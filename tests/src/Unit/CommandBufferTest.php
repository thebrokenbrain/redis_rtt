<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Redis\CommandBuffer;
use Drupal\redis\ClientFactory;

/**
 * @coversDefaultClass \Drupal\redis_rtt\Redis\CommandBuffer
 * @group redis_rtt
 */
class CommandBufferTest extends UnitTestCase {

  /**
   * The key ChainedFastBackend keeps the config bin's timestamp under.
   *
   * Outside the bin's own key space, exactly as the stock backend writes it.
   */
  protected const CONFIG_MARKER = 'p:last_write_timestamp_cache_config';

  /**
   * The same, for a second bin.
   */
  protected const DISCOVERY_MARKER = 'p:last_write_timestamp_cache_discovery';

  /**
   * The fake Redis client the buffer writes to.
   */
  protected FakeRedisClient $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['redis_rtt_defer_writes' => TRUE]);
    $this->client = new FakeRedisClient();
  }

  /**
   * Builds a buffer wired to the fake client.
   *
   * @param array<string, mixed> $settings
   *   Settings to install before construction.
   *
   * @return \Drupal\redis_rtt\Redis\CommandBuffer
   *   The buffer.
   */
  protected function buffer(array $settings = []): CommandBuffer {
    new Settings($settings + ['redis_rtt_defer_writes' => TRUE]);
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn($this->client);
    return new CommandBuffer($factory);
  }

  /**
   * The whole point: many writes, one network wait.
   *
   * @covers ::queueWrite
   * @covers ::flush
   */
  public function testWritesCollapseIntoOnePipeline(): void {
    $buffer = $this->buffer();

    foreach (range(1, 20) as $i) {
      $buffer->queueWrite("bin:key$i", ['cid' => "key$i", 'data' => 'x'], 300);
    }
    $this->assertSame(0, $this->client->roundTrips, 'Queueing must not touch the network.');

    $buffer->flush();

    $this->assertSame(1, $this->client->roundTrips, '20 writes must cost one round trip.');
    $this->assertCount(20, array_filter(
      array_keys($this->client->data),
      static fn (string $key): bool => str_starts_with($key, 'bin:key')
    ));
  }

  /**
   * Repeated writes to one key are sent once, with the last value.
   *
   * @covers ::queueWrite
   */
  public function testRepeatedWritesToTheSameKeyAreDeduplicated(): void {
    $buffer = $this->buffer();

    $buffer->queueWrite('bin:k', ['cid' => 'k', 'data' => 'first'], NULL);
    $buffer->queueWrite('bin:k', ['cid' => 'k', 'data' => 'second'], NULL);
    $buffer->queueWrite('bin:k', ['cid' => 'k', 'data' => 'third'], NULL);
    $buffer->flush();

    $this->assertSame('third', $this->client->data['bin:k']['data']);
    $this->assertSame(2, $buffer->getStats()['deduped']);
  }

  /**
   * A pending write can be read back without touching the network.
   *
   * @covers ::getPendingHash
   */
  public function testPendingWritesAreReadable(): void {
    $buffer = $this->buffer();
    $buffer->queueWrite('bin:k', ['cid' => 'k', 'data' => 'value'], NULL);

    $this->assertSame(['cid' => 'k', 'data' => 'value'], $buffer->getPendingHash('bin:k'));
    $this->assertNull($buffer->getPendingHash('bin:absent'));
    $this->assertSame(0, $this->client->roundTrips);
  }

  /**
   * A delete must win over a write that has not been sent yet.
   *
   * Without this, the delete would reach Redis first and the buffered write
   * would land afterwards, resurrecting an entry the caller deleted.
   *
   * @covers ::dropWrites
   */
  public function testDroppedWritesAreNeverSent(): void {
    $buffer = $this->buffer();

    $buffer->queueWrite('bin:keep', ['cid' => 'keep'], NULL);
    $buffer->queueWrite('bin:doomed', ['cid' => 'doomed'], NULL);
    $buffer->dropWrites(['bin:doomed']);
    $buffer->flush();

    $this->assertArrayHasKey('bin:keep', $this->client->data);
    $this->assertArrayNotHasKey('bin:doomed', $this->client->data);
  }

  /**
   * Wiping a bin must drop that bin's pending writes and nothing else.
   *
   * @covers ::dropWritesByPrefix
   */
  public function testDropWritesByPrefixOnlyAffectsThatBin(): void {
    $buffer = $this->buffer();

    $buffer->queueWrite('p:render:a', ['cid' => 'a'], NULL);
    $buffer->queueWrite('p:render:b', ['cid' => 'b'], NULL);
    $buffer->queueWrite('p:data:c', ['cid' => 'c'], NULL);
    $buffer->dropWritesByPrefix('p:render:');
    $buffer->flush();

    $this->assertSame(['p:data:c'], array_keys($this->client->data));
  }

  /**
   * Invalidating a pending write happens in place, with no round trip.
   *
   * @covers ::invalidatePending
   */
  public function testInvalidatingPendingWriteCostsNothing(): void {
    $buffer = $this->buffer();
    $buffer->queueWrite('bin:k', ['cid' => 'k', 'valid' => 1], NULL);

    $this->assertTrue($buffer->invalidatePending('bin:k'));
    $this->assertFalse($buffer->invalidatePending('bin:absent'), 'An unbuffered key must be reported back to the caller.');
    $this->assertSame(0, $this->client->roundTrips);

    $buffer->flush();
    $this->assertSame('0', $this->client->data['bin:k']['valid']);
  }

  /**
   * A marker must leave behind the data it describes, in the same pipeline.
   *
   * @covers ::queueMarker
   * @covers ::flush
   */
  public function testMarkersAreSentAfterTheWritesTheyDescribe(): void {
    $buffer = $this->buffer();

    $buffer->queueWrite('p:config:system.site', ['cid' => 'system.site'], NULL);
    $buffer->queueMarker(self::CONFIG_MARKER, 1.0, 'p:config:');
    $buffer->queueWrite('p:config:system.theme', ['cid' => 'system.theme'], NULL);
    $buffer->flush();

    $this->assertSame(['eval', 'eval', 'set'], $this->client->log, 'The marker must be sent after the writes it describes. The writes are eval, not hmset: each is applied only if it would not undo a newer one.');
    // Two trips, deliberately. A marker inside the data pipeline is stamped
    // before Redis has applied that pipeline, so it can claim a moment earlier
    // than its own data became readable - measured at 7.4 ms for 512 entries of
    // 8 KB. The second trip buys a timestamp that cannot precede visibility,
    // and it is spent in the shutdown function, after
    // fastcgi_finish_request(), where nobody is waiting for it.
    $this->assertSame(2, $this->client->roundTrips, 'The marker is sent after the pipeline it describes, not inside it.');
  }

  /**
   * A marker carries the moment it was sent, not the moment it was queued.
   *
   * Ordering alone is not enough. Another web node that reads this bin while
   * the write is still queued takes the old value and stamps its own fast
   * backend copy with the time it read - which is later than any timestamp
   * captured before the flush. ChainedFastBackend keeps a copy that is newer
   * than the marker, so that node would serve the pre-write value for as long
   * as nothing else touches the bin.
   *
   * @covers ::queueMarker
   * @covers ::flush
   */
  public function testMarkersAreStampedWhenTheyAreSent(): void {
    $buffer = $this->buffer();
    $buffer->queueWrite('p:config:system.site', ['cid' => 'system.site'], NULL);
    $buffer->queueMarker(self::CONFIG_MARKER, round(microtime(TRUE) + .001, 3), 'p:config:');

    // Stand in for the other node's read: everything queued is still invisible.
    $primed_at = round(microtime(TRUE), 3);
    $buffer->flush();

    $this->assertGreaterThan($primed_at, (float) $this->client->data[self::CONFIG_MARKER]);
  }

  /**
   * A write re-arms its own bin's marker, and only its own.
   *
   * Core rewrites the timestamp only once the clock has passed the one it
   * already published, which on Drupal 11.2 is a 50ms window. A second flush
   * can therefore carry writes that no fresh marker accompanies, and the buffer
   * has to republish the marker itself or those writes go out described by a
   * timestamp older than they are. Republishing a bin nobody wrote to would
   * cost the other nodes their fast backend copies for nothing.
   *
   * @covers ::queueWrite
   * @covers ::flush
   */
  public function testWritesRepublishOnlyTheirOwnBinsMarker(): void {
    $buffer = $this->buffer();
    $buffer->queueMarker(self::CONFIG_MARKER, 1.0, 'p:config:');
    $buffer->queueMarker(self::DISCOVERY_MARKER, 1.0, 'p:discovery:');
    $buffer->flush();

    // Both markers are up to date. Removing them makes anything the next flush
    // writes unmistakably a republication.
    unset($this->client->data[self::CONFIG_MARKER], $this->client->data[self::DISCOVERY_MARKER]);

    $buffer->queueWrite('p:config:system.site', ['cid' => 'system.site'], NULL);
    $buffer->flush();

    $this->assertArrayHasKey(self::CONFIG_MARKER, $this->client->data, 'The written bin needs a marker at least as new as the write.');
    $this->assertArrayNotHasKey(self::DISCOVERY_MARKER, $this->client->data, 'An untouched bin must be left alone.');
  }

  /**
   * A marker with nothing to accompany it still gets sent.
   *
   * A request that only deletes never queues a write, but ChainedFastBackend
   * still marks the bin as outdated, and that has to reach the other nodes.
   *
   * @covers ::flush
   */
  public function testMarkerWithNoWritesIsStillFlushed(): void {
    $buffer = $this->buffer();
    $buffer->queueMarker(self::CONFIG_MARKER, 1.0, 'p:config:');
    $buffer->flush();

    $this->assertArrayHasKey(self::CONFIG_MARKER, $this->client->data);
    $this->assertSame(1, $this->client->roundTrips);
  }

  /**
   * The buffer flushes itself rather than growing without bound.
   *
   * @covers ::queueWrite
   */
  public function testAnIntermediateFlushIsForcedAtTheLimit(): void {
    $buffer = $this->buffer(['redis_rtt_max_pending_writes' => 5]);

    foreach (range(1, 12) as $i) {
      $buffer->queueWrite("bin:k$i", ['cid' => "k$i"], NULL);
    }

    $this->assertSame(2, $this->client->roundTrips, '12 writes at a limit of 5 must have flushed twice.');
    $this->assertSame(2, $buffer->getStats()['pending'], 'The remainder stays queued for the end of the request.');
  }

  /**
   * Flushing nothing must not open a pipeline.
   *
   * @covers ::flush
   */
  public function testFlushingAnEmptyBufferIsFree(): void {
    $buffer = $this->buffer();
    $buffer->flush();
    $buffer->flush();
    $this->assertSame(0, $this->client->roundTrips);
  }

  /**
   * A failing flush must not take the request down.
   *
   * Cache writes are best-effort by definition: the data can be recomputed.
   *
   * @covers ::flush
   */
  public function testFailedFlushIsSwallowed(): void {
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willThrowException(new \RuntimeException('Redis is down'));
    $buffer = new CommandBuffer($factory);

    $buffer->queueWrite('bin:k', ['cid' => 'k'], NULL);
    $buffer->flush();

    $this->assertSame(0, $buffer->getStats()['pipelines']);
  }

  /**
   * When buffering is switched off, the backend must be told.
   *
   * @covers ::isEnabled
   */
  public function testBufferingCanBeDisabled(): void {
    $this->assertFalse($this->buffer(['redis_rtt_defer_writes' => FALSE])->isEnabled());
    $this->assertTrue($this->buffer(['redis_rtt_defer_writes' => TRUE])->isEnabled());
  }

  /**
   * Reads an entry's data back from the fake client.
   *
   * Through a method rather than inline, so that static analysis does not
   * report the assertion as always false: the mutation it cannot see happens
   * inside the fake client, when the flush applies the buffered write.
   *
   * @param string $key
   *   The Redis key.
   *
   * @return string|null
   *   The stored data, or NULL when the key is gone.
   */
  private function readBack(string $key): ?string {
    $entry = $this->client->data[$key] ?? NULL;
    return is_array($entry) ? ($entry['data'] ?? NULL) : NULL;
  }

  /**
   * A buffered write must not undo a newer write by another process.
   *
   * The buffer holds the entry as it was when ::set() was called. Replayed
   * unconditionally it overwrites whatever anyone else wrote to that key in the
   * meantime, and in a bin whose entries carry no cache tags and never expire -
   * cache_config - nothing would ever correct it: the database would hold the
   * new configuration and Redis the old one, for good.
   *
   * @covers ::flush
   */
  public function testBufferedWritesDoNotUndoNewerOnes(): void {
    $buffer = $this->buffer();
    $key = 'p:config:system.site';

    // This process captured the entry at t=1000.
    $buffer->queueWrite($key, ['cid' => 'system.site', 'created' => '1000.000', 'data' => 'OLD'], NULL);

    // Another process wrote a newer one straight to Redis meanwhile.
    $this->client->data[$key] = ['cid' => 'system.site', 'created' => '2000.000', 'data' => 'NEW'];

    $buffer->flush();

    $this->assertSame('NEW', $this->readBack($key), 'The newer write has to survive the flush.');
  }

  /**
   * A buffered write is still applied when nothing newer is there.
   *
   * The guard must not cost the module its whole purpose.
   *
   * @covers ::flush
   */
  public function testBufferedWritesStillLand(): void {
    $buffer = $this->buffer();
    $key = 'p:config:system.site';

    $this->client->data[$key] = ['cid' => 'system.site', 'created' => '1000.000', 'data' => 'OLD'];
    $buffer->queueWrite($key, ['cid' => 'system.site', 'created' => '2000.000', 'data' => 'NEW'], NULL);
    $buffer->flush();

    $this->assertSame('NEW', $this->readBack($key));
  }

}

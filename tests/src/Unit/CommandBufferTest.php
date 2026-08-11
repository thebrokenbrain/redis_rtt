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

}

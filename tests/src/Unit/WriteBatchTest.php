<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis\ClientFactory;
use Drupal\redis_rtt\Cache\BatchingRedisBackend;
use Drupal\redis_rtt\Redis\WriteBatch;

/**
 * Checks that batching writes saves waits without losing or reviving entries.
 *
 * The saving is easy to show and easy to get wrong. What the rest of these
 * tests are about is the second half: a queued write must never come back after
 * something else has decided it should be gone, and a read must never miss a
 * write this same request has already made.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Redis\WriteBatch
 * @group redis_rtt
 */
class WriteBatchTest extends UnitTestCase {

  /**
   * The key prefix every backend in this test shares.
   */
  protected const PREFIX = 'p';

  /**
   * The fake Redis every backend and batch talks to.
   */
  protected FakeRedisClient $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->client = new FakeRedisClient();

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000000);
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(FALSE);

    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    $container->set('database', $database);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a batch over the fake client.
   *
   * @param array<string, mixed> $settings
   *   Settings overriding the defaults.
   *
   * @return \Drupal\redis_rtt\Redis\WriteBatch
   *   The batch.
   */
  protected function batch(array $settings = []): WriteBatch {
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn($this->client);

    return new WriteBatch($factory, new Settings($settings));
  }

  /**
   * Builds a backend for a bin, optionally handing it a batch.
   *
   * @param string $bin
   *   The cache bin.
   * @param \Drupal\redis_rtt\Redis\WriteBatch|null $batch
   *   (optional) The batch to write through.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface|null $checksum
   *   (optional) The checksum provider.
   *
   * @return \Drupal\redis_rtt\Cache\BatchingRedisBackend
   *   The backend.
   */
  protected function backend(string $bin = 'render', ?WriteBatch $batch = NULL, ?CacheTagsChecksumInterface $checksum = NULL): BatchingRedisBackend {
    if ($checksum === NULL) {
      $checksum = $this->createMock(CacheTagsChecksumInterface::class);
      $checksum->method('isValid')->willReturn(TRUE);
    }
    $backend = new BatchingRedisBackend($bin, $this->client, $checksum, new PhpSerialize(), $batch);
    $backend->setPrefix(self::PREFIX);

    return $backend;
  }

  /**
   * Puts an entry straight into the fake Redis, as another process would.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   * @param string $data
   *   The stored payload.
   * @param float $created
   *   The creation stamp the entry carries.
   */
  protected function store(string $key, string $data, float $created): void {
    $entry = [
      'cid' => 'shared',
      'created' => (string) $created,
      'expire' => '-1',
      'tags' => '',
      'valid' => '1',
      'checksum' => '0',
      'data' => $data,
      'serialized' => '0',
    ];
    $this->client->data[$key] = $entry;
  }

  /**
   * Reads a stored field back out of the fake Redis.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   * @param string $field
   *   The hash field.
   *
   * @return string
   *   The stored value.
   */
  protected function stored(string $key, string $field): string {
    $entry = $this->client->data[$key];
    $this->assertIsArray($entry, "There should be a hash at $key.");

    return (string) $entry[$field];
  }

  /**
   * Two hundred and fifty writes cost three waits instead of two hundred fifty.
   *
   * This is the whole point of the class. The stock backend writes a render
   * entry per round trip, and building a heavy page cold produced 643 of them.
   *
   * @covers ::add
   * @covers ::send
   */
  public function testManyWritesTravelInFewPipelines(): void {
    $batch = $this->batch(['redis_rtt_max_batched_writes' => 100]);
    $backend = $this->backend('render', $batch);
    $this->client->resetCounters();

    foreach (range(1, 250) as $i) {
      $backend->set("cid-$i", $i);
    }
    // Two full batches have gone; the remaining fifty leave at the end of the
    // request.
    $this->assertSame(2, $this->client->roundTrips);
    $batch->send();

    $this->assertSame(3, $this->client->roundTrips, '250 writes, 3 waits.');
    $this->assertSame(250, $this->client->commands, 'Still 250 commands - they just travel together.');
    $this->assertCount(250, $this->client->data);
  }

  /**
   * Everything queued is really in Redis once the batch has been sent.
   *
   * @covers ::send
   */
  public function testQueuedWritesArriveIntact(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);

    $backend->set('answer', ['deep' => 'thought'], Cache::PERMANENT, ['node:1']);
    $batch->send();

    $cids = ['answer'];
    $found = $backend->getMultiple($cids);

    $this->assertArrayHasKey('answer', $found);
    $this->assertSame(['deep' => 'thought'], $found['answer']->data);
    $this->assertSame(['node:1'], $found['answer']->tags);
  }

  /**
   * A read of a key still in the batch hits, and costs no extra wait.
   *
   * Without this a request would miss on a key it had just written, which the
   * stock backend never does - and Drupal reads back what it writes constantly.
   *
   * @covers ::getPending
   */
  public function testReadsSeeTheirOwnQueuedWrite(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);
    $backend->set('fresh', 'value');
    $this->assertArrayNotHasKey('p:render:fresh', $this->client->data, 'The write should still be queued.');
    $this->client->resetCounters();

    $cids = ['fresh'];
    $found = $backend->getMultiple($cids);

    $this->assertArrayHasKey('fresh', $found);
    $this->assertSame('value', $found['fresh']->data);
    $this->assertSame([], $cids, 'A hit must be removed from the list of misses.');
  }

  /**
   * A read that is entirely served from the batch still resolves the marker.
   *
   * The "last delete all" timestamp decides whether an entry predates a flush,
   * so a queued entry has to be checked against it just like a stored one.
   *
   * @covers ::getPending
   */
  public function testQueuedEntriesAreCheckedAgainstTheDeleteAllMarker(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);

    // A flush happens, and only then is the entry written and read back.
    $backend->deleteAll();
    $backend->set('after', 'value');
    $cids = ['after'];
    $this->assertArrayHasKey('after', $backend->getMultiple($cids), 'An entry written after the flush survives it.');

    // An entry queued before a flush must not be readable after it.
    $backend = $this->backend('data', $batch);
    $backend->set('before', 'value');
    $backend->deleteAll();
    $backend->set('before', 'value');
    $stale = $batch->getPending('p:data:before');
    $this->assertNotNull($stale);
    // Force it to look older than the flush, as a slow request's write would.
    $batch->drop(['p:data:before']);
    $this->client->data['p:data:before'] = [
      'cid' => 'before',
      'created' => 1,
      'expire' => -1,
      'tags' => '',
      'valid' => 1,
      'checksum' => 0,
      'data' => 'value',
      'serialized' => 0,
    ];
    $cids = ['before'];
    $this->assertSame([], $backend->getMultiple($cids), 'An entry older than the flush must not be served.');
  }

  /**
   * Deleting a key discards whatever this request had queued for it.
   *
   * This is the failure that ended the previous, much broader, deferred write
   * design: the flush recreated a key that a delete had removed, and an absent
   * key is indistinguishable from one that never existed.
   *
   * @covers ::drop
   */
  public function testDeletingDiscardsTheQueuedWrite(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);

    $backend->set('doomed', 'value');
    $backend->delete('doomed');
    $batch->send();

    $this->assertArrayNotHasKey('p:render:doomed', $this->client->data, 'The flush must not bring back a deleted key.');
    $cids = ['doomed'];
    $this->assertSame([], $backend->getMultiple($cids));
  }

  /**
   * Deleting a key discards the queued write even inside a transaction.
   *
   * The stock backend defers the deletion itself to the commit. Dropping the
   * queued write anyway costs one cache miss if the transaction rolls back, and
   * avoids writing back an entry that was meant to be gone if it does not.
   *
   * @covers ::deleteMultiple
   */
  public function testDeletingInsideTransactionsAlsoDiscardsTheQueuedWrite(): void {
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(TRUE);
    $database->method('transactionManager')->willThrowException(new \RuntimeException('unused'));
    \Drupal::getContainer()->set('database', $database);

    $batch = $this->batch();
    $backend = $this->backend('render', $batch);
    $backend->set('doomed', 'value');

    try {
      $backend->delete('doomed');
    }
    catch (\RuntimeException) {
      // The stock backend registers a post-commit callback here; the mock
      // cannot, and the drop has already happened by then.
    }
    $batch->send();

    $this->assertArrayNotHasKey('p:render:doomed', $this->client->data);
  }

  /**
   * Flushing a bin discards its queued writes, and only its own.
   *
   * @covers ::dropByPrefix
   */
  public function testDeleteAllDiscardsThatBinsQueuedWrites(): void {
    $batch = $this->batch();
    $render = $this->backend('render', $batch);
    $data = $this->backend('data', $batch);

    $render->set('gone', 'value');
    $data->set('kept', 'value');
    $render->deleteAll();
    $batch->send();

    $this->assertArrayNotHasKey('p:render:gone', $this->client->data);
    $this->assertArrayHasKey('p:data:kept', $this->client->data, 'Another bin must not lose its writes.');
  }

  /**
   * A bin whose name merely starts with the flushed one keeps its writes.
   *
   * @covers ::dropByPrefix
   */
  public function testDeleteAllDoesNotReachBinsWithSimilarNames(): void {
    // 'render' is a prefix of 'render_inline', so a flush of the first must not
    // reach the second. Both are batched here to make the collision possible.
    $batch = $this->batch(['redis_rtt_batched_bins' => ['render', 'render_inline']]);
    $render = $this->backend('render', $batch);
    $inline = $this->backend('render_inline', $batch);

    $inline->set('kept', 'value');
    $render->deleteAll();
    $batch->send();

    $this->assertArrayHasKey('p:render_inline:kept', $this->client->data);
  }

  /**
   * Invalidating a queued entry is visible to this request immediately.
   *
   * @covers ::invalidatePending
   */
  public function testInvalidatingQueuedEntriesTakesEffectAtOnce(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);

    $backend->set('stale', 'value');
    $backend->invalidate('stale');

    $cids = ['stale'];
    $this->assertSame([], $backend->getMultiple($cids), 'An invalidated entry must not be served from the batch.');

    $cids = ['stale'];
    $allowed = $backend->getMultiple($cids, TRUE);
    $this->assertArrayHasKey('stale', $allowed, 'It is invalid, not gone.');

    // And it is still invalid once it reaches Redis.
    $batch->send();
    $this->assertSame('0', $this->stored('p:render:stale', 'valid'));
  }

  /**
   * A queued write never overwrites a newer one made by another process.
   *
   * @covers ::send
   */
  public function testQueuedWritesDoNotOvertakeNewerOnes(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);
    $backend->set('shared', 'mine');

    // Another process writes the same key while this one is still queued.
    $this->store('p:render:shared', 'theirs', microtime(TRUE) + 10);

    $batch->send();

    $this->assertSame('theirs', $this->stored('p:render:shared', 'data'), 'The newer write must survive the older one arriving late.');
  }

  /**
   * An older stored entry is replaced normally.
   *
   * @covers ::send
   */
  public function testQueuedWritesReplaceOlderStoredOnes(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);

    $this->store('p:render:shared', 'theirs', 1);
    $backend->set('shared', 'mine');
    $batch->send();

    $this->assertSame('mine', $this->stored('p:render:shared', 'data'));
  }

  /**
   * Writing the same key twice sends it once, with the later value.
   *
   * @covers ::add
   */
  public function testTheSameKeyWrittenTwiceIsSentOnce(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);
    $this->client->resetCounters();

    $backend->set('key', 'first');
    $backend->set('key', 'second');
    $batch->send();

    $this->assertSame(1, $this->client->commands, 'Only the surviving value should be sent.');
    $this->assertSame('second', $this->client->data['p:render:key']['data']);
    $this->assertSame(1, $batch->getStats()['superseded']);
  }

  /**
   * The bins that cannot recover from a revived entry write immediately.
   *
   * The entity bin is the one that destroyed a saved node under the previous
   * design: core deletes an entity's cache entry on every save, and the entry's
   * tags are not the ones a content save invalidates, so nothing would have
   * caught a resurrected one on read.
   *
   * @covers ::handles
   */
  public function testTheRiskyBinsAreNotBatched(): void {
    $batch = $this->batch();

    foreach (['entity', 'default', 'config', 'bootstrap', 'discovery', 'container'] as $bin) {
      $backend = $this->backend($bin, $batch);
      $backend->set('now', 'value');
      $this->assertArrayHasKey("p:$bin:now", $this->client->data, "The $bin bin must write immediately.");
    }
    $this->assertSame(0, $batch->getStats()['writes'], 'None of those writes should have been queued.');
  }

  /**
   * A bin nobody has checked is not batched either.
   *
   * The list says which bins have been shown to survive a revived entry, not
   * which ones have been ruled out. An exclusion list would hand the benefit of
   * the doubt to every bin a contributed module declares, which is the shape of
   * reasoning that produced the data loss in the first place.
   *
   * @covers ::handles
   */
  public function testUnknownBinsAreNotBatched(): void {
    $batch = $this->batch();

    foreach (['page', 'toolbar', 'access_policy', 'some_contrib_bin'] as $bin) {
      $this->backend($bin, $batch)->set('now', 'value');
      $this->assertArrayHasKey("p:$bin:now", $this->client->data, "The $bin bin was never checked, so it must write immediately.");
    }
    $this->assertSame(0, $batch->getStats()['writes']);
  }

  /**
   * The batched bins can be named in settings.
   *
   * @covers ::handles
   */
  public function testTheBatchedBinsAreConfigurable(): void {
    $batch = $this->batch(['redis_rtt_batched_bins' => ['entity']]);

    $this->backend('render', $batch)->set('now', 'value');
    $this->assertArrayHasKey('p:render:now', $this->client->data, 'A bin left out of the list must write immediately.');

    $this->backend('entity', $batch)->set('later', 'value');
    $this->assertArrayNotHasKey('p:entity:later', $this->client->data, 'And one named in it must be batched.');
  }

  /**
   * Turning batching off restores the stock write path exactly.
   *
   * @covers ::handles
   */
  public function testBatchingCanBeTurnedOff(): void {
    $batch = $this->batch(['redis_rtt_batch_writes' => FALSE]);
    $backend = $this->backend('render', $batch);
    $this->client->resetCounters();

    $backend->set('now', 'value');

    $this->assertArrayHasKey('p:render:now', $this->client->data);
    $this->assertSame(1, $this->client->roundTrips);
  }

  /**
   * One batch carries the writes of every bin that shares it.
   *
   * @covers ::send
   */
  public function testOneBatchServesEveryBin(): void {
    $batch = $this->batch();
    $render = $this->backend('render', $batch);
    $data = $this->backend('data', $batch);
    $menu = $this->backend('menu', $batch);
    $this->client->resetCounters();

    $render->set('a', 1);
    $data->set('b', 2);
    $menu->set('c', 3);
    $batch->send();

    $this->assertSame(1, $this->client->roundTrips, 'Three bins, one wait.');
    $this->assertCount(3, $this->client->data);
  }

  /**
   * Sending an empty batch does not touch the network.
   *
   * @covers ::send
   */
  public function testSendingNothingCostsNothing(): void {
    $batch = $this->batch();
    $this->client->resetCounters();

    $batch->send();

    $this->assertSame(0, $this->client->roundTrips);
  }

  /**
   * A write already expired is deleted, not queued.
   *
   * @covers ::setMultiple
   */
  public function testExpiredWritesAreNotQueued(): void {
    $batch = $this->batch();
    $backend = $this->backend('render', $batch);

    $backend->set('stale', 'value', 1);
    $batch->send();

    $this->assertArrayNotHasKey('p:render:stale', $this->client->data);
    $this->assertSame(0, $batch->getStats()['writes']);
  }

  /**
   * A send that fails during the request fails the request, as stock does.
   *
   * A full instance under a noeviction policy, or a replica promoted to
   * read-only, answers reads and refuses writes. The stock backend returns a
   * 500 for that, and batching must not quietly turn it into a page served with
   * nothing cached.
   *
   * @covers ::send
   */
  public function testFailingSendsRaise(): void {
    $batch = $this->brokenBatch();
    $backend = $this->backendFor($batch);
    $backend->set('lost', 'value');

    $this->expectException(\RuntimeException::class);
    $batch->send();
  }

  /**
   * The end-of-request send reports a failure instead of raising it.
   *
   * By then the response has gone out, so there is no request left to fail.
   *
   * @covers ::sendQuietly
   */
  public function testTheEndOfRequestSendDoesNotRaise(): void {
    $batch = $this->brokenBatch();
    $backend = $this->backendFor($batch);
    $backend->set('lost', 'value');

    $batch->sendQuietly();

    $this->assertSame(0, $batch->getStats()['pending'], 'The failed writes must not pile up for the next send.');
  }

  /**
   * A failed send drops its writes rather than holding them for the next one.
   *
   * Re-queueing would risk sending an entry that a delete has since made wrong,
   * which is the failure this whole design exists to avoid.
   *
   * @covers ::send
   */
  public function testFailedWritesAreDroppedNotRetried(): void {
    $batch = $this->brokenBatch();
    $backend = $this->backendFor($batch);
    $backend->set('lost', 'value');

    $batch->sendQuietly();
    $this->assertSame(0, $batch->getStats()['pending']);

    // A second send has nothing left to try.
    $batch->sendQuietly();
    $this->assertSame(0, $batch->getStats()['pending']);
  }

  /**
   * Builds a batch whose client always fails.
   *
   * @return \Drupal\redis_rtt\Redis\WriteBatch
   *   The batch.
   */
  protected function brokenBatch(): WriteBatch {
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willThrowException(new \RuntimeException('Redis is gone'));

    return new WriteBatch($factory, new Settings([]));
  }

  /**
   * Builds a render backend over a given batch.
   *
   * @param \Drupal\redis_rtt\Redis\WriteBatch $batch
   *   The batch to write through.
   *
   * @return \Drupal\redis_rtt\Cache\BatchingRedisBackend
   *   The backend.
   */
  protected function backendFor(WriteBatch $batch): BatchingRedisBackend {
    $backend = new BatchingRedisBackend('render', $this->client, $this->createMock(CacheTagsChecksumInterface::class), new PhpSerialize(), $batch);
    $backend->setPrefix(self::PREFIX);

    return $backend;
  }

  /**
   * The counters describe what actually happened.
   *
   * @covers ::getStats
   */
  public function testTheStatsReportWhatTheBatchDid(): void {
    $batch = $this->batch(['redis_rtt_max_batched_writes' => 10]);
    $backend = $this->backend('render', $batch);

    foreach (range(1, 25) as $i) {
      $backend->set("cid-$i", $i);
    }

    $this->assertSame(
      ['batches' => 2, 'writes' => 25, 'pending' => 5, 'superseded' => 0],
      $batch->getStats(),
    );
  }

}

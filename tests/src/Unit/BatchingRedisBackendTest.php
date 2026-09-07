<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\BatchingRedisBackend;

/**
 * Checks that the backend spends fewer network waits than the stock one.
 *
 * The counter that matters throughout is round trips, not commands. Every
 * optimisation here deliberately sends the same number of commands or more,
 * and the whole point is that they travel together: on a cache a millisecond
 * away, what a request pays for is the waiting, not the work.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Cache\BatchingRedisBackend
 * @group redis_rtt
 */
class BatchingRedisBackendTest extends UnitTestCase {

  /**
   * The key prefix every backend in this test shares.
   */
  protected const PREFIX = 'p';

  /**
   * The fake Redis the backend talks to.
   */
  protected FakeRedisClient $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->client = new FakeRedisClient();

    // \Drupal\redis\Cache\RedisBackend::getExpiration() asks for the request
    // time when an entry carries an absolute expiry.
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000000);
    // And ::deleteMultiple() asks whether a database transaction is open, to
    // decide whether the delete has to wait for the commit.
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(FALSE);

    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    $container->set('database', $database);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the backend for a bin.
   *
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface|null $checksum
   *   (optional) The checksum provider to use.
   *
   * @return \Drupal\redis_rtt\Cache\BatchingRedisBackend
   *   The backend.
   */
  protected function backend(?CacheTagsChecksumInterface $checksum = NULL): BatchingRedisBackend {
    if ($checksum === NULL) {
      $checksum = $this->createMock(CacheTagsChecksumInterface::class);
      $checksum->method('isValid')->willReturn(TRUE);
    }
    $backend = new BatchingRedisBackend('render', $this->client, $checksum, new PhpSerialize());
    $backend->setPrefix(self::PREFIX);

    return $backend;
  }

  /**
   * Reading a bin for the first time costs one round trip, marker included.
   *
   * The stock backend resolves the "last delete all" marker lazily from
   * ::expandEntry(), which is a second wait on top of the read that needed it -
   * once per bin per request.
   *
   * @covers ::getMultiple
   */
  public function testTheDeleteAllMarkerRidesInTheReadPipeline(): void {
    $backend = $this->backend();
    $backend->setMultiple([
      'one' => ['data' => 1],
      'two' => ['data' => 2],
      'three' => ['data' => 3],
    ]);
    $this->client->resetCounters();

    $cids = ['one', 'two', 'three'];
    $found = $backend->getMultiple($cids);

    $this->assertCount(3, $found);
    $this->assertSame(1, $this->client->roundTrips, 'The three reads and the marker must travel together.');
  }

  /**
   * The marker is resolved once, not on every read of the bin.
   *
   * @covers ::getMultiple
   */
  public function testTheMarkerIsResolvedOncePerBin(): void {
    $backend = $this->backend();
    $backend->setMultiple(['one' => ['data' => 1], 'two' => ['data' => 2]]);

    $cids = ['one'];
    $backend->getMultiple($cids);
    $this->client->resetCounters();

    $cids = ['two'];
    $backend->getMultiple($cids);

    $this->assertSame(1, $this->client->roundTrips);
    $this->assertNotContains('get', $this->client->log, 'The marker was already resolved; asking again is a wasted wait.');
  }

  /**
   * Invalidating many entries costs one round trip, not two per entry.
   *
   * Upstream issues a sequential HGET followed by a sequential HSET for every
   * cache ID. CacheCollector invalidates its entry on every ::set(), so this is
   * a hot path.
   *
   * @covers ::invalidateMultiple
   */
  public function testInvalidatingManyEntriesCostsOneRoundTrip(): void {
    $backend = $this->backend();
    $items = [];
    foreach (range(1, 10) as $i) {
      $items["cid-$i"] = ['data' => $i];
    }
    $backend->setMultiple($items);
    $this->client->resetCounters();

    $backend->invalidateMultiple(array_keys($items));

    $this->assertSame(1, $this->client->roundTrips, 'Ten invalidations, one wait.');
    $this->assertSame(10, $this->client->commands, 'Still ten commands - they just travel together.');

    // And they really were invalidated.
    $cids = array_keys($items);
    $this->assertSame([], $backend->getMultiple($cids), 'Every entry should now be invalid.');
  }

  /**
   * Invalidating an entry that is not there must not create one.
   *
   * The invalidation script guards its HSET on the entry already existing and
   * still being valid. Without that guard the HSET creates the hash it was
   * pointed at - permanent, never expiring, holding nothing but valid=0 - and
   * since cache tag invalidation walks whatever cache IDs it is handed, a bin
   * would accumulate one such key per miss, for ever.
   *
   * This asserts on the guard rather than on the round trip, because the round
   * trip is the same either way. The stand-in reads the guard out of the script
   * text, so deleting it from ::INVALIDATE_LUA fails this test instead of
   * quietly passing against a fake that kept the behaviour on its own account.
   *
   * @covers ::invalidateMultiple
   */
  public function testInvalidatingMissingEntriesCreatesNothing(): void {
    $backend = $this->backend();
    $backend->set('presente', 'v');
    $before = array_keys($this->client->data);

    $backend->invalidateMultiple(['ausente-1', 'ausente-2']);

    $this->assertSame(
      $before,
      array_keys($this->client->data),
      'Invalidating a missing cache ID must not add a key to the bin.',
    );
  }

  /**
   * Invalidating nothing touches the network at all.
   *
   * @covers ::invalidateMultiple
   */
  public function testInvalidatingNothingCostsNothing(): void {
    $backend = $this->backend();
    $this->client->resetCounters();

    $backend->invalidateMultiple([]);

    $this->assertSame(0, $this->client->roundTrips);
  }

  /**
   * An already-expired item is deleted under the key it actually has.
   *
   * The stock method hands ::deleteMultiple() a key that is already prefixed,
   * and it gets prefixed a second time - so the delete lands on a key that
   * cannot exist and the expired entry stays in Redis.
   *
   * @covers ::setMultiple
   */
  public function testExpiredItemsAreDeletedUnderTheirRealKey(): void {
    $backend = $this->backend();
    $backend->setMultiple(['stale' => ['data' => 'old']]);
    $this->assertArrayHasKey('p:render:stale', $this->client->data);

    // Writing it again, already expired, has to remove it.
    $backend->setMultiple(['stale' => ['data' => 'old', 'expire' => 1]]);

    $this->assertArrayNotHasKey('p:render:stale', $this->client->data, 'The expired entry must be gone, not left behind under a key nobody reads.');
    $this->assertArrayNotHasKey('p:render:p:render:stale', $this->client->data, 'And certainly not double-prefixed.');
  }

  /**
   * Entries that are not expired are still written.
   *
   * @covers ::setMultiple
   */
  public function testLiveItemsAreWrittenAlongsideExpiredOnes(): void {
    $backend = $this->backend();
    $backend->setMultiple(['gone' => ['data' => 'x', 'expire' => 1], 'kept' => ['data' => 'y']]);

    $this->assertArrayNotHasKey('p:render:gone', $this->client->data);
    $this->assertArrayHasKey('p:render:kept', $this->client->data);
  }

  /**
   * Every tag a read returns is offered for preloading before validation.
   *
   * That is what lets the checksum provider resolve them in one MGET instead of
   * one lookup per entry, and it only works if it hears about all of them
   * before the first entry is validated.
   *
   * @covers ::getMultiple
   */
  public function testTagsAreOfferedForPreloadBeforeAnyEntryIsValidated(): void {
    $checksum = new RecordingChecksumProvider();
    $backend = $this->backend($checksum);
    $backend->setMultiple([
      'a' => ['data' => 1, 'tags' => ['node:1', 'node:2']],
      'b' => ['data' => 2, 'tags' => ['node:3']],
    ]);

    $cids = ['a', 'b'];
    $backend->getMultiple($cids);

    $this->assertSame(
      ['node:1', 'node:2', 'node:3'],
      $checksum->preloaded,
      'All three tags should have been offered in one go.',
    );
    $this->assertSame('preload', $checksum->firstCall, 'Preloading has to happen before the first validation, or it saves nothing.');
  }

  /**
   * Writes reach Redis during the call that made them.
   *
   * @covers ::setMultiple
   */
  public function testWritesLandImmediately(): void {
    $backend = $this->backend();

    $backend->set('now', 'value', Cache::PERMANENT, ['node:1']);

    $this->assertArrayHasKey('p:render:now', $this->client->data, 'A write must be readable by another process as soon as it returns.');
  }

}

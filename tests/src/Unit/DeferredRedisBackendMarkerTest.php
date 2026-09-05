<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\ChainedFastBackend;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\DeferredRedisBackend;
use Drupal\redis_rtt\Redis\CommandBuffer;
use Drupal\redis\ClientFactory;

/**
 * Checks the chained-fast timestamp against the writes it is supposed to cover.
 *
 * This is the default path, not an opt-in one: setting
 * $settings['cache']['default'] = 'cache.backend.redis_rtt' hands the deferred
 * backend to core's own ChainedFastBackendFactory, which is what builds
 * cache.bootstrap, cache.config and cache.discovery. Core writes the timestamp
 * through ::set() straight after handing the data to the consistent backend,
 * and the stock Redis backend answers that with an immediate SET.
 *
 * The failure that motivates these tests is silent and permanent. Node A saves
 * config; the entry is queued and the timestamp is published at once. Node B
 * reads the bin during that window, throws away its APCu copy because it
 * predates the timestamp, re-primes it from a Redis that still holds the old
 * value, and stamps the re-primed copy later than the timestamp. Node A then
 * flushes. Nothing moves the timestamp again, so node B serves pre-save config
 * as a fresh APCu hit - cache tag invalidation cannot reach it and a cache
 * rebuild on node A does not either.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Cache\DeferredRedisBackend
 * @group redis_rtt
 */
class DeferredRedisBackendMarkerTest extends UnitTestCase {

  /**
   * The key prefix every backend in this test shares.
   */
  protected const PREFIX = 'p';

  /**
   * The key the config bin's timestamp lives under.
   */
  protected const MARKER = 'p:last_write_timestamp_cache_config';

  /**
   * The key the one cache entry in these tests lives under.
   */
  protected const ENTRY = 'p:config:system.site';

  /**
   * The cache tag every buffered entry in these tests carries.
   *
   * Not decoration. A permanent entry with no cache tag is written
   * synchronously rather than buffered, because nothing could ever correct it
   * if a concurrent delete were undone - so an entry without one would never
   * reach the buffer these tests are about. Every bin core builds on
   * ChainedFastBackend is permanent, which is why the tag is what makes the
   * difference here.
   *
   * @see \Drupal\redis_rtt\Cache\DeferredRedisBackend::setMultiple()
   */
  protected const TAGS = ['config:system.site'];

  /**
   * The fake Redis both the backend and the buffer talk to.
   */
  protected FakeRedisClient $client;

  /**
   * The shared write buffer, flushed by hand instead of at shutdown.
   */
  protected CommandBuffer $buffer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['redis_rtt_defer_writes' => TRUE]);
    $this->client = new FakeRedisClient();
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn($this->client);
    $this->buffer = new CommandBuffer($factory);
  }

  /**
   * Builds the bin core assembles for a chained-fast bin.
   *
   * Core's ChainedFastBackend deliberately, not the module's coalescing
   * subclass: the defect is on the path a site gets without opting into
   * anything.
   *
   * @return \Drupal\Core\Cache\ChainedFastBackend
   *   The config bin, over a deferred Redis backend.
   */
  protected function configBin(): ChainedFastBackend {
    $consistent = new DeferredRedisBackend(
      'config',
      $this->client,
      $this->createMock(CacheTagsChecksumInterface::class),
      new PhpSerialize(),
      $this->buffer,
    );
    $consistent->setPrefix(self::PREFIX);

    return new ChainedFastBackend($consistent, new CountingMemoryBackend(), 'config');
  }

  /**
   * A second chained-fast bin, sharing the buffer with the first.
   *
   * Two bins is the minimum needed to tell "this marker is waiting for its own
   * data" apart from "markers are flushed together".
   *
   * @return \Drupal\Core\Cache\ChainedFastBackend
   *   The discovery bin.
   */
  protected function discoveryBin(): ChainedFastBackend {
    $consistent = new DeferredRedisBackend(
      'discovery',
      $this->client,
      $this->createMock(CacheTagsChecksumInterface::class),
      new PhpSerialize(),
      $this->buffer,
    );
    $consistent->setPrefix(self::PREFIX);

    return new ChainedFastBackend($consistent, new CountingMemoryBackend(), 'discovery');
  }

  /**
   * Nothing may be visible in Redis while the write is still queued.
   *
   * @covers ::set
   */
  public function testTheMarkerIsNotPublishedAheadOfTheData(): void {
    $this->configBin()->set('system.site', 'new value', Cache::PERMANENT, self::TAGS);

    $this->assertArrayNotHasKey(self::MARKER, $this->client->data, 'A timestamp announcing a write nobody can read yet is worse than no timestamp at all.');
    $this->assertArrayNotHasKey(self::ENTRY, $this->client->data, 'The entry is buffered, so this only holds while the marker is buffered too.');
  }

  /**
   * A delete publishes its marker at once; there is nothing to wait for.
   *
   * Core writes the same marker from ::delete(), ::invalidate() and
   * ::deleteAll(), and those reach Redis synchronously. Holding their marker
   * back would announce nothing and withhold an invalidation: nothing flushes a
   * marker on its own, so in a drush or queue process every other web node
   * would keep serving what was just cleared for the rest of that process.
   *
   * @covers ::set
   */
  public function testDeletePublishesItsMarkerImmediately(): void {
    $this->configBin()->delete('system.site');

    $this->assertArrayHasKey(self::MARKER, $this->client->data, 'The invalidation signal has to leave with the delete it describes.');
  }

  /**
   * Clearing a whole bin publishes its marker at once, for the same reason.
   *
   * @covers ::set
   */
  public function testClearingTheBinPublishesItsMarkerImmediately(): void {
    $this->configBin()->deleteAll();

    $this->assertArrayHasKey(self::MARKER, $this->client->data);
  }

  /**
   * A delete takes the writes it would be stranded behind out with it.
   *
   * Neither of the two easy answers works here. Publishing the marker on its
   * own would announce the queued write before Redis holds it - the defect the
   * rest of this class is about. Withholding it is the same defect with a
   * longer fuse: the delete is already applied, nothing flushes a marker by
   * itself, and every other web node would go on serving what was just deleted
   * until this process reached its pending-write limit or exited. For a request
   * that is milliseconds; for cron, a queue worker or a config import it is
   * minutes. Sending the data first and the marker behind it, in one pipeline,
   * is what keeps both promises.
   *
   * @covers ::set
   */
  public function testDeletesTakeBufferedWritesWithThem(): void {
    $bin = $this->configBin();
    $bin->set('system.site', 'new value', Cache::PERMANENT, self::TAGS);
    $this->client->resetCounters();

    $bin->delete('other.thing');

    $this->assertArrayHasKey(self::ENTRY, $this->client->data, 'The buffered write has to be sent, not abandoned.');
    $this->assertArrayHasKey(self::MARKER, $this->client->data, 'A delete no other node is told about is a delete that did not happen for them.');

    $wrote_entry = array_search('eval', $this->client->log, TRUE);
    $wrote_marker = array_search('set', $this->client->log, TRUE);
    $this->assertNotFalse($wrote_entry, 'The buffered entry should have been written.');
    $this->assertNotFalse($wrote_marker, 'The marker should have been written.');
    $this->assertLessThan($wrote_marker, $wrote_entry, 'The marker must still leave behind the data it announces.');
  }

  /**
   * A delete in one bin must not publish another bin's waiting marker.
   *
   * The immediate publication is per bin. Flushing every dirty marker whenever
   * any of them has nothing to wait for would drag a marker out ahead of the
   * data it describes - the original defect, reintroduced through the door
   * opened to fix a different one.
   *
   * @covers ::set
   */
  public function testDeletingOneBinLeavesAnotherBinsWaitingMarkerAlone(): void {
    // Config has data in the buffer, so its marker is waiting for it.
    $this->configBin()->set('system.site', 'new value', Cache::PERMANENT, self::TAGS);
    // Discovery is cleared, so its marker has nothing to wait for.
    $this->discoveryBin()->deleteAll();

    $this->assertArrayHasKey(
      'p:last_write_timestamp_cache_discovery',
      $this->client->data,
      'The cleared bin still has to announce itself.',
    );
    $this->assertArrayNotHasKey(
      self::MARKER,
      $this->client->data,
      'The other bin has buffered data this marker would announce too early.',
    );
  }

  /**
   * When it lands, the marker covers the entry that landed with it.
   *
   * @covers ::set
   */
  public function testTheMarkerLandsBehindTheDataAndOutlivesTheBufferWindow(): void {
    $this->configBin()->set('system.site', 'new value', Cache::PERMANENT, self::TAGS);

    // The window another node reads in: it would take the old value now and
    // stamp its own copy with this moment.
    $primed_at = round(microtime(TRUE), 3);
    $this->client->resetCounters();
    $this->buffer->flush();

    $this->assertSame(['eval', 'set'], $this->client->log, 'The entry must be written before the marker.');
    // The marker follows the data pipeline rather than riding in it, so that
    // its timestamp is taken once Redis has applied the writes. See
    // CommandBufferTest::testMarkersAreSentAfterTheWritesTheyDescribe().
    $this->assertSame(2, $this->client->roundTrips);

    $marker = (float) $this->client->data[self::MARKER];
    $this->assertGreaterThanOrEqual((float) $this->client->data[self::ENTRY]['created'], $marker, 'The marker must not predate the entry it announces.');
    $this->assertGreaterThan($primed_at, $marker, 'It must also outdate anything another node cached during the buffer window, or that node keeps serving it.');
  }

  /**
   * An unbuffered bin keeps the stock behaviour exactly.
   *
   * With nothing deferred there is nothing to order against, and the timestamp
   * should reach the other nodes as early as core sends it.
   *
   * @covers ::set
   */
  public function testAnUnbufferedBinPublishesTheMarkerImmediately(): void {
    new Settings(['redis_rtt_defer_writes' => FALSE]);
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn($this->client);
    $this->buffer = new CommandBuffer($factory);

    $this->configBin()->set('system.site', 'new value', Cache::PERMANENT, self::TAGS);

    $this->assertArrayHasKey(self::ENTRY, $this->client->data);
    $this->assertArrayHasKey(self::MARKER, $this->client->data);
  }

  /**
   * A buffered write announces its bin even when core hands over no marker.
   *
   * Core 11.2's ChainedFastBackend::markAsOutdated() stamps 50 ms ahead and
   * then skips the write while that stamp is still in the future, so ::set() is
   * never called with the marker and the buffer is handed nothing to re-arm.
   * The write is still deferred, so without this the entry lands in Redis with
   * the marker untouched, and every node that primed a copy in between keeps
   * serving it - permanently, since nothing moves the marker afterwards.
   *
   * @covers ::setMultiple
   */
  public function testWritesAnnounceTheirBinWhenCoreSkipsTheMarker(): void {
    // Redis already holds a marker for this bin, which is what makes it a
    // chained-fast bin as far as the buffer can tell.
    $this->client->data[self::MARKER] = '1000';

    // Only the entry is queued: no marker reaches the backend, exactly as on
    // 11.2 inside the 50 ms window.
    $consistent = new DeferredRedisBackend(
      'config',
      $this->client,
      $this->createMock(CacheTagsChecksumInterface::class),
      new PhpSerialize(),
      $this->buffer,
    );
    $consistent->setPrefix(self::PREFIX);
    $consistent->setMultiple(['system.site' => ['data' => 'new value', 'tags' => self::TAGS]]);

    $this->buffer->flush();

    $this->assertGreaterThan(
      1000.0,
      (float) $this->client->data[self::MARKER],
      'The bin has to be announced, or other nodes keep the copy they primed before the write.',
    );
  }

}

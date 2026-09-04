<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Serialization\PhpSerialize;
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
    $this->configBin()->set('system.site', 'new value');

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
   * A delete after a queued write still leaves the marker behind the data.
   *
   * The marker waits for pending data, and only for that. Publishing it early
   * here would announce the queued write before Redis holds it.
   *
   * @covers ::set
   */
  public function testMarkerStillWaitsForDataQueuedBeforeTheDelete(): void {
    $bin = $this->configBin();
    $bin->set('system.site', 'new value');
    $bin->delete('other.thing');

    $this->assertArrayNotHasKey(self::MARKER, $this->client->data, 'There is buffered data this marker would announce too early.');
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
    // config has data in the buffer, so its marker is waiting for it.
    $this->configBin()->set('system.site', 'new value');
    // discovery is cleared, so its marker has nothing to wait for.
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
    $this->configBin()->set('system.site', 'new value');

    // The window another node reads in: it would take the old value now and
    // stamp its own copy with this moment.
    $primed_at = round(microtime(TRUE), 3);
    $this->client->resetCounters();
    $this->buffer->flush();

    $this->assertSame(['hmset', 'set'], $this->client->log, 'The entry must be written before the marker, in one pipeline.');
    $this->assertSame(1, $this->client->roundTrips);

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

    $this->configBin()->set('system.site', 'new value');

    $this->assertArrayHasKey(self::ENTRY, $this->client->data);
    $this->assertArrayHasKey(self::MARKER, $this->client->data);
  }

}

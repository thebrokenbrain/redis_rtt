<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\PreloadingRedisCacheTagsChecksum;
use Drupal\redis_rtt\Cache\ShortcutStoreInterface;
use Drupal\redis\Cache\RedisCacheTagsChecksum;
use Drupal\redis\ClientFactory;

/**
 * @coversDefaultClass \Drupal\redis_rtt\Cache\PreloadingRedisCacheTagsChecksum
 * @group redis_rtt
 */
class PreloadingRedisCacheTagsChecksumTest extends UnitTestCase {

  /**
   * The fake Redis client.
   */
  protected FakeRedisClient $client;

  /**
   * The learned-set store, shared across simulated requests.
   */
  protected ShortcutStoreInterface $store;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['redis_rtt_tag_warmset_min_hits' => 2]);
    $this->client = new FakeRedisClient();
    $this->store = new ArrayShortcutStore();
  }

  /**
   * Builds a checksum provider, as a new request would.
   *
   * @return \Drupal\redis_rtt\Cache\PreloadingRedisCacheTagsChecksum
   *   The provider.
   */
  protected function provider(): PreloadingRedisCacheTagsChecksum {
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn($this->client);
    return new PreloadingRedisCacheTagsChecksum($factory, $this->store);
  }

  /**
   * Builds the stock provider, for comparison.
   *
   * @return \Drupal\redis\Cache\RedisCacheTagsChecksum
   *   The provider.
   */
  protected function stockProvider(): RedisCacheTagsChecksum {
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn($this->client);
    return new RedisCacheTagsChecksum($factory);
  }

  /**
   * Registered tags ride along on the first lookup that has to happen anyway.
   *
   * @covers ::registerCacheTagsForPreload
   * @covers ::getTagInvalidationCounts
   */
  public function testRegisteredTagsAreResolvedInOneRoundTrip(): void {
    $provider = $this->provider();
    $tags = array_map(static fn (int $i): string => "node:$i", range(1, 12));

    $provider->registerCacheTagsForPreload($tags);
    $this->client->resetCounters();

    // Validate the entries one by one, as ::expandEntry() does.
    foreach ($tags as $tag) {
      $provider->isValid(0, [$tag]);
    }

    $this->assertSame(1, $this->client->roundTrips, '12 tags must resolve in one round trip.');
  }

  /**
   * The stock provider is the baseline this improves on.
   *
   * Guards against the optimisation silently becoming a no-op.
   */
  public function testTheStockProviderCostsOneRoundTripPerTag(): void {
    $stock = $this->stockProvider();
    $this->client->resetCounters();

    foreach (range(1, 12) as $i) {
      $stock->isValid(0, ["node:$i"]);
    }

    $this->assertSame(12, $this->client->roundTrips, 'The baseline is one round trip per tag.');
  }

  /**
   * Batching must not change a single checksum.
   *
   * @covers ::getTagInvalidationCounts
   */
  public function testBatchingDoesNotChangeChecksums(): void {
    $this->client->data['drupal:cachetags:node:7'] = '4';
    $this->client->data['drupal:cachetags:config:system.site'] = '2';
    $tags = ['node:7', 'config:system.site', 'never:invalidated'];

    $batched = $this->provider();
    $batched->registerCacheTagsForPreload(['node:1', 'node:2', 'node:7']);

    $this->assertSame(
      $this->stockProvider()->getCurrentChecksum($tags),
      $batched->getCurrentChecksum($tags),
    );
  }

  /**
   * The tags a request looks at are preloaded on the next one.
   *
   * @covers ::learn
   * @covers ::warmSet
   */
  public function testTheLearnedSetIsUsedByTheNextRequest(): void {
    $tags = ['config:system.site', 'routes', 'entity_types', 'library_info'];

    // Two requests to clear the minimum-hits threshold.
    for ($request = 0; $request < 2; $request++) {
      $provider = $this->provider();
      foreach ($tags as $tag) {
        $provider->isValid(0, [$tag]);
      }
      $provider->learn();
    }

    // Third request: the same tags, one at a time again.
    $provider = $this->provider();
    $this->client->resetCounters();
    foreach ($tags as $tag) {
      $provider->isValid(0, [$tag]);
    }

    $this->assertSame(1, $this->client->roundTrips, 'The learned set must resolve every tag in the first lookup.');
  }

  /**
   * Ranking is by frequency, so one-off content tags do not crowd it out.
   *
   * @covers ::learn
   * @covers ::warmSet
   */
  public function testRareTagsAreNotPreloaded(): void {
    // Three requests: a stable tag every time, a different node tag each time.
    foreach (range(1, 3) as $i) {
      $provider = $this->provider();
      $provider->isValid(0, ['config:system.site']);
      $provider->isValid(0, ["node:$i"]);
      $provider->learn();
    }

    $stats = $this->store->get('tagset');
    $this->assertSame(3, $stats['config:system.site']);
    $this->assertSame(1, $stats['node:1']);

    // The stable tag alone is preloaded, so a fourth request asking only for a
    // brand new node tag fetches both in one go and nothing else.
    $provider = $this->provider();
    $this->client->resetCounters();
    $provider->isValid(0, ['node:99']);

    $this->assertSame(1, $this->client->roundTrips);
    $this->assertSame(['mget'], $this->client->log);
  }

  /**
   * An empty learned set must not break the first ever request.
   *
   * @covers ::warmSet
   */
  public function testAnEmptyLearnedSetIsHarmless(): void {
    $provider = $this->provider();
    $this->client->resetCounters();

    $this->assertTrue($provider->isValid(0, ['some:tag']));
    $this->assertSame(1, $this->client->roundTrips);
  }

}

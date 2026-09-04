<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
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
   * Makes the database connection report an open transaction.
   *
   * The checksum provider reaches for \Drupal::database() lazily, and only when
   * a tag is invalidated, so a container holding a mock is all it takes to put
   * an invalidation on the delayed path.
   */
  protected function openTransaction(): void {
    $connection = $this->createMock(Connection::class);
    $connection->method('inTransaction')->willReturn(TRUE);
    $connection->method('transactionManager')
      ->willReturn($this->createMock(TransactionManagerInterface::class));
    $container = new ContainerBuilder();
    $container->set('database', $connection);
    \Drupal::setContainer($container);
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
   * A tag waiting on a transaction must never be preloaded.
   *
   * Its counter in Redis has not been incremented yet, so preloading it copies
   * the pre-invalidation count into the static tag cache - and the commit that
   * finally runs the INCR clears the delayed list but not that cache. The tag
   * would read as valid for the rest of the process, which in a drush, cron or
   * queue run is every subsequent read.
   *
   * @covers ::registerCacheTagsForPreload
   */
  public function testDelayedTagsAreNotPreloaded(): void {
    $provider = $this->provider();
    $this->openTransaction();

    // Inside the transaction the INCR is held back, so the counter in Redis is
    // still the one every cache entry written before the invalidation matches.
    $provider->invalidateTags(['node:1']);

    // Still inside the transaction, a cache read hands the raw tags of every
    // entry it returned to the preload hook, as DeferredRedisBackend does, and
    // one of those entries is then validated.
    $provider->registerCacheTagsForPreload(['node:1', 'node:2']);
    $provider->isValid(0, ['node:2']);

    // The transaction commits: the INCR lands and the delay is over.
    $provider->rootTransactionEndCallback(TRUE);
    // Cast: core's interface documents a string return, while every
    // implementation of it sums integers.
    $this->assertSame(
      1,
      (int) $this->provider()->getCurrentChecksum(['node:1']),
      'The commit must have incremented the counter in Redis.',
    );

    $this->assertFalse(
      $provider->isValid(0, ['node:1']),
      'The pre-invalidation checksum must not outlive the transaction.',
    );
  }

  /**
   * The learned set is a speculative fetch too, and obeys the same rule.
   *
   * A tag that every request looks at is exactly the kind of tag the learned
   * set holds, so this is the likelier way into the bug, not the rarer one.
   *
   * @covers ::getTagInvalidationCounts
   * @covers ::warmSet
   */
  public function testDelayedTagsAreNotPreloadedFromTheLearnedSet(): void {
    // Two requests to get node:1 into the learned set.
    for ($request = 0; $request < 2; $request++) {
      $warming = $this->provider();
      $warming->isValid(0, ['node:1']);
      $warming->learn();
    }

    $provider = $this->provider();
    $this->openTransaction();
    $provider->invalidateTags(['node:1']);

    // Any lookup at all drags the learned set in behind it.
    $provider->isValid(0, ['config:system.site']);

    $provider->rootTransactionEndCallback(TRUE);
    $this->assertSame(1, (int) $this->provider()->getCurrentChecksum(['node:1']));

    $this->assertFalse(
      $provider->isValid(0, ['node:1']),
      'The learned set must not pin a delayed tag either.',
    );
  }

  /**
   * The registered tags must be a list, which is what core 11.2 merges.
   *
   * From 11.2 the $preloadTags property belongs to core's
   * CacheTagsChecksumTrait, whose ::calculateChecksum() folds it into the tag
   * list. A set survives that fold as its boolean values, and TRUE then reads
   * as the tag "1" - requested from Redis on every lookup and, through
   * ::learn(), written into the warm set forever. There is no Drupal 11.2 to
   * run this against here, so the assertion is on the shape of the property and
   * on what core does with it.
   *
   * @covers ::registerCacheTagsForPreload
   */
  public function testRegisteredTagsKeepTheShapeCoreExpects(): void {
    $provider = $this->provider();
    $provider->registerCacheTagsForPreload(['node:1', 'node:2']);

    $property = new \ReflectionProperty(PreloadingRedisCacheTagsChecksum::class, 'preloadTags');
    $preload = $property->getValue($provider);
    $this->assertSame(['node:1', 'node:2'], $preload);

    // Verbatim from CacheTagsChecksumTrait::calculateChecksum() in 11.2.
    $tags_with_preload = array_unique(array_merge(['config:system.site'], $preload));
    $this->assertSame(
      ['config:system.site', 'node:1', 'node:2'],
      array_values($tags_with_preload),
      'Core must end up with tag names only, and no stray boolean.',
    );
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

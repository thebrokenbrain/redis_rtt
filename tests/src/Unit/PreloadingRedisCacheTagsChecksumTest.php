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
  protected function noTransaction(): void {
    $connection = $this->createMock(Connection::class);
    $connection->method('inTransaction')->willReturn(FALSE);
    $connection->method('transactionManager')
      ->willReturn($this->createMock(TransactionManagerInterface::class));
    $container = new ContainerBuilder();
    $container->set('database', $connection);
    \Drupal::setContainer($container);
  }

  /**
   * Puts a mocked open transaction in the container.
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
    // entry it returned to the preload hook, as the cache backend does, and
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
   * Registered tags go on this class's own list, and core's stays empty.
   *
   * From 11 the $preloadTags property belongs to core's CacheTagsChecksumTrait,
   * which runs a preload of its own on it: ::calculateChecksum() drains it and
   * hands the drained tags to ::getTagInvalidationCounts() mixed in with the
   * ones the caller asked for, then puts everything that comes back into the
   * static tag cache. A count fetched on spec would be authoritative for the
   * rest of the process that way, past the window this class bounds it with -
   * measured on core 11.4.6, with $settings['redis_rtt_tag_warmset_ttl'] at 0
   * and a tag another process had invalidated still answering with the old
   * count.
   *
   * So the two lists are kept apart. Core's has to be empty, because that is
   * what switches its preload off, and this class's has to be a list of names:
   * a set survives an array_merge() as its boolean values, and TRUE then reads
   * as the tag "1", requested from Redis on every lookup and written into the
   * warm set for ever by ::learn().
   *
   * @covers ::registerCacheTagsForPreload
   */
  public function testRegisteredTagsGoOnThisClassesOwnList(): void {
    $provider = $this->provider();
    $provider->registerCacheTagsForPreload(['node:1', 'node:2']);

    $own = new \ReflectionProperty(PreloadingRedisCacheTagsChecksum::class, 'pendingPreload');
    $this->assertSame(
      ['node:1', 'node:2'],
      $own->getValue($provider),
      'Tag names only, as a list: a set would put a stray boolean in the next array_merge().'
    );

    $core = new \ReflectionProperty(PreloadingRedisCacheTagsChecksum::class, 'preloadTags');
    $this->assertSame(
      [],
      $core->getValue($provider),
      "Core's own preload list has to stay empty: a tag in it is a tag fetched past this class's window."
    );
  }

  /**
   * Core's own preload is switched off, not merely left unused.
   *
   * Nothing in this class fills the inherited property, so on a correct build
   * it is empty anyway and the line that empties it changes nothing. It is
   * there for the case that stops being true: a future core, or a subclass,
   * putting something in it. What core does with a tag it finds there is take
   * the count on spec and make it authoritative for the rest of the process,
   * which is the one thing the window exists to prevent.
   *
   * The property is filled by reflection because that is the only way to reach
   * the state from here. Note this test can only discriminate on core 11 and
   * later: before that the trait has no preload of its own and the property is
   * this class's alone.
   *
   * @covers ::calculateChecksum
   */
  public function testCoresOwnPreloadIsSwitchedOff(): void {
    new Settings([
      'redis_rtt_tag_warmset_min_hits' => 2,
      // Any speculative count is stale the moment it is asked for.
      'redis_rtt_tag_warmset_ttl' => 0.0,
      'cache_prefix' => 'drupal',
    ]);
    $key = 'drupal:cachetags:node:1';
    $this->client->data[$key] = 3;
    $this->client->data['drupal:cachetags:node:2'] = 1;

    $provider = $this->provider();
    // As if core - or anything else - had registered a tag on its list.
    $inherited = new \ReflectionProperty(PreloadingRedisCacheTagsChecksum::class, 'preloadTags');
    $inherited->setValue($provider, ['node:1']);
    $provider->getCurrentChecksum(['node:2']);

    // Another process invalidates node:1 while this one is still running.
    $this->client->data[$key] = 4;

    $this->assertSame(
      4,
      (int) $provider->getCurrentChecksum(['node:1']),
      "A tag on core's preload list must not become authoritative behind this class's back.",
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

  /**
   * A speculative count must not answer for its tag once it has aged out.
   *
   * The warm set fetches counts for tags nobody has asked for yet. In a request
   * that lasts milliseconds that is free batching. In a cron run, a queue
   * worker or a migration it is not: an editor saving content elsewhere would
   * go unseen for the rest of the run, and the process would keep validating
   * cache entries against a count read minutes earlier.
   *
   * @covers ::getTagInvalidationCounts
   */
  public function testAnAgedSpeculativeCountIsNotTrusted(): void {
    new Settings([
      'redis_rtt_tag_warmset_min_hits' => 2,
      // Every speculative count is stale by the time it is asked for.
      'redis_rtt_tag_warmset_ttl' => 0.0,
      // Pins the key prefix so the test can stand in for another process.
      'cache_prefix' => 'drupal',
    ]);
    $key = 'drupal:cachetags:node:1';
    $this->client->data[$key] = 3;
    $this->client->data['drupal:cachetags:node:2'] = 1;

    $provider = $this->provider();
    // Registration is the deterministic way a tag becomes speculative: a cache
    // read hands the backend's tags over, and the next lookup drags them along.
    $provider->registerCacheTagsForPreload(['node:1']);
    $provider->getCurrentChecksum(['node:2']);

    // Another process invalidates node:1 while this one is still running.
    $this->client->data[$key] = 4;

    $this->assertSame(
      4,
      (int) $provider->getCurrentChecksum(['node:1']),
      'An aged speculative count must be re-read, not served.',
    );
  }

  /**
   * Invalidating a tag must drop the speculative count this process holds.
   *
   * The freshness window is no protection here: the count was read before the
   * increment, so it can be young and stale at the same time. And promoting it
   * would do more than serve one stale entry - every cache entry written
   * afterwards would carry the pre-invalidation checksum, and since the
   * counters only rise, those entries would be a permanent miss for every other
   * process on the site.
   *
   * @covers ::invalidateTags
   */
  public function testInvalidatingTagsDropsTheirSpeculativeCount(): void {
    new Settings([
      'redis_rtt_tag_warmset_min_hits' => 2,
      // Long enough that only the invalidation can drop the count.
      'redis_rtt_tag_warmset_ttl' => 60.0,
      'cache_prefix' => 'drupal',
    ]);
    $this->noTransaction();
    $this->client->data['drupal:cachetags:node:1'] = 3;
    $this->client->data['drupal:cachetags:node:2'] = 1;

    $provider = $this->provider();
    // node:1 is fetched speculatively while looking up node:2.
    $provider->registerCacheTagsForPreload(['node:1']);
    $provider->getCurrentChecksum(['node:2']);

    // This same process now invalidates node:1.
    $provider->invalidateTags(['node:1']);
    $expected = (int) $this->client->data['drupal:cachetags:node:1'];

    $this->assertSame(
      $expected,
      (int) $provider->getCurrentChecksum(['node:1']),
      'A speculative count cannot survive the invalidation of its own tag.',
    );
  }

  /**
   * The freshness window bounds the consequence, not only the use.
   *
   * On its own the window does nothing about how long the consequence lasts,
   * because core folds whatever ::getTagInvalidationCounts() returns into its
   * static tag cache and nothing empties that until the process ends. A count
   * that was one millisecond young when it was consulted would otherwise stay
   * authoritative for a whole cron run - the failure the window was added to
   * prevent, narrowed but not removed.
   *
   * So the window here is tiny and real time is allowed to pass: only
   * ::calculateChecksum() keeping the tag out of the static cache can make the
   * lookup after it see the new value.
   *
   * @covers ::calculateChecksum
   */
  public function testTheWindowBoundsTheConsequenceAndNotOnlyTheUse(): void {
    $window = 0.05;
    new Settings([
      'redis_rtt_tag_warmset_min_hits' => 2,
      'redis_rtt_tag_warmset_ttl' => $window,
      'cache_prefix' => 'drupal',
    ]);
    $key = 'drupal:cachetags:node:1';
    $this->client->data[$key] = 3;
    $this->client->data['drupal:cachetags:node:2'] = 1;

    $provider = $this->provider();
    // node:1 is fetched speculatively while looking up node:2, then asked for.
    $provider->registerCacheTagsForPreload(['node:1']);
    $provider->getCurrentChecksum(['node:2']);
    $this->assertSame(3, (int) $provider->getCurrentChecksum(['node:1']));

    // Another process invalidates node:1 after that first, speculative answer.
    $this->client->data[$key] = 4;

    // Once the window has passed, the count has to be read again. Without
    // ::calculateChecksum() removing the tag, core's static cache would answer
    // 3 here for the rest of the process however long the window was.
    usleep((int) ($window * 2 * 1000000));
    $this->assertSame(
      4,
      (int) $provider->getCurrentChecksum(['node:1']),
      'Past its window, a speculatively answered tag must be re-read.',
    );
  }

  /**
   * Inside its window, a speculative count answers again without a round trip.
   *
   * This is the other half of the contract, and it is the half that was broken:
   * the speculative entry used to be discarded on first use, so the second
   * lookup of the same tag went to Redis whatever the window said - a round
   * trip per preloaded tag asked more than once, which on a warm content
   * listing was 61 of them and took the saving there from 49% to 12%.
   *
   * A tag asked for repeatedly is the ordinary shape of a warm page: every
   * cache read validates the same handful of configuration tags.
   *
   * @covers ::getTagInvalidationCounts
   */
  public function testInsideItsWindowSpeculativeCountIsNotReRead(): void {
    new Settings([
      'redis_rtt_tag_warmset_min_hits' => 2,
      // Wide enough that nothing here can age out mid-test.
      'redis_rtt_tag_warmset_ttl' => 3600.0,
      'cache_prefix' => 'drupal',
    ]);
    $this->client->data['drupal:cachetags:node:1'] = 3;
    $this->client->data['drupal:cachetags:node:2'] = 1;

    $provider = $this->provider();
    $provider->registerCacheTagsForPreload(['node:1']);
    $provider->getCurrentChecksum(['node:2']);

    $this->client->resetCounters();
    for ($lookup = 0; $lookup < 5; $lookup++) {
      $this->assertSame(3, (int) $provider->getCurrentChecksum(['node:1']));
    }

    $this->assertSame(
      0,
      $this->client->roundTrips,
      'A count already read ahead of time must answer for its whole window, not once.',
    );
  }

}

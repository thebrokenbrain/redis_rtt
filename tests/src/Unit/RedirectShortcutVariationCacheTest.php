<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\Context\ContextCacheKeys;
use Drupal\Core\Cache\VariationCache;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\RedirectShortcutVariationCache;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @coversDefaultClass \Drupal\redis_rtt\Cache\RedirectShortcutVariationCache
 * @group redis_rtt
 */
class RedirectShortcutVariationCacheTest extends UnitTestCase {

  /**
   * The cache backend, shared across simulated requests.
   */
  protected CountingMemoryBackend $backend;

  /**
   * The learned-mapping store, shared across simulated requests.
   */
  protected ArrayShortcutStore $store;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * The cache contexts manager.
   */
  protected CacheContextsManager $contextsManager;

  /**
   * The initial (pre-bubbling) cacheability of the element under test.
   */
  protected CacheableMetadata $initial;

  /**
   * The final cacheability, one context wider than the initial one.
   */
  protected CacheableMetadata $final;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['redis_rtt_redirect_shortcut' => TRUE]);

    $this->backend = new CountingMemoryBackend();
    $this->store = new ArrayShortcutStore();
    $this->requestStack = new RequestStack();
    $this->requestStack->push(Request::create('/'));

    // Turns context tokens into deterministic keys without a container.
    $this->contextsManager = $this->createMock(CacheContextsManager::class);
    $this->contextsManager->method('convertTokensToKeys')
      ->willReturnCallback(static function (array $tokens): ContextCacheKeys {
        sort($tokens);
        return new ContextCacheKeys(array_map(
          static fn (string $token): string => "[$token]=v",
          $tokens,
        ));
      });

    $this->initial = (new CacheableMetadata())
      ->setCacheContexts(['languages:language_interface', 'theme']);
    $this->final = (new CacheableMetadata())
      ->setCacheContexts(['languages:language_interface', 'theme', 'user.permissions']);
  }

  /**
   * Builds a variation cache, as a new request would.
   *
   * @param bool $shortcut
   *   Whether the shortcut is enabled.
   *
   * @return \Drupal\redis_rtt\Cache\RedirectShortcutVariationCache
   *   The cache.
   */
  protected function cache(bool $shortcut = TRUE): RedirectShortcutVariationCache {
    new Settings(['redis_rtt_redirect_shortcut' => $shortcut]);
    return new RedirectShortcutVariationCache(
      $this->requestStack,
      $this->backend,
      $this->contextsManager,
      'render',
      $this->store,
    );
  }

  /**
   * Builds the stock variation cache, for comparison.
   *
   * @return \Drupal\Core\Cache\VariationCache
   *   The cache.
   */
  protected function stockCache(): VariationCache {
    return new VariationCache($this->requestStack, $this->backend, $this->contextsManager);
  }

  /**
   * Stores an element that needs a redirect to reach its data.
   *
   * @param string[] $keys
   *   The cache keys.
   */
  protected function seed(array $keys): void {
    $this->stockCache()->set($keys, 'the data', $this->final, $this->initial);
  }

  /**
   * The stock cache needs two sequential round trips per hit; the baseline.
   */
  public function testTheStockCacheCostsTwoRoundTripsPerHit(): void {
    $this->seed(['element']);
    $this->backend->resetCounters();

    $this->assertNotFalse($this->stockCache()->get(['element'], $this->initial));
    $this->assertSame(2, $this->backend->gets, 'Redirect, then data.');
    $this->assertSame(2, $this->backend->roundTrips, 'And it has to wait for each.');
  }

  /**
   * Once the mapping is known, a hit costs one round trip.
   *
   * The chain is still read in full - that is what makes the shortcut safe -
   * but it is read in a single multi-get instead of one sequential get per hop,
   * and waiting is the cost that matters.
   *
   * @covers ::get
   * @covers ::followShortcut
   * @covers ::readMany
   */
  public function testHitCostsOneRoundTripOnceLearned(): void {
    $this->seed(['element']);

    // First request learns the mapping.
    $this->cache()->get(['element'], $this->initial);

    // Second request uses it.
    $this->backend->resetCounters();
    $result = $this->cache()->get(['element'], $this->initial);

    $this->assertNotFalse($result);
    $this->assertSame('the data', $result->data);
    $this->assertSame(1, $this->backend->roundTrips, 'The sequential hop must be gone.');
    // Without this the test passes even when the shortcut never fires: the
    // fallback walk reuses the memoised replies, so it costs the same one round
    // trip, and it re-learns the mapping it just discarded, so the store looks
    // identical afterwards. The discard is the only observable difference.
    $this->assertSame(0, $this->store->deletes, 'The mapping has to have been used, not discarded and re-learnt.');
  }

  /**
   * The shortcut returns exactly what the stock implementation would.
   *
   * @covers ::get
   */
  public function testTheShortcutReturnsTheSameDataAsCore(): void {
    $this->seed(['element']);
    $this->cache()->get(['element'], $this->initial);

    $expected = $this->stockCache()->get(['element'], $this->initial);
    $actual = $this->cache()->get(['element'], $this->initial);

    $this->assertEquals($expected, $actual);
  }

  /**
   * A learned mapping that no longer resolves must report a miss.
   *
   * This is the correctness guarantee: the shortcut is only ever trusted when
   * it lands on real data, so a stale mapping degrades to a miss, never to
   * wrong data.
   *
   * @covers ::get
   */
  public function testStaleMappingReportsMissRatherThanStaleData(): void {
    $this->seed(['element']);
    $this->cache()->get(['element'], $this->initial);

    // The data entry disappears; the redirect remains.
    $this->backend->deleteWhere(static fn (string $cid): bool => str_contains($cid, 'user.permissions'));

    $this->assertFalse($this->cache()->get(['element'], $this->initial));
  }

  /**
   * A wrong guess must not cost an extra round trip.
   *
   * The fallback reuses the reply the shortcut already fetched, so a miss costs
   * what it would have cost anyway.
   *
   * @covers ::get
   * @covers ::fetch
   */
  public function testWrongGuessCostsNoExtraRoundTrip(): void {
    $this->seed(['element']);
    $this->cache()->get(['element'], $this->initial);
    $this->backend->deleteWhere(static fn (string $cid): bool => str_contains($cid, 'user.permissions'));

    $this->backend->resetCounters();
    $this->cache()->get(['element'], $this->initial);
    $shortcut_trips = $this->backend->roundTrips;

    // What the stock implementation would have spent on the same miss.
    $this->backend->resetCounters();
    $this->stockCache()->get(['element'], $this->initial);

    $this->assertLessThanOrEqual($this->backend->roundTrips, $shortcut_trips);
  }

  /**
   * An entry orphaned by a reshaped chain must never be served.
   *
   * Core's VariationCache::set() rewrites a chain's redirects in place when an
   * element's contexts change, and never deletes the data entry that hung off
   * the old path. That address stays populated while the chain no longer leads
   * to it, so a shortcut that checked only its destination would hand back an
   * entry cached for a set of contexts the request never asked for - on a site
   * where the contexts differ per user, another user's render.
   *
   * @covers ::get
   * @covers ::followShortcut
   */
  public function testOrphanedEntryFromReshapedChainIsNotServed(): void {
    // Core refuses to replace a redirect with one that has nothing in common
    // with it (VariationCache::set()), so a reshape only happens when the two
    // context sets overlap. These do: both add theme, and they disagree about
    // which user context the element varies by - which is exactly the case
    // where the orphaned entry belongs to different users than the request.
    $initial = (new CacheableMetadata())
      ->setCacheContexts(['languages:language_interface']);
    $first = (new CacheableMetadata())
      ->setCacheContexts(['languages:language_interface', 'theme', 'user.permissions']);
    $reshaped = (new CacheableMetadata())
      ->setCacheContexts(['languages:language_interface', 'theme', 'user']);

    $this->stockCache()->set(['element'], 'the data', $first, $initial);

    // A request learns the mapping to the user.permissions address.
    $this->cache()->get(['element'], $initial);
    $this->assertNotSame([], $this->store->entries, 'The mapping was learned.');

    // The element's contexts change. Core reshapes the chain and writes the new
    // data at a new address, leaving the old one populated and unreachable.
    // Through the stock cache, because a write on another web node does not
    // touch this node's learned mappings.
    $this->stockCache()->set(['element'], 'the new data', $reshaped, $initial);

    $result = $this->cache()->get(['element'], $initial);

    $this->assertNotFalse($result);
    $this->assertNotSame('the data', $result->data, 'The orphaned entry must not be served.');
    $this->assertSame('the new data', $result->data);
    $this->assertEquals(
      $this->stockCache()->get(['element'], $initial),
      $result,
      'The shortcut must agree with core, always.',
    );
  }

  /**
   * Every hop of a longer chain is verified, not just the first.
   *
   * A two-hop chain is where checking the destination alone stops being enough
   * *and* where checking only the entry point stops being enough. Core rewrote
   * the second redirect and left what hung off it orphaned, so a shortcut that
   * stopped verifying after hop one would jump the reshaped hop and return it.
   *
   * @covers ::followShortcut
   * @covers ::chainShape
   */
  public function testEveryHopOfTheChainIsVerified(): void {
    $initial = (new CacheableMetadata())->setCacheContexts(['languages:language_interface']);
    $md = static fn (array $contexts): CacheableMetadata =>
      (new CacheableMetadata())->setCacheContexts($contexts);

    // Two nested redirects: the element first varies by theme, then by theme
    // plus user.permissions on top of it.
    $this->stockCache()->set(['element'], 'shallow', $md(['languages:language_interface', 'theme']), $initial);
    $this->stockCache()->set(
      ['element'],
      'deep',
      $md(['languages:language_interface', 'theme', 'user.permissions', 'url.query_args']),
      $initial,
    );

    $this->cache()->get(['element'], $initial);
    $shape = reset($this->store->entries);
    $this->assertCount(2, $shape, 'The learned chain has two hops to verify.');

    // The second hop is rewritten. The first is untouched, so a shortcut that
    // trusted it alone would walk straight into the orphaned entry.
    $this->stockCache()->set(
      ['element'],
      'reshaped',
      $md(['languages:language_interface', 'theme', 'url.query_args', 'user.roles']),
      $initial,
    );

    $result = $this->cache()->get(['element'], $initial);

    $this->assertNotFalse($result);
    $this->assertNotSame('deep', $result->data, 'The entry orphaned by the second hop must not be served.');
    $this->assertEquals(
      $this->stockCache()->get(['element'], $initial),
      $result,
      'The shortcut must agree with core on a multi-hop chain too.',
    );
  }

  /**
   * The first hop is verified too, not only the one nearest the data.
   *
   * Core breaks a chain at the first redirect that is incompatible with what it
   * is writing, so the hop it rewrites is often the leading one, and everything
   * after it is orphaned in place. Verifying from the data backwards would pass
   * that case while walking straight past the rewritten hop.
   *
   * @covers ::followShortcut
   */
  public function testTheFirstHopOfTheChainIsVerifiedToo(): void {
    $language = 'languages:language_interface';
    $initial = (new CacheableMetadata())->setCacheContexts([$language]);
    $md = static fn (array $contexts): CacheableMetadata =>
      (new CacheableMetadata())->setCacheContexts($contexts);

    $this->stockCache()->set(['element'], 'shallow', $md([$language, 'theme', 'user.roles']), $initial);
    $this->stockCache()->set(
      ['element'],
      "another user's render",
      $md([$language, 'theme', 'user.roles', 'url.query_args']),
      $initial,
    );

    $this->cache()->get(['element'], $initial);
    $this->assertCount(2, reset($this->store->entries), 'Two hops, so there is a leading one to get wrong.');

    // Rewrites the first hop only. The overlap with the learned contexts is
    // wider than the initial ones, so core reshapes rather than refusing.
    $this->stockCache()->set(
      ['element'],
      'the current render',
      $md([$language, 'user.roles', 'user.permissions']),
      $initial,
    );

    $result = $this->cache()->get(['element'], $initial);

    $this->assertNotFalse($result);
    $this->assertNotSame("another user's render", $result->data, 'The entry orphaned behind the rewritten first hop must not be served.');
    $this->assertSame('the current render', $result->data);
  }

  /**
   * A mapping stored in the old flat format is discarded, not trusted.
   *
   * Entries written by an earlier version of this module survive in APCu across
   * the deploy that upgrades it. They record only the final contexts, which
   * proves nothing about the hops, so they cannot be verified.
   *
   * @covers ::followShortcut
   */
  public function testMappingInTheOldFlatFormatIsDiscarded(): void {
    $this->seed(['element']);
    $this->cache()->get(['element'], $this->initial);

    $key = array_key_first($this->store->entries);
    $this->store->entries[$key] = ['languages:language_interface', 'theme', 'user.permissions'];

    $result = $this->cache()->get(['element'], $this->initial);

    $this->assertNotFalse($result);
    $this->assertSame('the data', $result->data);
  }

  /**
   * The chain is memoised within a request; the data deliberately is not.
   *
   * Memoising the redirect is what removes the duplicate chain walk. Memoising
   * the data would remove something else: the backend's cache tag check, which
   * happens on every read. So the second read of the same element costs the one
   * round trip for the data and none for the hop.
   *
   * @covers ::fetch
   * @covers ::remember
   */
  public function testTheChainIsMemoisedButTheDataIsNot(): void {
    $this->seed(['element']);
    $cache = $this->cache(FALSE);

    $cache->get(['element'], $this->initial);
    $this->backend->resetCounters();
    $cache->get(['element'], $this->initial);

    $this->assertSame(1, $this->backend->roundTrips, 'The redirect hop is memoised, the data is re-read.');
  }

  /**
   * An entry invalidated mid-process must not be served from the memo.
   *
   * Cache tags are invalidated by other processes, and the backend enforces
   * them on read. A memo that answers a later read with an earlier hit skips
   * that enforcement, so the entry goes on being served as fresh - for the life
   * of the process, which in a queue worker or an indexing run is minutes. Core
   * 11.2 excludes hits from its own chain memo for this exact reason.
   *
   * @covers ::remember
   */
  public function testAnInvalidatedEntryIsNotServedFromTheMemo(): void {
    $this->seed(['element']);
    $cache = $this->cache(FALSE);

    $this->assertNotFalse($cache->get(['element'], $this->initial), 'Warm the memo.');

    // Another process empties the bin: no write happens through this service,
    // so nothing here knows about it. ::deleteAll() rather than the deprecated
    // ::invalidateAll(), which is what that deprecation points at; either way
    // the entry stops answering, which is what the memo must not hide.
    $this->backend->deleteAll();

    $this->assertFalse(
      $cache->get(['element'], $this->initial),
      'The entry is invalid now, and a memo must not be able to hide that.',
    );
  }

  /**
   * A write invalidates the memo, so a later read sees the new value.
   *
   * @covers ::set
   */
  public function testWriteInvalidatesTheRequestMemo(): void {
    $this->seed(['element']);
    $cache = $this->cache();
    $cache->get(['element'], $this->initial);

    $cache->set(['element'], 'rewritten', $this->final, $this->initial);
    $result = $cache->get(['element'], $this->initial);

    $this->assertNotFalse($result);
    $this->assertSame('rewritten', $result->data);
  }

  /**
   * With the shortcut off, behaviour is core's, hop and all.
   *
   * @covers ::get
   */
  public function testTheShortcutCanBeDisabled(): void {
    $this->seed(['element']);
    $this->cache()->get(['element'], $this->initial);

    $this->backend->resetCounters();
    $this->assertNotFalse($this->cache(FALSE)->get(['element'], $this->initial));
    $this->assertSame(2, $this->backend->roundTrips, 'Disabled means the full chain walk.');
  }

  /**
   * An element with no redirect has nothing to shortcut and learns nothing.
   *
   * @covers ::get
   */
  public function testElementWithoutRedirectIsNotLearned(): void {
    $this->stockCache()->set(['plain'], 'data', $this->initial, $this->initial);

    $cache = $this->cache();
    $this->assertNotFalse($cache->get(['plain'], $this->initial));
    $this->assertSame([], $this->store->entries, 'A single-entry chain teaches nothing.');
  }

}

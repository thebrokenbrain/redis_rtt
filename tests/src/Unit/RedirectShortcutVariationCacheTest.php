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
   * The stock cache needs two gets per hit; that is the baseline.
   */
  public function testTheStockCacheCostsTwoGetsPerHit(): void {
    $this->seed(['element']);
    $this->backend->resetCounters();

    $this->assertNotFalse($this->stockCache()->get(['element'], $this->initial));
    $this->assertSame(2, $this->backend->gets, 'Redirect, then data.');
  }

  /**
   * Once the mapping is known, a hit costs one get.
   *
   * @covers ::get
   */
  public function testHitCostsOneGetOnceLearned(): void {
    $this->seed(['element']);

    // First request learns the mapping.
    $this->cache()->get(['element'], $this->initial);

    // Second request uses it.
    $this->backend->resetCounters();
    $result = $this->cache()->get(['element'], $this->initial);

    $this->assertNotFalse($result);
    $this->assertSame('the data', $result->data);
    $this->assertSame(1, $this->backend->gets, 'The redirect hop must be skipped.');
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
  public function testWrongGuessCostsNoExtraGet(): void {
    $this->seed(['element']);
    $this->cache()->get(['element'], $this->initial);
    $this->backend->deleteWhere(static fn (string $cid): bool => str_contains($cid, 'user.permissions'));

    $this->backend->resetCounters();
    $this->cache()->get(['element'], $this->initial);
    $shortcut_gets = $this->backend->gets;

    // What the stock implementation would have spent on the same miss.
    $this->backend->resetCounters();
    $this->stockCache()->get(['element'], $this->initial);

    $this->assertLessThanOrEqual($this->backend->gets, $shortcut_gets);
  }

  /**
   * Repeated reads of one cache ID in a request hit the backend once.
   *
   * @covers ::fetch
   */
  public function testReadsAreMemoisedWithinRequest(): void {
    $this->seed(['element']);
    $cache = $this->cache(FALSE);

    $cache->get(['element'], $this->initial);
    $this->backend->resetCounters();
    $cache->get(['element'], $this->initial);

    $this->assertSame(0, $this->backend->gets);
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
    $this->assertSame(2, $this->backend->gets, 'Disabled means the full chain walk.');
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

<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheRedirect;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\VariationCache;
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Variation cache that skips the cache-redirect hop on a hit.
 *
 * \Drupal\Core\Cache\VariationCache::getRedirectChain() walks a chain of
 * dependent cache gets: it reads the cache ID built from the pre-bubbling
 * ("initial") cache contexts, discovers a \Drupal\Core\Cache\CacheRedirect
 * naming the real set of contexts, then reads again at the resulting cache ID.
 * Each hop is a separate, strictly sequential round trip because the next cache
 * ID is not known until the previous reply comes back.
 *
 * Drupal calls this for every render-cacheable element (Renderer::doRender()),
 * for Dynamic Page Cache, for the JSON:API normalization cacher and for every
 * access-policy lookup. An authenticated page render performs dozens of these,
 * so on a cross-AZ ElastiCache primary the redirect hops alone can dominate the
 * response time.
 *
 * The mapping being resolved by those hops - "an element cached under these
 * keys, entered with these initial contexts, ends up varying by these final
 * contexts" - is structural. It does not depend on the *values* of the contexts
 * and therefore not on the user, the language or the request. That makes it
 * safe to memoize per web node in APCu and use it to jump straight to the final
 * cache ID, which is then built from the current request's context values as
 * usual.
 *
 * Correctness is not taken on trust, and landing on data is not evidence that
 * the data is the right data. Core's VariationCache::set() reshapes a chain in
 * place when an element's contexts change, and it never deletes the data entry
 * that hung off the old path, so an address the chain no longer leads to can
 * stay populated with an entry cached for a different set of contexts. A
 * shortcut that only checked its destination would return that entry - to a
 * user it was never cached for.
 *
 * So the mapping records the whole shape of the chain, the cache contexts of
 * every redirect in it in order, and using it verifies that shape: every
 * intermediate entry must still be a CacheRedirect naming exactly the contexts
 * that were learned. Anything else falls back to the full chain walk and
 * re-learns the mapping.
 *
 * The entire chain is fetched in one multi-get, so verification costs a single
 * round trip where the walk costs one per hop, and a mapping that fails
 * verification costs nothing extra: the replies are memoized, so the fallback
 * walk reuses them.
 */
class RedirectShortcutVariationCache extends VariationCache {

  /**
   * Per-request memo of cache backend replies, keyed by cache ID.
   *
   * Also removes the duplicate chain walk that happens when
   * \Drupal\Core\Render\RenderCache::set() re-resolves a chain that
   * ::get() already resolved moments earlier on a render cache miss.
   *
   * @var array<string, object|false>
   */
  protected array $fetched = [];

  /**
   * Where learned mappings live.
   */
  protected ShortcutStoreInterface $store;

  /**
   * Whether the shortcut is switched on.
   */
  protected bool $enabled;

  public function __construct(
    RequestStack $request_stack,
    CacheBackendInterface $cache_backend,
    CacheContextsManager $cache_contexts_manager,
    protected string $bin = 'render',
    ?ShortcutStoreInterface $store = NULL,
  ) {
    parent::__construct($request_stack, $cache_backend, $cache_contexts_manager);

    $this->store = $store ?? new ApcuShortcutStore($this->bin);
    $this->enabled = (bool) Settings::get('redis_rtt_redirect_shortcut', TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function get(array $keys, CacheableDependencyInterface $initial_cacheability) {
    if (!$this->enabled) {
      $chain = $this->getRedirectChain($keys, $initial_cacheability);
      return end($chain);
    }

    $shortcut_key = $this->getShortcutKey($keys, $initial_cacheability);

    if ($shape = $this->store->get($shortcut_key)) {
      if ($result = $this->followShortcut($keys, $initial_cacheability, $shape)) {
        return $result;
      }
      // The mapping no longer describes what is in the cache.
      $this->store->delete($shortcut_key);
    }

    $chain = $this->getRedirectChain($keys, $initial_cacheability);
    $result = end($chain);

    // Learn the mapping, but only from a chain that actually took a hop and
    // ended on data. A single-entry chain has nothing to shortcut.
    if (count($chain) > 1 && $result && !($result->data instanceof CacheRedirect)) {
      $this->store->set($shortcut_key, $this->chainShape($chain));
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function set(array $keys, $data, CacheableDependencyInterface $cacheability, CacheableDependencyInterface $initial_cacheability): void {
    parent::set($keys, $data, $cacheability, $initial_cacheability);

    // The chain this write just created or reshaped is now stale in both memos.
    $this->fetched = [];
    $this->store->delete($this->getShortcutKey($keys, $initial_cacheability));
  }

  /**
   * {@inheritdoc}
   */
  public function delete(array $keys, CacheableDependencyInterface $initial_cacheability): void {
    parent::delete($keys, $initial_cacheability);
    $this->fetched = [];
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate(array $keys, CacheableDependencyInterface $initial_cacheability): void {
    parent::invalidate($keys, $initial_cacheability);
    $this->fetched = [];
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $keys
   *   The cache keys.
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $initial_cacheability
   *   The pre-bubbling cacheable metadata.
   *
   * @return array<string, object|false>
   *   Every cache get that led to the result, keyed by cache ID.
   */
  protected function getRedirectChain(array $keys, CacheableDependencyInterface $initial_cacheability): array {
    $cid = $this->createCacheIdFast($keys, $initial_cacheability);
    $chain[$cid] = $result = $this->fetch($cid);

    while ($result && $result->data instanceof CacheRedirect) {
      $cid = $this->createCacheIdFast($keys, $result->data);
      $chain[$cid] = $result = $this->fetch($cid);
    }

    return $chain;
  }

  /**
   * Reads from the cache backend, once per cache ID per request.
   *
   * @param string $cid
   *   The cache ID.
   *
   * @return object|false
   *   The cache item, or FALSE on a miss.
   */
  protected function fetch(string $cid) {
    if (array_key_exists($cid, $this->fetched)) {
      return $this->fetched[$cid];
    }
    return $this->fetched[$cid] = $this->cacheBackend->get($cid);
  }

  /**
   * Resolves a learned chain shape, verifying it against the cache.
   *
   * @param string[] $keys
   *   The cache keys.
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $initial_cacheability
   *   The pre-bubbling cacheable metadata.
   * @param array<mixed> $shape
   *   A chain shape as returned by ::chainShape().
   *
   * @return object|null
   *   The cache item the learned chain leads to, or NULL when the mapping does
   *   not describe what is in the cache and the chain has to be walked.
   */
  protected function followShortcut(array $keys, CacheableDependencyInterface $initial_cacheability, array $shape): ?object {
    // A mapping written before the shape was recorded holds a flat context
    // list, which proves nothing about the hops. Discard it.
    foreach ($shape as $contexts) {
      if (!is_array($contexts)) {
        return NULL;
      }
    }

    try {
      $cids = [$this->createCacheIdFast($keys, $initial_cacheability)];
      foreach ($shape as $contexts) {
        $cids[] = $this->createCacheIdFast($keys, (new CacheableMetadata())->setCacheContexts($contexts));
      }
    }
    catch (\Throwable $e) {
      // A learned token can outlive the module that provided it: this store
      // survives a cache rebuild and a module uninstall, and converting a token
      // whose service is gone throws. Only the mapping is unusable - the chain
      // walk below converts nothing but the tokens that are still in the cache.
      return NULL;
    }

    $this->prefetch($cids);

    // Every hop has to still be the redirect that was learned. Checking only
    // the destination would accept an entry orphaned by a reshaped chain.
    foreach ($shape as $i => $contexts) {
      $result = $this->fetch($cids[$i]);
      if (!$result || !($result->data instanceof CacheRedirect)) {
        return NULL;
      }
      if (!$this->sameContexts($result->data->getCacheContexts(), $contexts)) {
        return NULL;
      }
    }

    $result = $this->fetch($cids[count($shape)]);

    return $result && !($result->data instanceof CacheRedirect) ? $result : NULL;
  }

  /**
   * Reads several cache IDs into the memo in one round trip.
   *
   * @param string[] $cids
   *   The cache IDs to fetch.
   */
  protected function prefetch(array $cids): void {
    $wanted = [];
    foreach ($cids as $cid) {
      if (!array_key_exists($cid, $this->fetched)) {
        $wanted[$cid] = $cid;
      }
    }
    if (!$wanted) {
      return;
    }

    $lookup = array_values($wanted);
    $found = $this->cacheBackend->getMultiple($lookup);
    foreach ($wanted as $cid) {
      $this->fetched[$cid] = $found[$cid] ?? FALSE;
    }
  }

  /**
   * Records the shape of a redirect chain.
   *
   * @param array<string, object|false> $chain
   *   A redirect chain as returned by ::getRedirectChain().
   *
   * @return array<int, string[]>
   *   The cache contexts of every redirect in the chain, in order. The last one
   *   is what the data entry varies by; the ones before it are the hops that
   *   lead there, and they are what makes the mapping verifiable.
   */
  protected function chainShape(array $chain): array {
    $shape = [];
    foreach ($chain as $result) {
      if ($result && $result->data instanceof CacheRedirect) {
        $shape[] = $result->data->getCacheContexts();
      }
    }
    return $shape;
  }

  /**
   * Compares two lists of cache contexts regardless of order.
   *
   * @param string[] $a
   *   One list of cache contexts.
   * @param string[] $b
   *   The other one.
   *
   * @return bool
   *   TRUE when they hold the same tokens.
   */
  protected function sameContexts(array $a, array $b): bool {
    sort($a);
    sort($b);
    return $a === $b;
  }

  /**
   * Builds the value-independent key a mapping is memoized under.
   *
   * Deliberately built from the cache keys and the *unresolved* initial context
   * tokens, never from resolved context values, so one entry serves every user,
   * language and theme.
   *
   * @param string[] $keys
   *   The cache keys.
   * @param \Drupal\Core\Cache\CacheableDependencyInterface $initial_cacheability
   *   The pre-bubbling cacheable metadata.
   *
   * @return string
   *   The memoization key.
   */
  protected function getShortcutKey(array $keys, CacheableDependencyInterface $initial_cacheability): string {
    $contexts = $initial_cacheability->getCacheContexts();
    sort($contexts);
    return hash('xxh128', implode(':', $keys) . '|' . implode(',', $contexts));
  }

}

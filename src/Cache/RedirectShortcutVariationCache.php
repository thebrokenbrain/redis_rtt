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
 * Correctness is not taken on trust. The shortcut is only accepted when the
 * entry it lands on exists and is not itself a CacheRedirect; anything else
 * falls back to the full chain walk and re-learns the mapping. Because the
 * fallback reuses the reply already fetched by the shortcut, a wrong guess
 * costs no extra round trip - it just does not save one.
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

    if ($contexts = $this->store->get($shortcut_key)) {
      $cacheability = (new CacheableMetadata())->setCacheContexts($contexts);
      $result = $this->fetch($this->createCacheIdFast($keys, $cacheability));
      // Only trust the shortcut when it lands on real data. A miss or a
      // redirect means the mapping no longer describes reality.
      if ($result && !($result->data instanceof CacheRedirect)) {
        return $result;
      }
      $this->store->delete($shortcut_key);
    }

    $chain = $this->getRedirectChain($keys, $initial_cacheability);
    $result = end($chain);

    // Learn the mapping, but only from a chain that actually took a hop and
    // ended on data. A single-entry chain has nothing to shortcut.
    if (count($chain) > 1 && $result && !($result->data instanceof CacheRedirect)) {
      $this->store->set($shortcut_key, $this->finalContexts($chain));
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
   * Returns the cache contexts of the last redirect in a chain.
   *
   * @param array<string, object|false> $chain
   *   A redirect chain as returned by ::getRedirectChain().
   *
   * @return string[]
   *   The cache contexts that lead to the data entry.
   */
  protected function finalContexts(array $chain): array {
    $contexts = [];
    foreach ($chain as $result) {
      if ($result && $result->data instanceof CacheRedirect) {
        $contexts = $result->data->getCacheContexts();
      }
    }
    return $contexts;
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

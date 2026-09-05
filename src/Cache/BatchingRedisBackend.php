<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\redis\Cache\RedisBackend;
use Drupal\redis\ClientInterface;

/**
 * Redis cache backend tuned for high round-trip-cost topologies.
 *
 * Every difference from \Drupal\redis\Cache\RedisBackend is about fitting more
 * work into fewer network waits. Writes go to Redis exactly when the stock
 * backend sends them.
 *
 * 1. The "last delete all" marker is fetched inside the same pipeline as the
 *    first read of the bin instead of costing its own round trip. Stock
 *    behaviour spends one extra GET per bin per request the first time an entry
 *    of that bin is expanded.
 *
 * 2. ::invalidateMultiple() costs one round trip instead of two per cache ID.
 *    Stock behaviour issues a sequential HGET followed by a sequential HSET for
 *    every single cache ID, un-pipelined. This is hit hard by CacheCollector,
 *    which invalidates its cache entry on every ::set().
 *
 * 3. The cache tags of everything a read returns are handed to the checksum
 *    provider before any single entry is validated, so it can resolve them all
 *    in one MGET rather than one GET per tag.
 *
 * 4. The double-prefixing bug in the stock ::setMultiple() expired-item path is
 *    fixed (it passes an already-prefixed key to ::delete(), which prefixes it
 *    again and deletes a key that cannot exist).
 */
class BatchingRedisBackend extends RedisBackend {

  /**
   * Invalidates a cache entry only if it exists and is currently valid.
   *
   * Mirrors the stock backend's semantics exactly: HGET returns FALSE for a
   * missing key or missing field, and the stock PHP check treats the string
   * '0' as falsy, so an already-invalid entry is not rewritten.
   *
   * Declared with exactly one key so it stays correct if the deployment ever
   * moves to a cluster-mode Redis.
   */
  protected const INVALIDATE_LUA = <<<'LUA'
local v = redis.call('HGET', KEYS[1], 'valid')
if v and v ~= '0' and v ~= '' then
  redis.call('HSET', KEYS[1], 'valid', 0)
  return 1
end
return 0
LUA;

  public function __construct(
    string $bin,
    ClientInterface $client,
    CacheTagsChecksumInterface $checksum_provider,
    SerializationInterface $serializer,
  ) {
    parent::__construct($bin, $client, $checksum_provider, $serializer);
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $cids
   *   The cache IDs to fetch; those found are removed from the list.
   * @param bool $allow_invalid
   *   Whether to return entries invalidated by a cache tag.
   *
   * @return object[]
   *   The cache items, keyed by cache ID.
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    if (empty($cids)) {
      return [];
    }

    $return = [];
    $keys = [];
    foreach ($cids as $cid) {
      $keys[$cid] = $this->getKey($cid);
    }

    // Piggyback the "last delete all" marker on the read pipeline. The stock
    // backend fetches it lazily from ::expandEntry(), which costs a separate
    // round trip per bin.
    $needs_last_delete = $this->lastDeleteAll === NULL;

    $this->client->pipeline();
    foreach ($keys as $key) {
      $this->client->hgetall($key);
    }
    if ($needs_last_delete) {
      $this->client->get($this->getKey(static::LAST_DELETE_ALL_KEY));
    }
    $result = $this->client->exec() ?: [];

    if ($needs_last_delete) {
      // The marker is the last reply in the pipeline.
      $this->lastDeleteAll = (float) array_pop($result);
    }

    // Register every returned tag for preloading before validating any single
    // item, so the checksum provider can resolve them all in one MGET rather
    // than one MGET per item.
    if (method_exists($this->checksumProvider, 'registerCacheTagsForPreload')) {
      $tags_for_preload = [];
      foreach ($result as $item) {
        if (is_array($item) && !empty($item['tags'])) {
          $tags_for_preload[] = explode(' ', $item['tags']);
        }
      }
      if ($tags_for_preload) {
        $this->checksumProvider->registerCacheTagsForPreload(array_merge(...$tags_for_preload));
      }
    }

    foreach (array_values($result) as $values) {
      if (is_array($values) && ($item = $this->expandEntry($values, $allow_invalid))) {
        $return[$item->cid] = $item;
      }
    }

    $cids = array_diff($cids, array_keys($return));

    return $return;
  }

  /**
   * {@inheritdoc}
   *
   * Only here to route already-expired items through ::deleteMultiple() with
   * their raw cache IDs. The stock method hands it an already-prefixed key,
   * which ::deleteMultiple() prefixes a second time, so the entry it means to
   * remove is never touched and stays in Redis until it expires on its own.
   *
   * @param array<string, array{data: mixed, expire?: int, tags?: string[]}> $items
   *   The items to write, keyed by cache ID.
   */
  public function setMultiple(array $items): void {
    $expired = [];
    foreach ($items as $cid => $item) {
      $ttl = $this->getExpiration($item['expire'] ?? CacheBackendInterface::CACHE_PERMANENT);
      if ($ttl !== NULL && $ttl <= 0) {
        $expired[] = $cid;
        unset($items[$cid]);
      }
    }

    if ($expired) {
      $this->deleteMultiple($expired);
    }
    if ($items) {
      parent::setMultiple($items);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $cids
   *   The cache IDs to invalidate.
   */
  public function invalidateMultiple(array $cids): void {
    if (!$cids) {
      return;
    }

    // One round trip for the whole set, instead of a sequential HGET + HSET per
    // cache ID.
    $this->client->pipeline();
    foreach ($cids as $cid) {
      $this->client->eval(static::INVALIDATE_LUA, [$this->getKey($cid)], 1);
    }
    $this->client->exec();
  }

}

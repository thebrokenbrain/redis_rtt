<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\redis\Cache\RedisBackend;
use Drupal\redis_rtt\Redis\Pipeline;
use Drupal\redis_rtt\Redis\Scripting;

/**
 * Redis cache backend tuned for high round-trip-cost topologies.
 *
 * Every difference from \Drupal\redis\Cache\RedisBackend is about fitting more
 * work into fewer network waits.
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
 *
 *
 * Writes go to Redis at exactly the point the stock backend sends them. An
 * earlier version of this class held them in memory and sent them in batches,
 * which was the largest single saving it made and also the only part of it that
 * could be wrong: a queued write that another process deleted in the meantime
 * was recreated by the flush, because Redis cannot tell "deleted" from "never
 * existed". That was bounded to three bins by a list of four conditions and
 * never reproduced outside a deliberately misconfigured one - but it was the
 * only thing here that had to be reasoned about rather than simply read, and it
 * is gone. What it cost to remove is measured in README.md: nothing at all on a
 * warm page, and about two thirds of the saving on a cold one.
 */
class PipeliningRedisBackend extends RedisBackend {

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

    $keys = [];
    foreach ($cids as $cid) {
      $keys[$cid] = $this->getKey($cid);
    }

    $rows = [];

    // Piggyback the "last delete all" marker on the read pipeline. The stock
    // backend fetches it lazily from ::expandEntry(), which costs a separate
    // round trip per bin.
    $needs_last_delete = $this->lastDeleteAll === NULL;

    // Always at least one key here: an empty $cids returned above.
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

    foreach ($result as $values) {
      if (is_array($values)) {
        $rows[] = $values;
      }
    }

    // Register every returned tag for preloading before validating any single
    // item, so the checksum provider can resolve them all in one MGET rather
    // than one MGET per item.
    if (method_exists($this->checksumProvider, 'registerCacheTagsForPreload')) {
      $tags_for_preload = [];
      foreach ($rows as $values) {
        if (!empty($values['tags'])) {
          $tags_for_preload[] = explode(' ', $values['tags']);
        }
      }
      if ($tags_for_preload) {
        $this->checksumProvider->registerCacheTagsForPreload(array_merge(...$tags_for_preload));
      }
    }

    $return = [];
    foreach ($rows as $values) {
      if ($item = $this->expandEntry($values, $allow_invalid)) {
        $return[$item->cid] = $item;
      }
    }

    $cids = array_diff($cids, array_keys($return));

    return $return;
  }

  /**
   * {@inheritdoc}
   *
   * Two changes over the stock method. Already-expired items are routed through
   * ::deleteMultiple() with their raw cache IDs - the stock method hands it an
   * already-prefixed key, which ::deleteMultiple() prefixes a second time, so
   * the entry it means to remove is never touched and stays in Redis until it
   * expires on its own. And, for bins the batch handles, the entries are queued
   * instead of sent.
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
    if (!$items) {
      return;
    }

    parent::setMultiple($items);
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

    // Redis has already refused to run scripts on this connection, so do not
    // ask again: the inherited path sends what stock would have sent.
    if (Scripting::unavailable($this->client)) {
      parent::invalidateMultiple($cids);
      return;
    }

    // One round trip for the whole set, instead of a sequential HGET + HSET per
    // cache ID.
    try {
      $this->client->pipeline();
      foreach ($cids as $cid) {
        $this->client->eval(static::INVALIDATE_LUA, [$this->getKey($cid)], 1);
      }
      $this->client->exec();
    }
    catch (\Exception $e) {
      // A pipeline of scripts that times out mid-flight leaves the connection
      // reading the previous command's replies. See Pipeline::discard().
      Pipeline::discard($this->client);
      // A Redis with scripting switched off must not take the site down. Fall
      // back to what this class inherits, which is what stock does.
      if (Scripting::refuses($e)) {
        Scripting::markRefused();
        parent::invalidateMultiple($cids);
        return;
      }
      throw $e;
    }
  }

}

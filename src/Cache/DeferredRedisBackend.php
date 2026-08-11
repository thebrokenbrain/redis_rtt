<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;
use Drupal\redis_rtt\Redis\CommandBufferInterface;
use Drupal\redis\Cache\RedisBackend;
use Drupal\redis\ClientInterface;

/**
 * Redis cache backend tuned for high round-trip-cost topologies.
 *
 * Differences from \Drupal\redis\Cache\RedisBackend:
 *
 * 1. Writes are buffered in a process-wide
 *    \Drupal\redis_rtt\Redis\CommandBuffer and flushed as a single pipeline
 *    at the end of the request, across all bins. Stock behaviour is one
 *    pipeline per ::setMultiple() call per bin, and most Drupal code writes
 *    one item at a time.
 *
 * 2. The "last delete all" marker is fetched inside the same pipeline as the
 *    first read of the bin instead of costing its own round trip. Stock
 *    behaviour spends one extra GET per bin per request the first time an entry
 *    of that bin is expanded.
 *
 * 3. ::invalidateMultiple() costs one round trip instead of two per cache ID.
 *    Stock behaviour issues a sequential HGET followed by a sequential HSET for
 *    every single cache ID, un-pipelined. This is hit hard by CacheCollector,
 *    which invalidates its cache entry on every ::set().
 *
 * 4. Reads are served from the pending write buffer when possible, so
 *    read-your-own-writes within a request costs nothing.
 *
 * 5. The double-prefixing bug in the stock ::setMultiple() expired-item path is
 *    fixed (it passes an already-prefixed key to ::delete(), which prefixes it
 *    again and deletes a key that cannot exist).
 */
class DeferredRedisBackend extends RedisBackend {

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
    protected CommandBufferInterface $buffer,
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
    $fetch = [];
    $pending = [];

    // Serve anything still sitting in the write buffer without a round trip.
    foreach ($cids as $cid) {
      $key = $this->getKey($cid);
      $hash = $this->buffer->getPendingHash($key);
      if ($hash !== NULL) {
        $pending[$cid] = $hash;
      }
      else {
        $fetch[$cid] = $key;
      }
    }

    // Piggyback the "last delete all" marker on the read pipeline. The stock
    // backend fetches it lazily from ::expandEntry(), which costs a separate
    // round trip per bin.
    $needs_last_delete = $this->lastDeleteAll === NULL;

    $result = [];
    if ($fetch || $needs_last_delete) {
      $this->client->pipeline();
      foreach ($fetch as $key) {
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
      foreach ($pending as $item) {
        if (!empty($item['tags'])) {
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
    foreach ($pending as $values) {
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
   * @param array<string, array{data: mixed, expire?: int, tags?: string[]}> $items
   *   The items to write, keyed by cache ID.
   */
  public function setMultiple(array $items): void {
    if (!$this->buffer->isEnabled()) {
      parent::setMultiple($items);
      return;
    }

    $tags = [];
    // Always add a cache tag for the current bin, so that we can use that for
    // invalidateAll().
    if (Settings::get('redis_invalidate_all_as_delete', TRUE) === FALSE) {
      $tags[] = [$this->getTagForBin()];
    }

    $expired = [];
    foreach ($items as $cid => $item) {
      $item += [
        'expire' => CacheBackendInterface::CACHE_PERMANENT,
        'tags' => [],
      ];

      $item['ttl'] = $this->getExpiration($item['expire']);

      // If the item is already expired, delete it. Note that the stock backend
      // passes the prefixed key to ::delete(), which prefixes it a second time
      // and therefore deletes a key that cannot exist. Collect the raw cache
      // IDs instead and delete them in a single command.
      if (isset($item['ttl']) && $item['ttl'] <= 0) {
        $expired[] = $cid;
        unset($items[$cid]);
        continue;
      }

      if (!empty($item['tags'])) {
        assert(Inspector::assertAllStrings($item['tags']), 'Cache Tags must be strings.');
        $tags[] = $item['tags'];
      }

      $items[$cid] = $item;
    }

    if ($expired) {
      $this->deleteMultiple($expired);
    }

    if ($tags) {
      // Resolve every checksum in one go before building the entries.
      $this->checksumProvider->getCurrentChecksum(array_merge(...$tags));
    }

    foreach ($items as $cid => $item) {
      $this->buffer->queueWrite(
        $this->getKey($cid),
        $this->createEntryHash($cid, $item['data'], $item['expire'], $item['tags']),
        $item['ttl'],
      );
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $cids
   *   The cache IDs to delete.
   */
  public function deleteMultiple(array $cids): void {
    if (!$cids) {
      return;
    }
    // Drop buffered writes first so a pending write cannot resurrect a deleted
    // entry after the delete has already been sent.
    $this->buffer->dropWrites(array_map([$this, 'getKey'], $cids));
    parent::deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $cids
   *   The cache IDs to invalidate.
   */
  public function invalidateMultiple(array $cids): void {
    $keys = [];
    foreach ($cids as $cid) {
      $key = $this->getKey($cid);
      // If the entry has not been sent yet we can invalidate it in place.
      if (!$this->buffer->invalidatePending($key)) {
        $keys[] = $key;
      }
    }

    if (!$keys) {
      return;
    }

    // One round trip for the whole set, instead of a sequential HGET + HSET per
    // cache ID.
    $this->client->pipeline();
    foreach ($keys as $key) {
      $this->client->eval(static::INVALIDATE_LUA, [$key], 1);
    }
    $this->client->exec();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll(): void {
    $this->buffer->dropWritesByPrefix($this->getKey() . ':');
    parent::deleteAll();
  }

  /**
   * {@inheritdoc}
   *
   * @param bool $success
   *   Whether the transaction committed.
   */
  public function postRootTransactionCommit($success): void {
    if ($success && $this->delayedDeletions) {
      $this->buffer->dropWrites(array_map([$this, 'getKey'], $this->delayedDeletions));
    }
    parent::postRootTransactionCommit($success);
  }

}

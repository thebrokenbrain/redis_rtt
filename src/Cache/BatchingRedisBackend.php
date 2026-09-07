<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Component\Assertion\Inspector;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;
use Drupal\redis\Cache\RedisBackend;
use Drupal\redis\ClientInterface;
use Drupal\redis_rtt\Redis\WriteBatch;

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
 * 5. Writes of bins that are only invalidated through cache tags travel in
 *    batches rather than one round trip each. Which bins those are, why the
 *    rest are excluded, and what the residual risk is, are all in
 *    \Drupal\redis_rtt\Redis\WriteBatch. Without a batch service the backend
 *    writes exactly where the stock one does.
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

  /**
   * Constructs the backend.
   *
   * @param string $bin
   *   The cache bin.
   * @param \Drupal\redis\ClientInterface $client
   *   The Redis client.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksum_provider
   *   The cache tags checksum provider.
   * @param \Drupal\Component\Serialization\SerializationInterface $serializer
   *   The serializer.
   * @param \Drupal\redis_rtt\Redis\WriteBatch|null $batch
   *   (optional) The batch to hand writes to. Shared by every bin, so that a
   *   batch fills from whichever bins are writing and one pipeline carries all
   *   of them. NULL writes everything immediately.
   */
  public function __construct(
    string $bin,
    ClientInterface $client,
    CacheTagsChecksumInterface $checksum_provider,
    SerializationInterface $serializer,
    protected ?WriteBatch $batch = NULL,
  ) {
    parent::__construct($bin, $client, $checksum_provider, $serializer);
    if ($this->batch && !$this->batch->handles($bin)) {
      $this->batch = NULL;
    }
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

    $keys = [];
    foreach ($cids as $cid) {
      $keys[$cid] = $this->getKey($cid);
    }

    // Anything still waiting in the batch is served from there. Without this a
    // read of a key this request has just written would miss, which the stock
    // backend never does.
    $rows = [];
    if ($this->batch) {
      foreach ($keys as $cid => $key) {
        if (($hash = $this->batch->getPending($key)) !== NULL) {
          $rows[] = $hash;
          unset($keys[$cid]);
        }
      }
    }

    // Piggyback the "last delete all" marker on the read pipeline. The stock
    // backend fetches it lazily from ::expandEntry(), which costs a separate
    // round trip per bin.
    $needs_last_delete = $this->lastDeleteAll === NULL;

    if ($keys || $needs_last_delete) {
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

    if ($this->batch) {
      $this->queueMultiple($items);
    }
    else {
      parent::setMultiple($items);
    }
  }

  /**
   * Builds the entries exactly as the stock backend does, then queues them.
   *
   * The whole entry - creation stamp, cache tag checksum, compressed payload -
   * is built here and not at send time, so that waiting cannot change what ends
   * up in Redis. A batched write stores the state of the moment it was made.
   *
   * @param array<string, array{data: mixed, expire?: int, tags?: string[]}> $items
   *   The items to write, keyed by cache ID.
   */
  protected function queueMultiple(array $items): void {
    $tags = [];
    // Always add a cache tag for the current bin, so that it can be used for
    // invalidateAll().
    if (Settings::get('redis_invalidate_all_as_delete', TRUE) === FALSE) {
      $tags[] = [$this->getTagForBin()];
    }

    foreach ($items as $cid => $item) {
      $item += [
        'expire' => CacheBackendInterface::CACHE_PERMANENT,
        'tags' => [],
      ];
      if (!empty($item['tags'])) {
        assert(Inspector::assertAllStrings($item['tags']), 'Cache Tags must be strings.');
        $tags[] = $item['tags'];
      }
      $items[$cid] = $item;
    }

    // Resolve every tag in one go, before building any entry:
    // ::createEntryHash() asks the checksum provider per item, and the provider
    // only batches what it has been shown up front.
    if ($tags) {
      $this->checksumProvider->getCurrentChecksum(array_merge(...$tags));
    }

    foreach ($items as $cid => $item) {
      $this->batch->add(
        $this->getKey($cid),
        $this->createEntryHash($cid, $item['data'], $item['expire'], $item['tags']),
        $this->getExpiration($item['expire']),
      );
    }
  }

  /**
   * {@inheritdoc}
   *
   * Drops anything queued for these keys first, so that a flush cannot undo a
   * delete this same process has just made. Across processes it cannot: that is
   * the window \Drupal\redis_rtt\Redis\WriteBatch documents, and the reason its
   * bin list is what it is.
   *
   * The drop happens even inside a database transaction, where the stock
   * backend defers the deletion to the commit. Keeping the queued write would
   * risk writing back an entry that was meant to be gone, so it is dropped.
   *
   * What that costs is not "one cache miss", as this used to say. Measured: on
   * a rollback the entry left in Redis is the one from *before* the
   * transaction, served as a valid HIT, not a miss - the queued write that
   * would have replaced it is gone. Stock, which never sent the DEL, is left
   * holding the new value instead. Neither is the state the transaction
   * intended and both self-heal on the next tag invalidation; the direction
   * they are wrong in is opposite, and for a value derived from a database row
   * it is this backend that agrees with the database and stock that does not.
   *
   * @param string[] $cids
   *   The cache IDs to delete.
   */
  public function deleteMultiple(array $cids): void {
    if ($this->batch && $cids) {
      $this->batch->drop(array_map([$this, 'getKey'], $cids));
    }
    parent::deleteMultiple($cids);
  }

  /**
   * {@inheritdoc}
   *
   * Queued writes of this bin are dropped rather than sent. They would be
   * ignored on read anyway - their creation stamp predates the marker this
   * writes - but leaving them would fill Redis with entries nothing can read.
   */
  public function deleteAll(): void {
    // getKey() with no argument returns the bin prefix without its separator,
    // which would also match a bin whose name merely starts with this one.
    $this->batch?->dropByPrefix($this->getKey() . ':');
    parent::deleteAll();
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
    try {
      $this->client->pipeline();
      foreach ($cids as $cid) {
        $key = $this->getKey($cid);
        // A queued entry is invalidated in place as well as in Redis: in place
        // so that this request reads it as invalid, and in Redis because an
        // earlier version of the same key may already be stored there.
        $this->batch?->invalidatePending($key);
        $this->client->eval(static::INVALIDATE_LUA, [$key], 1);
      }
      $this->client->exec();
    }
    catch (\Exception $e) {
      // A pipeline of scripts that times out mid-flight leaves the connection
      // reading the previous command's replies. See WriteBatch::discard().
      WriteBatch::discard($this->client);
      throw $e;
    }
  }

}

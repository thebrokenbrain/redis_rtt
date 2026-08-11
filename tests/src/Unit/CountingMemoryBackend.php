<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Cache\CacheBackendInterface;

/**
 * An in-memory cache backend that counts reads.
 *
 * Core's MemoryBackend would do for storage, but the tests need to assert on
 * the number of ::get() calls - the whole subject being how many reads a hit
 * costs - and it does not expose that.
 */
final class CountingMemoryBackend implements CacheBackendInterface {

  /**
   * Stored items, keyed by cache ID.
   *
   * @var array<string, object>
   */
  private array $items = [];

  /**
   * Number of ::get() calls, including those inside ::getMultiple().
   */
  public int $gets = 0;

  /**
   * Resets the counters, keeping the stored items.
   */
  public function resetCounters(): void {
    $this->gets = 0;
  }

  /**
   * Deletes every item whose cache ID matches a predicate.
   *
   * @param callable $predicate
   *   Receives the cache ID, returns TRUE to delete.
   */
  public function deleteWhere(callable $predicate): void {
    foreach (array_keys($this->items) as $cid) {
      if ($predicate($cid)) {
        unset($this->items[$cid]);
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param string $cid
   *   The cache ID.
   * @param bool $allow_invalid
   *   Whether to return an invalidated entry.
   *
   * @return object|false
   *   The item, or FALSE on a miss.
   */
  public function get($cid, $allow_invalid = FALSE) {
    $this->gets++;
    $item = $this->items[$cid] ?? FALSE;
    if ($item && !$allow_invalid && !$item->valid) {
      return FALSE;
    }
    return $item;
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $cids
   *   The cache IDs; found ones are removed.
   * @param bool $allow_invalid
   *   Whether to return invalidated entries.
   *
   * @return object[]
   *   The items, keyed by cache ID.
   */
  public function getMultiple(&$cids, $allow_invalid = FALSE) {
    $found = [];
    foreach ($cids as $cid) {
      if ($item = $this->get($cid, $allow_invalid)) {
        $found[$cid] = $item;
      }
    }
    $cids = array_diff($cids, array_keys($found));
    return $found;
  }

  /**
   * {@inheritdoc}
   *
   * @param string $cid
   *   The cache ID.
   * @param mixed $data
   *   The data to store.
   * @param int $expire
   *   The expiry timestamp, or Cache::PERMANENT.
   * @param string[] $tags
   *   The cache tags.
   */
  public function set($cid, $data, $expire = CacheBackendInterface::CACHE_PERMANENT, array $tags = []): void {
    $this->items[$cid] = (object) [
      'cid' => $cid,
      'data' => $data,
      'created' => 1000000.0,
      'expire' => $expire,
      'tags' => $tags,
      'valid' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, array{data: mixed, expire?: int, tags?: string[]}> $items
   *   The items to write.
   */
  public function setMultiple(array $items): void {
    foreach ($items as $cid => $item) {
      $this->set($cid, $item['data'], $item['expire'] ?? CacheBackendInterface::CACHE_PERMANENT, $item['tags'] ?? []);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($cid): void {
    unset($this->items[$cid]);
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $cids
   *   The cache IDs.
   */
  public function deleteMultiple(array $cids): void {
    foreach ($cids as $cid) {
      $this->delete($cid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll(): void {
    $this->items = [];
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate($cid): void {
    if (isset($this->items[$cid])) {
      $this->items[$cid]->valid = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $cids
   *   The cache IDs.
   */
  public function invalidateMultiple(array $cids): void {
    foreach ($cids as $cid) {
      $this->invalidate($cid);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateAll(): void {
    foreach ($this->items as $item) {
      $item->valid = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection(): void {}

  /**
   * {@inheritdoc}
   */
  public function removeBin(): void {
    $this->items = [];
  }

}

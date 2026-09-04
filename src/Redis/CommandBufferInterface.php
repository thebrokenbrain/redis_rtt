<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Redis;

/**
 * Collects cache writes so they can be sent as one pipeline.
 *
 * Implementations are process-wide and shared by every cache bin, which is the
 * point: the saving comes from collapsing the writes of *all* bins into a
 * single network round trip, not from batching within one bin.
 *
 * @see \Drupal\redis_rtt\Redis\CommandBuffer
 * @see \Drupal\redis_rtt\Redis\NullCommandBuffer
 */
interface CommandBufferInterface {

  /**
   * Returns whether writes are actually buffered.
   *
   * A backend seeing FALSE must write synchronously instead of queueing.
   *
   * @return bool
   *   TRUE if ::queueWrite() may be called.
   */
  public function isEnabled(): bool;

  /**
   * Queues a cache hash write.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   * @param array<string, mixed> $hash
   *   The hash fields as built by the cache backend. Built eagerly by the
   *   caller, including the cache tag checksum, so that deferring the send
   *   cannot change what gets stored.
   * @param int|null $ttl
   *   The TTL in seconds, or NULL for no expiry.
   */
  public function queueWrite(string $key, array $hash, ?int $ttl): void;

  /**
   * Records which marker key describes a bin that is buffering writes.
   *
   * A bin can go a whole process without core handing a marker over: on
   * Drupal 11.2 ChainedFastBackend::markAsOutdated() skips the write while the
   * published stamp is still in the future. The write is still buffered, so it
   * still has to be announced, and that needs the key.
   *
   * @param string $prefix
   *   The key prefix of the bin.
   * @param string $marker_key
   *   The fully prefixed Redis key of that bin's chained-fast marker.
   */
  public function registerBin(string $prefix, string $marker_key): void;

  /**
   * Queues a chained-fast "last write timestamp" marker.
   *
   * @param string $key
   *   The fully prefixed Redis key of the marker.
   * @param float $value
   *   The timestamp the caller wants published, treated as a lower bound.
   * @param string $prefix
   *   The key prefix of the bin this marker describes.
   */
  public function queueMarker(string $key, float $value, string $prefix): void;

  /**
   * Returns a pending write, if any.
   *
   * Lets a backend answer a read from the buffer, so read-your-own-writes
   * within a request costs no round trip.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   *
   * @return array<string, mixed>|null
   *   The pending hash, or NULL if the key is not buffered.
   */
  public function getPendingHash(string $key): ?array;

  /**
   * Drops pending writes for the given keys.
   *
   * Must be called before a delete reaches Redis, so a buffered write cannot
   * resurrect an entry this process deleted. The guarantee stops at the process
   * boundary: a delete issued elsewhere during the buffer window is not seen.
   *
   * @param string[] $keys
   *   Fully prefixed Redis keys.
   */
  public function dropWrites(array $keys): void;

  /**
   * Drops every pending write whose key starts with the given prefix.
   *
   * Used when a whole bin is wiped.
   *
   * @param string $prefix
   *   The fully prefixed bin key prefix.
   */
  public function dropWritesByPrefix(string $prefix): void;

  /**
   * Marks a pending write as invalid without a round trip.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   *
   * @return bool
   *   TRUE if the key was pending and has been invalidated in place; FALSE if
   *   the caller still has to invalidate it in Redis.
   */
  public function invalidatePending(string $key): bool;

  /**
   * Sends every pending write.
   *
   * Called automatically at end of request; callers only need it when they must
   * guarantee the writes have landed before doing something else.
   */
  public function flush(): void;

  /**
   * Returns instrumentation counters for the current request.
   *
   * @return array{pipelines: int, pending: int, deduped: int}
   *   Counters describing what the buffer did.
   */
  public function getStats(): array;

}

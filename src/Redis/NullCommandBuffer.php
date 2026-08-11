<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Redis;

/**
 * A buffer that never buffers, for bins that must be written synchronously.
 *
 * Reporting itself as disabled makes the cache backend fall through to the
 * stock synchronous write path, while the delete and invalidate paths keep
 * their (side-effect free) bookkeeping calls and need no special-casing.
 *
 * Used for the container bin: the compiled service container must be readable
 * by the next request the moment it is written.
 */
class NullCommandBuffer implements CommandBufferInterface {

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function queueWrite(string $key, array $hash, ?int $ttl): void {
    throw new \LogicException('NullCommandBuffer must never receive writes; check isEnabled() first.');
  }

  /**
   * {@inheritdoc}
   */
  public function getPendingHash(string $key): ?array {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function dropWrites(array $keys): void {}

  /**
   * {@inheritdoc}
   */
  public function dropWritesByPrefix(string $prefix): void {}

  /**
   * {@inheritdoc}
   */
  public function invalidatePending(string $key): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function flush(): void {}

  /**
   * {@inheritdoc}
   */
  public function getStats(): array {
    return ['pipelines' => 0, 'pending' => 0, 'deduped' => 0];
  }

}

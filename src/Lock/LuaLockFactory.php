<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Lock;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\redis\ClientFactory;

/**
 * Builds LuaRedisLock instances.
 *
 * Drop-in replacement for the redis.lock.factory service.
 */
class LuaLockFactory {

  public function __construct(protected ClientFactory $clientFactory) {}

  /**
   * Returns a lock backend.
   *
   * @param bool $persistent
   *   Whether to return a persistent lock implementation.
   */
  public function get($persistent = FALSE): LockBackendInterface {
    return new LuaRedisLock($this->clientFactory, $persistent);
  }

}

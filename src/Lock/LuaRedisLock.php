<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Lock;

use Drupal\redis\Lock\RedisLock;

/**
 * Redis lock backend that uses one round trip per operation.
 *
 * \Drupal\redis\Lock\RedisLock implements the compare-and-act parts of the lock
 * protocol with WATCH / GET / MULTI / EXEC. That is three sequential round
 * trips for a release, and three more for re-acquiring (extending) a lock the
 * process already holds. Locks sit on the critical path of every
 * \Drupal\Core\Cache\CacheCollector write - state, menu active trail, theme
 * registry, library discovery, path alias whitelist - so those round trips add
 * up quickly on a cross-AZ primary.
 *
 * Both operations are compare-and-swap, which is exactly what a Lua script does
 * atomically in a single round trip. This is the canonical Redis locking
 * pattern and is strictly safer than WATCH/MULTI here: a persistent connection
 * that dies between WATCH and EXEC leaves a dangling watch on a connection that
 * is subsequently reused by another request.
 */
class LuaRedisLock extends RedisLock {

  /**
   * Deletes the key only if this process still owns it.
   */
  protected const RELEASE_LUA = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  return redis.call('DEL', KEYS[1])
end
return 0
LUA;

  /**
   * Extends the expiry only if this process still owns the lock.
   */
  protected const EXTEND_LUA = <<<'LUA'
if redis.call('GET', KEYS[1]) == ARGV[1] then
  redis.call('PEXPIRE', KEYS[1], ARGV[2])
  return 1
end
return 0
LUA;

  /**
   * {@inheritdoc}
   */
  public function acquire($name, $timeout = 30.0) {
    // Insure that the timeout is at least 1 ms.
    $timeout = max($timeout, 0.001);
    $key = $this->getKey($name);
    $id = $this->getLockId();

    if (isset($this->locks[$name])) {
      // Extend a lock we believe we hold: one round trip instead of three.
      $extended = $this->client->eval(static::EXTEND_LUA, [$key, $id, (int) ($timeout * 1000)], 1);
      if (!$extended) {
        unset($this->locks[$name]);
        return FALSE;
      }
      return TRUE;
    }

    // A plain SET NX PX is already a single round trip.
    if ($this->client->set($key, $id, ['nx', 'px' => (int) ($timeout * 1000)]) === FALSE) {
      return FALSE;
    }

    return ($this->locks[$name] = TRUE);
  }

  /**
   * {@inheritdoc}
   *
   * @param string $name
   *   The lock name.
   */
  public function release($name): void {
    unset($this->locks[$name]);
    // One round trip instead of WATCH + GET + MULTI/DEL/EXEC.
    $this->client->eval(static::RELEASE_LUA, [$this->getKey($name), $this->getLockId()], 1);
  }

  /**
   * {@inheritdoc}
   *
   * @param string|null $lock_id
   *   (optional) The lock owner id; defaults to this request's.
   */
  public function releaseAll($lock_id = NULL): void {
    if (!$this->locks) {
      return;
    }
    $names = array_keys($this->locks);
    $this->locks = [];
    $id = $lock_id ?: $this->getLockId();

    // Every held lock released in a single round trip. Each EVAL declares
    // exactly one key, so this stays correct under cluster mode too.
    $this->client->pipeline();
    foreach ($names as $name) {
      $this->client->eval(static::RELEASE_LUA, [$this->getKey($name), $id], 1);
    }
    $this->client->exec();
  }

}

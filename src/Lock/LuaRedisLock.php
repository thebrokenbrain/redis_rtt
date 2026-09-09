<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Lock;

use Drupal\redis\Lock\RedisLock;
use Drupal\redis_rtt\Redis\Pipeline;
use Drupal\redis_rtt\Redis\Scripting;

/**
 * Redis lock backend that uses one round trip per operation.
 *
 * \Drupal\redis\Lock\RedisLock implements the compare-and-act parts of the lock
 * protocol with WATCH / GET / MULTI / EXEC. That is three sequential round
 * trips for a release, and three more for re-acquiring (extending) a lock the
 * process already holds. Locks sit on the critical path of every
 * \Drupal\Core\Cache\CacheCollector write - state, menu active trail, theme
 * registry, library discovery, path alias prefixes - so those round trips add
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
   * Whether ::release() must go straight to the inherited path.
   *
   * Set only while ::releaseAllInherited() is walking the locks, so that a
   * decision already taken for the batch is not re-taken, and re-paid, once per
   * lock. @see ::releaseAllInherited()
   */
  protected bool $skipScripts = FALSE;

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
    if (Scripting::unavailable($this->client)) {
      return parent::acquire($name, $timeout);
    }
    // Insure that the timeout is at least 1 ms.
    $timeout = max($timeout, 0.001);
    $key = $this->getKey($name);
    $id = $this->getLockId();

    if (isset($this->locks[$name])) {
      // Extend a lock we believe we hold: one round trip instead of three.
      try {
        Scripting::clearError($this->client);
        $extended = $this->client->eval(static::EXTEND_LUA, [$key, $id, (int) ($timeout * 1000)], 1);
      }
      catch (\Exception $e) {
        if (!Scripting::refuses($e)) {
          throw $e;
        }
        Scripting::markRefused();
        return parent::acquire($name, $timeout);
      }
      // EXTEND_LUA answers 1 or 0 and never FALSE, so a FALSE is Redis
      // rejecting the command rather than this process having lost the lock.
      // Reading it as the latter - which is what happens when FALSE and 0 are
      // treated alike - made the process drop a lock it still held, and let
      // nobody else take it either, because the key was never touched.
      if (Scripting::failedReply($extended)) {
        if (Scripting::refusedReply($this->client, $extended)) {
          Scripting::markRefused();
        }
        return parent::acquire($name, $timeout);
      }
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
    if ($this->skipScripts || Scripting::unavailable($this->client)) {
      parent::release($name);
      return;
    }
    unset($this->locks[$name]);
    // One round trip instead of WATCH + GET + MULTI/DEL/EXEC.
    try {
      Scripting::clearError($this->client);
      $released = $this->client->eval(static::RELEASE_LUA, [$this->getKey($name), $this->getLockId()], 1);
    }
    catch (\Exception $e) {
      if (!Scripting::refuses($e)) {
        throw $e;
      }
      Scripting::markRefused();
      parent::release($name);
      return;
    }
    // RELEASE_LUA answers 0 or the result of DEL, so a FALSE means the key was
    // never looked at. Left alone, the lock stays held until its PX expires and
    // blocks every other process for that long.
    if (Scripting::failedReply($released)) {
      if (Scripting::refusedReply($this->client, $released)) {
        Scripting::markRefused();
      }
      parent::release($name);
    }
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
    $held = $this->locks;
    if (Scripting::unavailable($this->client)) {
      $this->releaseAllInherited($held, $lock_id);
      return;
    }
    $names = array_keys($this->locks);
    $this->locks = [];
    $id = $lock_id ?: $this->getLockId();

    // Every held lock released in a single round trip. Each EVAL declares
    // exactly one key, so this stays correct under cluster mode too.
    try {
      Scripting::clearError($this->client);
      $this->client->pipeline();
      foreach ($names as $name) {
        $this->client->eval(static::RELEASE_LUA, [$this->getKey($name), $id], 1);
      }
      $replies = $this->client->exec();
    }
    catch (\Exception $e) {
      // A pipeline of scripts that times out mid-flight leaves the connection
      // reading the previous command's replies. See Pipeline::discard().
      Pipeline::discard($this->client);
      if (Scripting::refuses($e)) {
        Scripting::markRefused();
        $this->releaseAllInherited($held, $lock_id);
        return;
      }
      throw $e;
    }

    // And the same rejection arriving as a reply rather than as an exception,
    // which is the usual way. Every lock this process holds would otherwise
    // stay held until its PX ran out.
    if (Scripting::failedReply($replies)) {
      if (Scripting::refusedReply($this->client, $replies)) {
        Scripting::markRefused();
      }
      $this->releaseAllInherited($held, $lock_id);
    }
  }

  /**
   * Releases every held lock without Lua, deciding what the script decides.
   *
   * Two differences from handing straight over to the inherited method, and
   * both of them matter now that the fallback is reached by more Redis
   * configurations than it used to be.
   *
   * ::RELEASE_LUA deletes a key only when its value matches the id it was
   * given, so releaseAll() with somebody else's id frees nothing this process
   * holds - which is what \Drupal\Tests\redis_rtt\Kernel\LuaRedisLockTest
   * asserts, and a reasonable thing for a lock backend to promise.
   * \Drupal\redis\Lock\RedisLock::releaseAll() ignores the parameter
   * completely and releases this process's own locks whatever it is passed, so
   * delegating left the same call, with the same argument, doing opposite
   * things depending on whether the Redis in front of it would run a script.
   *
   * And the inherited method returns early on an empty list, so what the caller
   * emptied has to be put back before handing over.
   *
   * @param array<string, bool> $held
   *   The locks this backend held before the attempt.
   * @param string|null $lock_id
   *   The owner id the caller asked for, or NULL for this process's own.
   */
  protected function releaseAllInherited(array $held, $lock_id): void {
    $this->locks = $held;

    if ($lock_id !== NULL && $lock_id !== $this->getLockId()) {
      // A caller with somebody else's id frees nothing we hold. The script
      // path forgets them anyway, so this one does too, rather than have the
      // two disagree about that.
      $this->locks = [];
      return;
    }

    // ::releaseAll() walks the locks calling ::release(), which is this class's
    // own. With nothing remembered - the path taken when a script failed for a
    // reason this module does not recognise - each of those would send a fresh
    // EVAL before falling back in its turn: for three locks, six failed scripts
    // and thirteen round trips instead of one. The answer is already known
    // here, so it is not asked again.
    $this->skipScripts = TRUE;
    try {
      parent::releaseAll($lock_id);
    }
    finally {
      $this->skipScripts = FALSE;
    }
  }

}

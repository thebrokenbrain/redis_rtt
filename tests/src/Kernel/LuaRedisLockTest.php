<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\Core\Lock\LockTest;
use Drupal\redis_rtt\Lock\LuaRedisLock;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Runs core's lock contract against this module's lock backend.
 *
 * WHY THIS EXISTS.
 *
 * It did not, and that was the largest hole in the suite. \Drupal\redis_rtt\Lock\LuaRedisLock
 * replaces the stock WATCH/GET/MULTI/EXEC protocol with Lua compare-and-swap,
 * and nothing asserted on the result: six deliberate mutations of the lock -
 * including one that made ::release() delete a lock held by anyone, and one that
 * dropped the NX from ::acquire() so two processes could hold it at once - left
 * the whole suite green.
 *
 * The stand-in in tests/src/Unit does not help here. It recognises the cache
 * scripts and reads the deciding line out of them, but it does not run Lua, so
 * a lock script can be arbitrarily wrong and still "work" against it. That is
 * why this is a kernel test against a real Redis, like the one the redis module
 * ships for its own lock.
 *
 * Everything inherited from \Drupal\KernelTests\Core\Lock\LockTest applies as
 * written; what this class adds is the ownership half of the protocol, which is
 * where a compare-and-swap can be wrong without core's contract noticing.
 *
 * Skips itself when no Redis is reachable. A skip is a pass that proves nothing,
 * which is why the CI job for this module brings a Redis with it.
 *
 * @group redis_rtt
 */
class LuaRedisLockTest extends LockTest {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'redis', 'redis_rtt'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $this->applyRedisSettings();
    parent::register($container);

    $container->register('lock', LockBackendInterface::class)
      ->setFactory([new Reference('redis_rtt.lock.factory'), 'get']);
  }

  /**
   * Points the redis module at the Redis this test can reach, and skips if none.
   */
  protected function applyRedisSettings(): void {
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);

    $socket = @fsockopen($host, $port, $errno, $error, 1);
    if ($socket === FALSE) {
      $this->markTestSkipped("No Redis reachable at $host:$port. Set REDIS_HOST and REDIS_PORT to run this.");
    }
    fclose($socket);

    $settings = Settings::getAll();
    $settings['redis.connection']['interface'] = getenv('REDIS_INTERFACE') ?: 'PhpRedis';
    $settings['redis.connection']['host'] = $host;
    $settings['redis.connection']['port'] = $port;
    new Settings($settings);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->lock = $this->container->get('lock');
  }

  /**
   * {@inheritdoc}
   */
  public function testBackendLockRelease(): void {
    // Asked of the container rather than of $this->lock: the parent declares
    // that property as core's database backend, so a static analyser reads the
    // assertion as always false.
    $this->assertInstanceOf(LuaRedisLock::class, $this->container->get('lock'), 'The factory must build this module\'s lock.');

    // A lock that has never been acquired is available.
    // @see \Drupal\Tests\redis\Kernel\RedisLockTest, which does the same.
    $this->assertTrue($this->lock->lockMayBeAvailable('lock_a'));

    parent::testBackendLockRelease();
  }

  /**
   * A second holder cannot take a lock that is held.
   *
   * This is the NX in ::acquire(). Without it two processes hold the same lock
   * and whatever it protects is unprotected; with the stand-in alone, removing
   * it changed nothing anywhere in the suite.
   */
  public function testAcquireIsExclusiveAcrossHolders(): void {
    $other = $this->newLockBackend();

    $this->assertTrue($this->lock->acquire('exclusive', 3600));
    $this->assertFalse($other->acquire('exclusive', 3600), 'A second holder must not be able to acquire a held lock.');
    $this->assertFalse($other->lockMayBeAvailable('exclusive'));

    $this->lock->release('exclusive');
    $this->assertTrue($other->acquire('exclusive', 3600), 'Once released, another holder may take it.');
    $other->release('exclusive');
  }

  /**
   * Releasing a lock held by somebody else does nothing.
   *
   * The compare half of the compare-and-swap in ::RELEASE_LUA. A release that
   * skipped the GET would delete any lock by name, which is how one request
   * silently frees the lock another is relying on.
   */
  public function testReleaseDoesNotFreeAnotherHoldersLock(): void {
    $owner = $this->newLockBackend();
    $this->assertTrue($owner->acquire('not_yours', 3600));

    // A different backend instance means a different lock id, which is exactly
    // what a different request is.
    $this->lock->release('not_yours');

    $this->assertFalse($this->lock->acquire('not_yours', 3600), 'The lock must still be held by its owner.');
    $this->assertFalse($owner->lockMayBeAvailable('not_yours'), 'And its owner must still hold it.');

    $owner->release('not_yours');
    $this->assertTrue($this->lock->lockMayBeAvailable('not_yours'));
  }

  /**
   * ReleaseAll() with somebody else's id does not free this holder's locks.
   *
   * Same compare, through the pipelined path, which is a separate branch.
   */
  public function testReleaseAllWithForeignIdFreesNothing(): void {
    $owner = $this->newLockBackend();
    $this->assertTrue($owner->acquire('mine_a', 3600));
    $this->assertTrue($owner->acquire('mine_b', 3600));

    $owner->releaseAll('an-id-that-is-not-ours');

    $this->assertFalse($this->lock->acquire('mine_a', 3600), 'A foreign id must not release mine_a.');
    $this->assertFalse($this->lock->acquire('mine_b', 3600), 'A foreign id must not release mine_b.');

    // Cleaned up through the owner, which is the only holder that can.
    $owner->releaseAll();
  }

  /**
   * Re-acquiring a lock this process holds extends it rather than failing.
   *
   * ::EXTEND_LUA. Core's contract calls acquire() twice in a row and expects
   * TRUE, but it does not check that the expiry actually moved, so an extend
   * that quietly did nothing would pass.
   */
  public function testAcquireExtendsLockThisProcessHolds(): void {
    $this->assertTrue($this->lock->acquire('extend_me', 1));
    $this->assertTrue($this->lock->acquire('extend_me', 3600), 'Re-acquiring a held lock must extend it.');

    // Had the extend not happened, the one-second lock would be gone by now and
    // another holder could take it.
    sleep(2);
    $other = $this->newLockBackend();
    $this->assertFalse($other->acquire('extend_me', 3600), 'The extended lock must outlive its original expiry.');

    $this->lock->release('extend_me');
  }

  /**
   * Extending a lock this process has lost reports failure.
   *
   * The other half of ::EXTEND_LUA: when the key has expired and somebody else
   * has taken it, acquire() must return FALSE rather than reporting success on
   * a lock the process no longer owns.
   */
  public function testExtendingLostLockFails(): void {
    $this->assertTrue($this->lock->acquire('lost', 1));

    // Let it expire, then let another holder take it.
    sleep(2);
    $other = $this->newLockBackend();
    $this->assertTrue($other->acquire('lost', 3600));

    $this->assertFalse($this->lock->acquire('lost', 3600), 'A lock taken by somebody else must not be extendable.');

    $other->release('lost');
  }

  /**
   * Builds a second lock backend, which has a lock id of its own.
   *
   * @return \Drupal\Core\Lock\LockBackendInterface
   *   A backend that stands in for another request.
   */
  protected function newLockBackend(): LockBackendInterface {
    return $this->container->get('redis_rtt.lock.factory')->get();
  }

}

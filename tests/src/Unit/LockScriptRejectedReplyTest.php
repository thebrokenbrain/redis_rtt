<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\redis\ClientFactory as RedisClientFactory;
use Drupal\redis_rtt\Lock\LuaRedisLock;
use Drupal\redis_rtt\Redis\Scripting;

/**
 * Tests the lock when Redis rejects a script by answering FALSE.
 *
 * The lock scripts cannot answer FALSE on their own: RELEASE_LUA returns 0 or
 * the result of a DEL, and EXTEND_LUA returns 1 or 0. So a FALSE is always
 * Redis rejecting the command, and reading it as a script result is how a
 * release stopped releasing and an extension made the process believe it had
 * lost a lock it still held - with the key untouched in Redis, so nobody else
 * could take it either, for as long as its PX lasted.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Lock\LuaRedisLock
 * @group redis_rtt
 */
class LockScriptRejectedReplyTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Scripting::reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Scripting::reset();
    parent::tearDown();
  }

  /**
   * Builds a lock over a client whose scripts answer FALSE.
   *
   * @param \Drupal\redis\ClientInterface $client
   *   The connection.
   *
   * @return \Drupal\redis_rtt\Lock\LuaRedisLock
   *   The lock backend, with a prefix set.
   */
  protected function lock($client): LuaRedisLock {
    $factory = $this->createMock(RedisClientFactory::class);
    $factory->method('getClient')->willReturn($client);
    $lock = new LuaRedisLock($factory, TRUE);
    $lock->setPrefix('p');

    return $lock;
  }

  /**
   * A release whose script answered FALSE still deletes the key.
   *
   * @covers ::release
   */
  public function testReleaseFallsBackWhenTheScriptAnswersFalse(): void {
    $inner = new FakeRedisClient();
    $client = new ScriptFailingClient($inner);
    $lock = $this->lock($client);

    $this->assertTrue($lock->acquire('trabajo', 30), 'Taken with a plain SET NX.');
    $this->assertFalse($lock->lockMayBeAvailable('trabajo'), 'And held.');

    $lock->release('trabajo');

    $this->assertSame(1, $client->scriptAttempts, 'The script was tried once.');
    $this->assertTrue(
      $lock->lockMayBeAvailable('trabajo'),
      'And the lock is free: a rejected script must not leave it held until its PX.'
    );
  }

  /**
   * Releasing every lock at once has the same reply path.
   *
   * @covers ::releaseAll
   */
  public function testReleaseAllFallsBackWhenTheScriptAnswersFalse(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $lock = $this->lock($client);

    $lock->acquire('uno', 30);
    $lock->acquire('dos', 30);
    $this->assertFalse($lock->lockMayBeAvailable('uno'));

    $lock->releaseAll();

    $this->assertTrue($lock->lockMayBeAvailable('uno'), 'Both are free.');
    $this->assertTrue($lock->lockMayBeAvailable('dos'), 'Both are free.');
  }

  /**
   * An extension whose script answered FALSE does not drop the lock.
   *
   * The old code treated FALSE and 0 alike, so a rejected EXTEND made this
   * return FALSE and forget a lock it still owned.
   *
   * @covers ::acquire
   */
  public function testExtendKeepsTheLockWhenTheScriptAnswersFalse(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $lock = $this->lock($client);

    $this->assertTrue($lock->acquire('trabajo', 30), 'Taken.');

    $this->assertTrue(
      $lock->acquire('trabajo', 30),
      'Extending a lock this process holds must succeed through the inherited path.'
    );
    $this->assertFalse($lock->lockMayBeAvailable('trabajo'), 'And it is still held.');
  }

  /**
   * The control: with the scripts running, none of this changes.
   *
   * @covers ::release
   * @covers ::acquire
   */
  public function testTheScriptPathIsUnchanged(): void {
    $client = new FakeRedisClient();
    $lock = $this->lock($client);

    $this->assertTrue($lock->acquire('trabajo', 30));
    $this->assertTrue($lock->acquire('trabajo', 30), 'Extended.');
    $this->assertFalse($lock->lockMayBeAvailable('trabajo'), 'Still held.');

    $lock->release('trabajo');

    $this->assertTrue($lock->lockMayBeAvailable('trabajo'), 'And released.');
    $this->assertFalse(Scripting::unavailable($client), 'Nothing was refused.');
  }

  /**
   * A recognised refusal from the lock is remembered for the request.
   *
   * @covers ::release
   */
  public function testRecognisedRefusalIsRemembered(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $lock = $this->lock($client);

    $lock->acquire('uno', 30);
    $lock->release('uno');
    $this->assertTrue(Scripting::unavailable($client), 'Remembered.');

    $lock->acquire('dos', 30);
    $lock->release('dos');

    $this->assertSame(1, $client->scriptAttempts, 'So the second release sends no script.');
    $this->assertTrue($lock->lockMayBeAvailable('dos'), 'And still releases.');
  }

}

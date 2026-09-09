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

  /**
   * A foreign id frees nothing, with the script and without it.
   *
   * The script deletes a key only when its value matches the id it was given.
   * The inherited method ignores the parameter and frees this process's own
   * locks whatever it is passed, so delegating straight to it made the same
   * call with the same argument do opposite things depending on whether the
   * Redis in front would run a script. There is a kernel test that asserts the
   * promise against a real Redis; this one pins the path it cannot reach.
   *
   * @covers ::releaseAll
   * @covers ::releaseAllInherited
   */
  public function testForeignIdFreesNothingThroughTheFallback(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $lock = $this->lock($client);

    $lock->acquire('uno', 3600);
    $lock->acquire('dos', 3600);

    $lock->releaseAll('un-id-que-no-es-nuestro');

    $this->assertFalse($lock->lockMayBeAvailable('uno'), 'A foreign id must not free uno.');
    $this->assertFalse($lock->lockMayBeAvailable('dos'), 'A foreign id must not free dos.');
  }

  /**
   * The control: our own id, or none at all, does free them.
   *
   * Without this, refusing to free anything at all would pass the test above.
   *
   * @covers ::releaseAll
   * @covers ::releaseAllInherited
   */
  public function testOwnIdStillFreesThemThroughTheFallback(): void {
    foreach ([NULL, 'propio'] as $case) {
      $client = new ScriptFailingClient(new FakeRedisClient());
      $lock = $this->lock($client);
      $lock->acquire('uno', 3600);

      $lock->releaseAll($case === 'propio' ? $lock->getLockId() : NULL);

      $this->assertTrue(
        $lock->lockMayBeAvailable('uno'),
        'Our own id must still release what we hold.'
      );
    }
  }

  /**
   * And the same promise on the path that never sends a script at all.
   *
   * Scripting::unavailable() returns early, which is a third way into the
   * inherited method and had the same divergence.
   *
   * @covers ::releaseAll
   */
  public function testForeignIdFreesNothingWhenScriptingIsKnownUnavailable(): void {
    $client = new ScriptFailingClient(new FakeRedisClient(), 'ERR unknown command \'EVAL\'', 'Predis');
    $lock = $this->lock($client);
    $lock->acquire('uno', 3600);
    $this->assertTrue(Scripting::unavailable($client), 'This client is never sent scripts.');

    $lock->releaseAll('un-id-que-no-es-nuestro');

    $this->assertFalse($lock->lockMayBeAvailable('uno'), 'Still not theirs to free.');
  }

  /**
   * Once a refusal is remembered, acquire() stops sending scripts too.
   *
   * The guard at the top of each method is what turns one refusal into no
   * further attempts for the rest of the request. release() had a test that
   * pinned it, by counting attempts; acquire() and releaseAll() did not, so
   * either guard could be deleted with the suite still green.
   *
   * @covers ::acquire
   */
  public function testAcquireStopsSendingScriptsOnceRefused(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $lock = $this->lock($client);

    $lock->acquire('uno', 3600);
    $lock->release('uno');
    $this->assertSame(1, $client->scriptAttempts, 'The release tried once.');
    $this->assertTrue(Scripting::unavailable($client), 'And it was remembered.');

    // A plain acquire is a SET NX and sends no script either way, so the one
    // that would is the extension of a lock this process already holds.
    $lock->acquire('dos', 3600);
    $lock->acquire('dos', 3600);

    $this->assertSame(1, $client->scriptAttempts, 'No script after the refusal.');
    $this->assertFalse($lock->lockMayBeAvailable('dos'), 'And the lock is held.');
  }

  /**
   * The same guard on releaseAll().
   *
   * @covers ::releaseAll
   */
  public function testReleaseAllStopsSendingScriptsOnceRefused(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $lock = $this->lock($client);

    $lock->acquire('uno', 3600);
    $lock->release('uno');
    $this->assertSame(1, $client->scriptAttempts);

    $lock->acquire('dos', 3600);
    $lock->acquire('tres', 3600);
    $lock->releaseAll();

    $this->assertSame(1, $client->scriptAttempts, 'No script after the refusal.');
    $this->assertTrue($lock->lockMayBeAvailable('dos'), 'And both were released.');
    $this->assertTrue($lock->lockMayBeAvailable('tres'), 'And both were released.');
  }

  /**
   * A refusal that arrives as an exception makes acquire() delegate.
   *
   * The reply path and the exception path are separate branches, and only the
   * first had a test: the second could be made to return FALSE instead of
   * handing over, and the process would report having lost a lock it holds.
   *
   * @covers ::acquire
   */
  public function testExtendDelegatesWhenTheScriptThrowsRefusal(): void {
    $client = new ScriptRefusingClient(new FakeRedisClient());
    $lock = $this->lock($client);

    $this->assertTrue($lock->acquire('trabajo', 3600), 'Taken with a plain SET NX.');

    $this->assertTrue(
      $lock->acquire('trabajo', 3600),
      'A thrown refusal must hand over to the inherited path, not report a lost lock.'
    );
    $this->assertTrue(Scripting::unavailable($client), 'And be remembered.');
    $this->assertFalse($lock->lockMayBeAvailable('trabajo'), 'The lock is still held.');
  }

  /**
   * An exception that is not a refusal keeps propagating out of acquire().
   *
   * Swallowing it would turn a bug in this module - or a Redis in trouble -
   * into a silent slow path.
   *
   * @covers ::acquire
   */
  public function testExtendRethrowsWhatIsNotRefusal(): void {
    $client = new ScriptRefusingClient(
      new FakeRedisClient(),
      'ScriptRefusing',
      'READONLY You can\'t write against a read only replica'
    );
    $lock = $this->lock($client);
    $this->assertTrue($lock->acquire('trabajo', 3600));

    $this->expectException(\RedisException::class);
    $this->expectExceptionMessage('READONLY');

    $lock->acquire('trabajo', 3600);
  }

  /**
   * Releasing everything after an unrecognised failure sends one script, not N.
   *
   * ::releaseAll() falls back by walking the locks through ::release(), which
   * is this class's own. On the path where nothing is remembered - a script
   * that failed for a reason this module does not recognise, deliberately not
   * treated as "Redis refuses scripts" - each of those walked releases used to
   * send a fresh EVAL before falling back in its turn: six failed scripts for
   * three locks, and thirteen round trips where there had been one.
   *
   * @covers ::releaseAll
   * @covers ::releaseAllInherited
   */
  public function testReleaseAllRetriesNoScriptPerLock(): void {
    $client = new ScriptFailingClient(
      new FakeRedisClient(),
      'WRONGTYPE Operation against a key holding the wrong kind of value'
    );
    $lock = $this->lock($client);
    $lock->acquire('uno', 3600);
    $lock->acquire('dos', 3600);
    $lock->acquire('tres', 3600);

    $lock->releaseAll();

    $this->assertFalse(Scripting::unavailable($client), 'Nothing was remembered, as intended.');
    // Three: the pipeline sends one script per lock, which is the whole point
    // of it - three scripts in one round trip. What must not happen is a second
    // three on the way back out, one per lock, each in its own round trip.
    $this->assertSame(
      3,
      $client->scriptAttempts,
      'The batch tries once per lock; the fallback must not try again.'
    );
    foreach (['uno', 'dos', 'tres'] as $name) {
      $this->assertTrue($lock->lockMayBeAvailable($name), "And $name was released.");
    }
  }

  /**
   * The status report can tell an operator that scripting was refused.
   *
   * A degraded module serves 200s and looks exactly like a healthy one from
   * outside, so without this there is nowhere at all to find out.
   *
   * @covers \Drupal\redis_rtt\Redis\Scripting::wasRefused
   */
  public function testRefusalIsVisibleToTheStatusReport(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $lock = $this->lock($client);
    $this->assertFalse(Scripting::wasRefused(), 'Nothing has happened yet.');

    $lock->acquire('uno', 3600);
    $lock->release('uno');

    $this->assertTrue(Scripting::wasRefused(), 'And now there is something to report.');
  }

  /**
   * The lock empties the error slot before each script too.
   *
   * Same reasoning as the cache backend: a correct command does not clear the
   * slot, measured on phpredis and on Relay, so an error from earlier in the
   * request survives until something empties it. Read as this script's own, it
   * turns an unrelated failure into "Redis refuses scripts" for the rest of the
   * request.
   *
   * @covers ::release
   */
  public function testReleaseClearsTheSlotBeforeSendingItsScript(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $client->seedLastError("NOPERM User default has no permissions to run the 'eval' command");
    $client->recordsReason = FALSE;
    $lock = $this->lock($client);
    $lock->acquire('uno', 3600);

    $lock->release('uno');

    $this->assertFalse(
      Scripting::unavailable($client),
      'A stale error must not be read as this script being refused.'
    );
    $this->assertTrue($lock->lockMayBeAvailable('uno'), 'And the lock was released anyway.');
  }

  /**
   * And so does releasing everything at once.
   *
   * @covers ::releaseAll
   */
  public function testReleaseAllClearsTheSlotBeforeSendingItsScripts(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $client->seedLastError("NOPERM User default has no permissions to run the 'eval' command");
    $client->recordsReason = FALSE;
    $lock = $this->lock($client);
    $lock->acquire('uno', 3600);
    $lock->acquire('dos', 3600);

    $lock->releaseAll();

    $this->assertFalse(Scripting::unavailable($client), 'Nothing refused anything.');
    $this->assertTrue($lock->lockMayBeAvailable('uno'), 'And both were released.');
    $this->assertTrue($lock->lockMayBeAvailable('dos'), 'And both were released.');
  }

  /**
   * And extending a lock this process holds.
   *
   * @covers ::acquire
   */
  public function testAcquireClearsTheSlotBeforeSendingItsScript(): void {
    $client = new ScriptFailingClient(new FakeRedisClient());
    $client->seedLastError("NOPERM User default has no permissions to run the 'eval' command");
    $client->recordsReason = FALSE;
    $lock = $this->lock($client);
    $lock->acquire('uno', 3600);

    $this->assertTrue($lock->acquire('uno', 3600), 'The extension goes through.');
    $this->assertFalse(
      Scripting::unavailable($client),
      'A stale error must not be read as this script being refused.'
    );
  }

}

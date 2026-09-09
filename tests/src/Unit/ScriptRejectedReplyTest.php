<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\PipeliningRedisBackend;
use Drupal\redis_rtt\Redis\Scripting;

/**
 * Tests the rejections that arrive as a return value instead of an exception.
 *
 * Round 14 measured that this is most of them. phpredis raises for a short
 * family - NOPERM, OOM, READONLY, BUSY, AUTH - and hands back the whole -ERR
 * class as FALSE with ::getLastError() set, raising nothing. So a Redis started
 * with `rename-command EVAL ""` answered `ERR unknown command 'EVAL'`, the
 * catch block never ran, and the module carried on as if the script had worked:
 * entries stayed valid after an invalidation, lock keys stayed in Redis after a
 * release, and every page still said 200. Relay behaves the same way, and
 * additionally does not raise for an ACL refusal inside a pipeline.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Redis\Scripting
 * @group redis_rtt
 */
class ScriptRejectedReplyTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Scripting::reset();

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000000);
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(FALSE);

    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    $container->set('database', $database);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    Scripting::reset();
    parent::tearDown();
  }

  /**
   * Builds a backend over a client whose scripts answer FALSE.
   *
   * @param string $error
   *   What Redis leaves in the last-error slot.
   *
   * @return array{\Drupal\redis_rtt\Cache\PipeliningRedisBackend, \Drupal\Tests\redis_rtt\Unit\ScriptFailingClient}
   *   The backend and the client, so the test can count script attempts.
   */
  protected function backend(string $error = "ERR unknown command 'EVAL', with args beginning with: "): array {
    $client = new ScriptFailingClient(new FakeRedisClient(), $error);
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $checksum->method('isValid')->willReturn(TRUE);
    $backend = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $backend->setPrefix('p');

    return [$backend, $client];
  }

  /**
   * A FALSE anywhere in a reply set means the script did not run.
   *
   * @covers ::failedReply
   */
  public function testFailedReplyFindsTheFalse(): void {
    $this->assertTrue(Scripting::failedReply(FALSE), 'A bare FALSE.');
    $this->assertTrue(Scripting::failedReply([FALSE]), 'One in a reply set.');
    $this->assertTrue(Scripting::failedReply([1, 1, FALSE, 1]), 'One among many.');

    $this->assertFalse(Scripting::failedReply(1), 'A script that ran and said 1.');
    $this->assertFalse(Scripting::failedReply(0), 'A script that ran and said 0.');
    $this->assertFalse(Scripting::failedReply([0, 0]), 'A reply set of zeroes.');
    $this->assertFalse(Scripting::failedReply([]), 'An empty pipeline.');
    $this->assertFalse(Scripting::failedReply(NULL), 'Not a reply this can judge.');
  }

  /**
   * Only a reply whose error names a refusal counts as one.
   *
   * @covers ::refusedReply
   */
  public function testOnlyRefusalRepliesCount(): void {
    $refusals = [
      "ERR unknown command 'EVAL', with args beginning with: ",
      'NOPERM User default has no permissions to run the \'eval\' command',
      'ERR This instance has scripting disabled',
    ];
    foreach ($refusals as $error) {
      [, $client] = $this->backend();
      $client->seedLastError($error);
      $this->assertTrue(
        Scripting::refusedReply($client, FALSE),
        "Should fall back on: $error"
      );
    }

    $others = [
      'ERR Error compiling script (new function): user_script:1: unexpected symbol',
      'WRONGTYPE Operation against a key holding the wrong kind of value',
      'OOM command not allowed when used memory > \'maxmemory\'',
    ];
    foreach ($others as $error) {
      [, $client] = $this->backend();
      $client->seedLastError($error);
      $this->assertFalse(
        Scripting::refusedReply($client, FALSE),
        "Should not be remembered as a refusal: $error"
      );
    }

    // And a reply that did not fail is never a refusal, whatever the slot says.
    [, $client] = $this->backend();
    $client->seedLastError('NOPERM User default has no permissions to run the \'eval\' command');
    $this->assertFalse(Scripting::refusedReply($client, [0, 1]), 'Nothing failed.');
  }

  /**
   * An invalidation whose script answered FALSE still invalidates.
   *
   * @covers ::failedReply
   * @covers ::refusedReply
   */
  public function testInvalidationFallsBackWhenTheScriptAnswersFalse(): void {
    [$backend, $client] = $this->backend();
    $backend->set('uno', 'V1');

    $backend->invalidateMultiple(['uno']);

    $this->assertSame(1, $client->scriptAttempts, 'The script is tried once.');
    $item = $backend->get('uno', TRUE);
    $this->assertNotFalse($item, 'The entry is still there: invalidation is not deletion.');
    $this->assertFalse((bool) $item->valid, 'And it is invalid, which is what was asked for.');
    $this->assertFalse($backend->get('uno'), 'An ordinary read no longer sees it.');
  }

  /**
   * A recognised refusal is remembered; the script is not tried again.
   *
   * @covers ::refusedReply
   * @covers ::unavailable
   */
  public function testRecognisedRefusalIsRemembered(): void {
    [$backend, $client] = $this->backend();
    $backend->set('uno', 'V1');
    $backend->set('dos', 'V2');

    $backend->invalidateMultiple(['uno']);
    $backend->invalidateMultiple(['dos']);

    $this->assertSame(1, $client->scriptAttempts, 'Remembered, not re-tested per call.');
    $this->assertTrue(Scripting::unavailable($client), 'And the flag is set.');
    $this->assertFalse($backend->get('dos'), 'The second invalidation worked too.');
  }

  /**
   * An unrecognised failure still invalidates, but is not remembered.
   *
   * The work has to happen either way, because losing it silently is the whole
   * defect. But a script failing on its own merits is a bug in this module, and
   * switching Lua off for the rest of the request would hide it.
   *
   * @covers ::failedReply
   * @covers ::refusedReply
   */
  public function testAnUnrecognisedFailureFallsBackWithoutRemembering(): void {
    [$backend, $client] = $this->backend('WRONGTYPE Operation against a key holding the wrong kind of value');
    $backend->set('uno', 'V1');
    $backend->set('dos', 'V2');

    $backend->invalidateMultiple(['uno']);
    $this->assertFalse($backend->get('uno'), 'The entry was invalidated anyway.');
    $this->assertFalse(Scripting::unavailable($client), 'But nothing was remembered.');

    $backend->invalidateMultiple(['dos']);
    $this->assertSame(2, $client->scriptAttempts, 'So the script is tried again.');
    $this->assertFalse($backend->get('dos'), 'And that one was invalidated too.');
  }

  /**
   * The control: a client whose scripts work is not sent down the fallback.
   *
   * @covers ::failedReply
   */
  public function testNothingChangesWhenTheScriptRuns(): void {
    $client = new FakeRedisClient();
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $checksum->method('isValid')->willReturn(TRUE);
    $backend = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $backend->setPrefix('p');
    $backend->set('uno', 'V1');

    $backend->invalidateMultiple(['uno']);

    $this->assertFalse(Scripting::unavailable($client), 'Nothing refused anything.');
    $this->assertFalse($backend->get('uno'), 'And the entry is invalid.');
  }

  /**
   * A stale error from an earlier command is not read as a refusal.
   *
   * @covers ::clearError
   */
  public function testStaleErrorIsNotMistakenForRefusal(): void {
    [$backend, $client] = $this->backend();
    $backend->set('uno', 'V1');

    // Something else left a refusal-shaped message in the slot, and then a
    // script ran perfectly well.
    $client->__call('clearLastError', []);
    $this->assertNull($client->__call('getLastError', []), 'Cleared.');

    $backend->invalidateMultiple(['uno']);
    $this->assertTrue(Scripting::unavailable($client), 'This one really was refused.');

    // And the slot is emptied before each attempt, so the next connection does
    // not inherit this one's answer.
    Scripting::reset();
    Scripting::clearError($client);
    $this->assertFalse(
      Scripting::refusedReply($client, FALSE),
      'With the slot empty there is no refusal to find.'
    );
  }

  /**
   * A message that names a script but is not a refusal keeps propagating.
   *
   * ::isRefusal() has two halves: the message must name eval or scripting, and
   * it must carry one of four refusal markers. Every message any test had ever
   * given it failed the first half, so the second one had never run - the four
   * markers could all be deleted, or the loop replaced by `return TRUE`, with
   * the suite green. These are the messages that reach it: real Redis 7 answers
   * to an EVAL that failed on its own merits.
   *
   * @covers ::refuses
   * @covers ::refusedReply
   */
  public function testScriptErrorNamingEvalIsNotRefusal(): void {
    $notRefusals = [
      "ERR Error compiling script (new function): user_script:1: '=' expected near 'eval'",
      'ERR Error running script (call to f_1): @user_script:1: eval blocked',
      "BUSY Redis is busy running a script. You can only call SCRIPT KILL or SHUTDOWN NOSAVE. Sent by EVAL",
    ];
    foreach ($notRefusals as $message) {
      $this->assertFalse(
        Scripting::refuses(new \RedisException($message)),
        "Names a script but refuses nothing, so it must keep propagating: $message"
      );
      [, $client] = $this->backend();
      $client->seedLastError($message);
      $this->assertFalse(
        Scripting::refusedReply($client, FALSE),
        "And the same through the reply path: $message"
      );
    }

    // The control: the same first half, with a marker, is a refusal.
    $this->assertTrue(
      Scripting::refuses(new \RedisException("ERR unknown command 'EVAL'")),
      'A marker is what makes the difference, and it still does.'
    );
  }

  /**
   * A reply set longer than what was queued is not whole either.
   *
   * The count check is not redundant with the two conditions beside it. A
   * pipeline whose queue was dragged in from earlier answers with more replies
   * than were asked for, and array_pop() would then take one of those as the
   * flush marker: a timestamp read out of somebody else\'s answer.
   *
   * @covers \Drupal\redis_rtt\Cache\PipeliningRedisBackend::getMultiple
   */
  public function testLongerReplySetIsNotTreatedAsWhole(): void {
    $client = new OverlongReplyClient(new FakeRedisClient());
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $checksum->method('isValid')->willReturn(TRUE);

    $writer = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $writer->setPrefix('p');
    $writer->set('uno', 'V1');
    $flusher = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $flusher->setPrefix('p');
    $flusher->deleteAll();

    $reader = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $reader->setPrefix('p');
    $client->overlong = TRUE;
    $cids = ['uno'];
    $reader->getMultiple($cids);

    $this->assertFalse(
      $reader->get('uno'),
      'A flush that really happened must stay honoured, not be overwritten by a stray reply.'
    );
  }

  /**
   * A reply set shifted by one does not yield a flush marker either.
   *
   * This is the shape a desynchronised socket actually produces - same length,
   * contents one position late - and the count check cannot see it. What
   * refuses it is that every reply but the marker is a hash, so the shift puts
   * an array where the timestamp belongs and is_scalar() throws it out.
   *
   * @covers \Drupal\redis_rtt\Cache\PipeliningRedisBackend::getMultiple
   */
  public function testShiftedReplySetYieldsNoMarker(): void {
    $client = new ShiftedReplyClient(new FakeRedisClient());
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $checksum->method('isValid')->willReturn(TRUE);
    $construir = static function () use ($client, $checksum): PipeliningRedisBackend {
      $b = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
      $b->setPrefix('p');
      return $b;
    };

    $construir()->set('uno', 'V1');
    $construir()->deleteAll();

    $reader = $construir();
    $client->shifted = TRUE;
    $cids = ['uno'];
    $reader->getMultiple($cids);

    $this->assertFalse(
      $reader->get('uno'),
      'A flush that really happened must stay honoured after a shifted read.'
    );
  }

  /**
   * The error slot is emptied before each script, by the code and not by hand.
   *
   * ::refusedReply() is only trustworthy if the slot was cleared just before
   * the script went out - a correct command does not clear it, measured on
   * phpredis and on Relay, so an error from earlier in the request survives
   * indefinitely. Every call site could drop that call with the suite green,
   * because the only test that pinned it called ::clearError() itself.
   *
   * @covers \Drupal\redis_rtt\Cache\PipeliningRedisBackend::invalidateMultiple
   */
  public function testInvalidateClearsTheSlotBeforeSendingItsScript(): void {
    [$backend, $client] = $this->backend();
    $backend->set('uno', 'V1');

    // Something unrelated left a refusal-shaped error behind. Then this script
    // fails for its own reason and leaves the slot alone, which is what a
    // connection-state FALSE from ::exec() does. Without the clear, the stale
    // message is what gets read, and the module switches Lua off for the rest
    // of the request over something that never refused anything.
    $client->seedLastError("NOPERM User default has no permissions to run the 'eval' command");
    $client->recordsReason = FALSE;

    $backend->invalidateMultiple(['uno']);

    $this->assertFalse(
      Scripting::unavailable($client),
      'A stale error must not be read as this script being refused.'
    );
    $this->assertFalse($backend->get('uno'), 'And the entry was invalidated anyway.');
  }

}

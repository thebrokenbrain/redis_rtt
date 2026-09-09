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

}

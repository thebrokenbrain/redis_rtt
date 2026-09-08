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
 * Tests that a Redis which refuses Lua does not take the site down.
 *
 * Round 13 found this the hard way: with scripting removed from the default
 * ACL - which several managed Redis providers do - every page became a 500 and
 * nothing was invalidated either. No setting turned the EVAL off and the only
 * way back was editing settings.php.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Redis\Scripting
 * @group redis_rtt
 */
class ScriptingFallbackTest extends UnitTestCase {

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
   * Builds a backend over a client that refuses scripts.
   *
   * @return array{\Drupal\redis_rtt\Cache\PipeliningRedisBackend, \Drupal\Tests\redis_rtt\Unit\ScriptRefusingClient}
   *   The backend and the client, so the test can count script attempts.
   */
  protected function backend(): array {
    $client = new ScriptRefusingClient(new FakeRedisClient());
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $checksum->method('isValid')->willReturn(TRUE);
    $backend = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $backend->setPrefix('p');

    return [$backend, $client];
  }

  /**
   * Only a refusal to run scripts counts as one.
   *
   * A script that fails on its own merits is a bug in this module, and quietly
   * downgrading it to the inherited path would hide it.
   *
   * @covers ::refuses
   */
  public function testOnlyScriptingRefusalsCount(): void {
    $refusals = [
      "NOPERM User default has no permissions to run the 'eval' command",
      'ERR unknown command "EVAL"',
      'ERR This instance has scripting disabled',
    ];
    foreach ($refusals as $message) {
      $this->assertTrue(
        Scripting::refuses(new \RedisException($message)),
        "Should fall back on: $message"
      );
    }

    $others = [
      'ERR Error compiling script (new function): user_script:1: unexpected symbol',
      'WRONGTYPE Operation against a key holding the wrong kind of value',
      "NOPERM User default has no permissions to run the 'hset' command",
      'READONLY You can\'t write against a read only replica',
    ];
    foreach ($others as $message) {
      $this->assertFalse(
        Scripting::refuses(new \RedisException($message)),
        "Should keep propagating: $message"
      );
    }
  }

  /**
   * A refused invalidation still invalidates, through the inherited path.
   *
   * @covers ::unavailable
   * @covers ::markRefused
   */
  public function testInvalidationFallsBackAndStillInvalidates(): void {
    [$backend, $client] = $this->backend();
    $backend->set('uno', 'V1');

    $backend->invalidateMultiple(['uno']);

    $this->assertSame(1, $client->scriptAttempts, 'The script is tried once before giving up.');
    $item = $backend->get('uno', TRUE);
    $this->assertNotFalse($item, 'The entry is still there: invalidation is not deletion.');
    $this->assertFalse((bool) $item->valid, 'The entry is invalid, which is what was asked for.');
    $this->assertFalse($backend->get('uno'), 'And an ordinary read no longer sees it.');
  }

  /**
   * Once refused, the script is not attempted again this process.
   *
   * @covers ::unavailable
   */
  public function testTheScriptIsNotRetriedOnceRefused(): void {
    [$backend, $client] = $this->backend();
    $backend->set('uno', 'V1');
    $backend->set('dos', 'V2');

    $backend->invalidateMultiple(['uno']);
    $backend->invalidateMultiple(['dos']);

    $this->assertSame(1, $client->scriptAttempts, 'A refusal is remembered, not re-tested per call.');
    $this->assertFalse($backend->get('dos'), 'The second invalidation worked too.');
  }

  /**
   * With scripting available nothing changes: the script still runs.
   *
   * @covers ::unavailable
   */
  public function testTheScriptIsUsedWhenRedisAllowsIt(): void {
    $client = new FakeRedisClient();
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $checksum->method('isValid')->willReturn(TRUE);
    $backend = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $backend->setPrefix('p');
    $backend->set('uno', 'V1');

    $backend->invalidateMultiple(['uno']);

    $this->assertFalse(Scripting::unavailable($client), 'Nothing refused anything.');
    $this->assertFalse($backend->get('uno'));
  }

  /**
   * Predis never gets a script, because its eval() takes different arguments.
   *
   * Learned the expensive way: sending phpredis's shape to Predis puts an array
   * where the key count goes, and every page 500s. There is nothing to catch
   * there - the error does not read as a refusal - so it has to be decided
   * before the call.
   *
   * @covers ::unavailable
   */
  public function testPredisIsNeverSentScripts(): void {
    $client = new ScriptRefusingClient(new FakeRedisClient(), 'Predis');
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $checksum->method('isValid')->willReturn(TRUE);
    $backend = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $backend->setPrefix('p');
    $backend->set('uno', 'V1');

    $this->assertTrue(Scripting::unavailable($client));

    $backend->invalidateMultiple(['uno']);

    $this->assertSame(0, $client->scriptAttempts, 'Not even once.');
    $this->assertFalse($backend->get('uno'), 'And it invalidated anyway.');
  }

  /**
   * The module's own client is not mistaken for Predis.
   *
   * "PhpRedisRtt" contains "pRedis", so a substring search matched it and
   * turned Lua off for everybody - which is to say, turned the module off. The
   * check is anchored now, and this pins it.
   *
   * @covers ::unavailable
   */
  public function testOwnClientIsNotMistakenForPredis(): void {
    $inner = new FakeRedisClient();
    foreach (['PhpRedisRtt', 'PhpRedisRtt (instrumented)', 'PhpRedis', 'Relay'] as $name) {
      $this->assertFalse(
        Scripting::unavailable(new ScriptRefusingClient($inner, $name)),
        "$name must still be sent scripts."
      );
    }
    foreach (['Predis', 'Predis (instrumented)'] as $name) {
      $this->assertTrue(
        Scripting::unavailable(new ScriptRefusingClient($inner, $name)),
        "$name must not be sent scripts."
      );
    }
  }

}

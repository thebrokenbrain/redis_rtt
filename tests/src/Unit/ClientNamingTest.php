<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\redis\ClientInterface;
use Drupal\redis_rtt\Client\CountingClient;
use Drupal\redis_rtt\Client\PhpRedisRtt;
use Drupal\redis_rtt\Client\PhpRedisRttFactory;

/**
 * The connection has to be identifiable in the redis module's reports.
 *
 * \Drupal\redis\ClientFactory::getClientName() asks the *client* for its name,
 * not the factory that built it, and every place the redis module reports the
 * connection goes through that: the status report, /admin/reports/redis and
 * `drush redis:info`.
 *
 * Before this was fixed, a connection established by PhpRedisRttFactory - with
 * a read timeout configured, keepalive on and TLS where asked for - announced
 * itself as plain "PhpRedis". Those reports are the only place an operator can
 * confirm which settings are in force, so one that names the wrong client
 * invites someone to "fix" a configuration that was already correct.
 *
 * The client is a phpredis client, so naming it needs a \Redis to hand it -
 * even a mocked one. Without the extension there is no class to mock, and the
 * test has nothing to say rather than something to fail about.
 *
 * @group redis_rtt
 * @requires extension redis
 */
class ClientNamingTest extends UnitTestCase {

  /**
   * The fast client identifies itself, not its parent.
   *
   * @covers \Drupal\redis_rtt\Client\PhpRedisRtt::getName
   */
  public function testFastClientReportsItsOwnName(): void {
    $client = new PhpRedisRtt($this->createMock(\Redis::class));

    $this->assertSame('PhpRedisRtt', $client->getName());
    // There used to be an assertInstanceOf(PhpRedis::class) here. It cannot
    // fail: "class PhpRedisRtt extends PhpRedis" is in the source, PHPStan
    // proves it, and every static restatement of it - is_subclass_of() with
    // literals included - is a tautology the analyser reports as one. Silencing
    // that would be hiding a dead assertion rather than keeping a live guard,
    // so it is gone. What the inheritance actually buys - the read timeout and
    // the credentials on the connection - is covered where it can fail, in
    // \Drupal\Tests\redis_rtt\Kernel\PhpRedisRttConnectionTest.
  }

  /**
   * The factory hands back a client that can be identified.
   *
   * Guards the actual regression: the factory used to return the parent's
   * client class, which reported the wrong name however the factory was named.
   *
   * @covers \Drupal\redis_rtt\Client\PhpRedisRttFactory::getName
   */
  public function testFactoryAndClientAgree(): void {
    $factory = new PhpRedisRttFactory();

    $this->assertSame('PhpRedisRtt', $factory->getName());
    $this->assertSame(
      $factory->getName(),
      (new PhpRedisRtt($this->createMock(\Redis::class)))->getName(),
      'The name the factory is selected by must be the name the client reports.',
    );
  }

  /**
   * Instrumentation is visible in the reported name.
   *
   * It is not free and should not be left on fleet-wide, so anything that names
   * the client should say when it is wrapped in a counter.
   *
   * @covers \Drupal\redis_rtt\Client\CountingClient::getName
   */
  public function testInstrumentationIsVisible(): void {
    $inner = new PhpRedisRtt($this->createMock(\Redis::class));

    $this->assertSame('PhpRedisRtt (instrumented)', (new CountingClient($inner))->getName());
  }

  /**
   * The count_commands setting wraps the client; its absence leaves it alone.
   *
   * Nothing checked this. The wrapper is the instrument every round-trip figure
   * in the README and the guide was measured with, so an instrument that
   * quietly stopped instrumenting would not have made a test fail - it would
   * have made every published number wrong.
   *
   * @covers \Drupal\redis_rtt\Client\PhpRedisRttFactory::instrument
   */
  public function testCountCommandsWrapsTheClient(): void {
    $factory = new PhpRedisRttFactory();
    $instrument = (new \ReflectionObject($factory))->getMethod('instrument');
    $instrument->setAccessible(TRUE);
    $inner = new PhpRedisRtt($this->createMock(\Redis::class));

    $plain = $instrument->invoke($factory, $inner, []);
    $this->assertSame($inner, $plain, 'Without the setting, nothing is wrapped.');

    $counted = $instrument->invoke($factory, $inner, ['count_commands' => TRUE]);
    $this->assertNotSame($inner, $counted, 'With it, the client is wrapped.');
    $this->assertInstanceOf(CountingClient::class, $counted);
    $this->assertSame('PhpRedisRtt (instrumented)', $counted->getName());
  }

  /**
   * The factory actually calls the wrapper on the way out.
   *
   * ::instrument() had a test, by reflection, and its one call site had none:
   * deleting `$this->instrument(...)` from ::getClient() left the suite green
   * while count_commands quietly stopped counting. That is the instrument every
   * published round-trip figure was measured with, so a broken wiring would not
   * have failed a test, it would have made every number wrong.
   *
   * @covers \Drupal\redis_rtt\Client\PhpRedisRttFactory::getClient
   */
  public function testGetClientPutsTheCounterInPlace(): void {
    $factory = new RecordingConnectFactory();

    $plain = $factory->getClient(['host' => '127.0.0.1', 'port' => 6379]);
    $this->assertNotInstanceOf(CountingClient::class, $plain, 'Off by default.');

    $counted = $factory->getClient(['host' => '127.0.0.1', 'port' => 6379, 'count_commands' => TRUE]);
    $this->assertInstanceOf(
      CountingClient::class,
      $counted,
      'With count_commands on, what comes out of the factory has to be the counter.'
    );
  }

}

/**
 * A factory that connects to nothing, so ::getClient() can be called at all.
 */
class RecordingConnectFactory extends PhpRedisRttFactory {

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $settings
   *   The connection settings.
   *
   * @return \Drupal\redis\ClientInterface
   *   A client over a mock socket.
   */
  protected function connect(#[\SensitiveParameter] array $settings): ClientInterface {
    return new PhpRedisRtt(new \Redis());
  }

}

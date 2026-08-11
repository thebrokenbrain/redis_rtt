<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Client\CountingClient;
use Drupal\redis_rtt\Client\FastPhpRedis;
use Drupal\redis_rtt\Client\FastPhpRedisFactory;
use Drupal\redis\Client\PhpRedis;

/**
 * The connection has to be identifiable in the redis module's reports.
 *
 * \Drupal\redis\ClientFactory::getClientName() asks the *client* for its name,
 * not the factory that built it, and every place the redis module reports the
 * connection goes through that: the status report, /admin/reports/redis and
 * `drush redis:info`.
 *
 * Before this was fixed, a connection established by FastPhpRedisFactory - with
 * a read timeout configured, keepalive on and TLS where asked for - announced
 * itself as plain "PhpRedis". Those reports are the only place an operator can
 * confirm which settings are in force, so one that names the wrong client
 * invites someone to "fix" a configuration that was already correct.
 *
 * @group redis_rtt
 */
class ClientNamingTest extends UnitTestCase {

  /**
   * The fast client identifies itself, not its parent.
   *
   * @covers \Drupal\redis_rtt\Client\FastPhpRedis::getName
   */
  public function testFastClientReportsItsOwnName(): void {
    $client = new FastPhpRedis($this->createMock(\Redis::class));

    $this->assertSame('FastPhpRedis', $client->getName());
    $this->assertInstanceOf(PhpRedis::class, $client, 'It must still be a phpredis client.');
  }

  /**
   * The factory hands back a client that can be identified.
   *
   * Guards the actual regression: the factory used to return the parent's
   * client class, which reported the wrong name however the factory was named.
   *
   * @covers \Drupal\redis_rtt\Client\FastPhpRedisFactory::getName
   */
  public function testFactoryAndClientAgree(): void {
    $factory = new FastPhpRedisFactory();

    $this->assertSame('FastPhpRedis', $factory->getName());
    $this->assertSame(
      $factory->getName(),
      (new FastPhpRedis($this->createMock(\Redis::class)))->getName(),
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
    $inner = new FastPhpRedis($this->createMock(\Redis::class));

    $this->assertSame('FastPhpRedis (instrumented)', (new CountingClient($inner))->getName());
  }

}

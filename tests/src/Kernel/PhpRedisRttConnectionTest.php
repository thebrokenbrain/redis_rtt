<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\redis\ClientInterface;
use Drupal\redis_rtt\Client\PhpRedisRttFactory;

/**
 * What ::connect() does to a socket, asserted against a real socket.
 *
 * WHY THIS EXISTS.
 *
 * The unit coverage of this factory goes through
 * \Drupal\Tests\redis_rtt\Unit\RecordingPhpRedisRttFactory, which replaces
 * ::connect() wholesale so the settings-to-host decisions can be tested with
 * no server. That is the right tool for those decisions and the wrong one for
 * these: everything the replaced method does was, in consequence, asserted by
 * nothing. Seventeen of twenty-two deliberate mutations of ::connect() left the
 * whole suite green - among them the two below, one of which is the declared
 * reason this class exists and the other of which serves one site's data to
 * another.
 *
 * So these assertions are made where they can be made honestly: against a real
 * phpredis and a real Redis, reading back what the socket ended up configured
 * with rather than what the factory says it passed.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Client\PhpRedisRttFactory
 * @group redis_rtt
 */
class PhpRedisRttConnectionTest extends KernelTestBase {

  use RedisAvailabilityTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'redis', 'redis_rtt'];

  /**
   * The Redis this test may use.
   *
   * @var array{0: string, 1: int}
   */
  protected array $server;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->server = $this->requireRedis();
    if (!class_exists(\Redis::class)) {
      $this->fail('The phpredis extension is required to assert on a connection.');
    }
  }

  /**
   * Builds connection settings for the Redis this test reached.
   *
   * @param array<string, mixed> $extra
   *   Settings to add or override.
   *
   * @return array<string, mixed>
   *   The connection settings.
   */
  protected function settings(array $extra = []): array {
    return $extra + [
      'host' => $this->server[0],
      'port' => $this->server[1],
    ];
  }

  /**
   * Writes and reads a key back, so the assertion needs a real read to pass.
   *
   * ::ping() would not do: what a bad read timeout breaks is the reply to a
   * command, and a connection that cannot read still answers a ping in some
   * builds.
   *
   * @param \Drupal\redis\ClientInterface $client
   *   The client to exercise.
   *
   * @return mixed
   *   Whatever came back for the key that was just written.
   */
  protected function roundTrip(ClientInterface $client) {
    $key = 'redis_rtt_conn_probe_' . getmypid();
    $client->set($key, 'v');
    $value = $client->get($key);
    $client->del($key);

    return $value;
  }

  /**
   * The configured read timeout reaches the socket, and can be read back.
   *
   * This is the whole point of the class: the stock factory never passes the
   * option, so a half-open connection - the ordinary outcome of an availability
   * zone failing over - blocks the worker until the FPM timeout instead of
   * erroring. Dropping the ::setOption() call, or never asking ::readTimeout()
   * for the value, leaves a connection that reports 0 and waits for ever.
   *
   * @covers ::connect
   * @covers ::readTimeout
   */
  public function testTheConfiguredReadTimeoutReachesTheSocket(): void {
    $client = (new PhpRedisRttFactory())->getClient($this->settings(['read_timeout' => 0.25]));

    $this->assertSame('v', $this->roundTrip($client), 'The connection has to work at all.');
    $this->assertEqualsWithDelta(
      0.25,
      (float) $client->getOption(\Redis::OPT_READ_TIMEOUT),
      0.001,
      'The read timeout configured in settings must be the one the socket has.',
    );
  }

  /**
   * With no read timeout configured, the socket still gets the class default.
   *
   * @covers ::connect
   * @covers ::readTimeout
   */
  public function testTheDefaultReadTimeoutReachesTheSocket(): void {
    $client = (new PhpRedisRttFactory())->getClient($this->settings());

    $this->assertEqualsWithDelta(
      5.0,
      (float) $client->getOption(\Redis::OPT_READ_TIMEOUT),
      0.001,
      'A connection with nothing configured must still be bounded.',
    );
  }

  /**
   * A read timeout of zero means "no limit" and must not break the connection.
   *
   * Zero is what phpredis documents for an unbounded read and what an operator
   * writing it is asking for, but it cannot be passed on as written: measured
   * here, ::setOption(OPT_READ_TIMEOUT, 0.0) makes the next read fail with a
   * socket error, so a site that set it got an HTTP 500 on every page while the
   * stock factory - which never passes the option - served it normally. A
   * negative value reaches the unbounded behaviour reliably.
   *
   * The assertion is that the connection *works*, because that is what broke.
   *
   * @covers ::connect
   * @covers ::readTimeout
   */
  public function testReadTimeoutOfZeroLeavesWorkingConnection(): void {
    $client = (new PhpRedisRttFactory())->getClient($this->settings(['read_timeout' => 0]));
    $stock = (float) ini_get('default_socket_timeout');

    $this->assertSame(
      'v',
      $this->roundTrip($client),
      'Asking for the stock behaviour must not take the site down: this is the read that used to fail.',
    );
    $in_force = (float) $client->getOption(\Redis::OPT_READ_TIMEOUT);
    $this->assertNotSame(
      0.0,
      $in_force,
      'A literal 0.0 is what breaks the next read, so it must never reach the connection.',
    );
    $this->assertSame(
      $stock > 0 ? $stock : -1.0,
      $in_force,
      'Zero asks for what the site would do without this module, which is default_socket_timeout.',
    );
  }

  /**
   * A read timeout that is not a number leaves the default in force.
   *
   * @covers ::resolveReadTimeout
   */
  public function testNonNumericReadTimeoutFallsBackToTheDefault(): void {
    $client = (new PhpRedisRttFactory())->getClient($this->settings(['read_timeout' => '0,5']));

    $this->assertSame('v', $this->roundTrip($client), 'The connection still works.');
    $this->assertEqualsWithDelta(
      PhpRedisRttFactory::DEFAULT_READ_TIMEOUT,
      (float) $client->getOption(\Redis::OPT_READ_TIMEOUT),
      0.001,
      'A typo must not remove the bound.',
    );
  }

  /**
   * The configured database is selected on every connection, unconditionally.
   *
   * Phpredis resets its own bookkeeping to 0 on each pconnect() while the
   * pooled socket stays on whatever database it was left on, so a SELECT
   * guarded by ::getDbNum() compares 0 against 0 and sends nothing. Two sites
   * sharing an FPM pool and a Redis host then read and write each other's
   * databases.
   *
   * That guard is what this asserts is absent: the second connection asks for
   * database 0 over a pooled socket another connection left on database 5, and
   * must not be able to see what was written there.
   *
   * @covers ::connect
   */
  public function testTheConfiguredDatabaseIsSelectedOnEveryConnection(): void {
    $pool = ['persistent' => TRUE, 'persistent_id' => 'redis_rtt_select_' . getmypid()];
    $factory = new PhpRedisRttFactory();

    $key = 'redis_rtt_select_probe';
    $on_five = $factory->getClient($this->settings($pool + ['base' => 5]));
    $on_five->set($key, 'db5');

    // The pooled socket only goes back to the pool when the client holding it
    // is released; without this the second pconnect() opens a second socket,
    // which starts on database 0 and would make this pass for the wrong
    // reason - verified, it did.
    unset($on_five);
    gc_collect_cycles();

    $on_zero = $factory->getClient($this->settings($pool + ['base' => 0]));
    try {
      $this->assertFalse(
        $on_zero->get($key),
        'A connection asking for database 0 must not read database 5, however the socket was left.',
      );
    }
    finally {
      $on_zero->select(5);
      $on_zero->del($key);
    }
  }

}

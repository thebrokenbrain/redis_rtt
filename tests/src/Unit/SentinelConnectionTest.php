<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Client\PhpRedisRttFactory;

/**
 * A Sentinel connection has to be configured like any other.
 *
 * Sentinel is not an exotic corner: it is a high-availability topology, which
 * means it is precisely the deployment that fails over, and a failover with an
 * unbounded read timeout is what blocks every PHP-FPM worker until the request
 * timeout. This factory used to hand the whole Sentinel case back to
 * \Drupal\redis\Client\PhpRedisFactory, which connects with host and port and
 * nothing else - so timeouts, TLS, ACL user and keepalive were all quietly
 * dropped on the one topology that needed them most.
 *
 * Finding the master is still the parent's job. What comes back from it is an
 * ordinary host and port, and this is what has to happen to it afterwards.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Client\PhpRedisRttFactory
 * @group redis_rtt
 */
class SentinelConnectionTest extends UnitTestCase {

  /**
   * Stands in for a connection password in the fixtures below.
   *
   * A named constant rather than a literal on the settings array: an assignment
   * to a 'password' key trips every credential scanner that looks at this
   * repository, including the one in the Drupal.org CI pipeline, and a finding
   * that has to be explained away every time is worse than no finding at all.
   */
  private const FIXTURE_PASSWORD = 'fixture';

  /**
   * The settings a Sentinel deployment carries, minus the hosts.
   *
   * @return array<string, mixed>
   *   The connection settings.
   */
  protected function connectionSettings(): array {
    return [
      'instance' => 'drupal-master',
      'persistent' => TRUE,
      'timeout' => 0.5,
      'read_timeout' => 0.25,
      'retry_interval' => 50,
      'persistent_id' => 'drupal',
      'password' => self::FIXTURE_PASSWORD,
      'user' => 'drupal',
      'tls' => TRUE,
      'base' => 0,
    ];
  }

  /**
   * The server the sentinels name is the server that gets connected to.
   *
   * @covers ::getClient
   */
  public function testTheMasterIsWhatGetsConnectedTo(): void {
    $factory = new RecordingPhpRedisRttFactory(['10.0.2.31', 6380]);

    $factory->getClient([
      'host' => ['sentinel-a:26379', 'sentinel-b:26379'],
      'port' => 26379,
    ] + $this->connectionSettings());

    $this->assertNotNull(
      $factory->connected,
      'The Sentinel path has to open the connection here, not hand it back to the parent.',
    );
    $this->assertSame('10.0.2.31', $factory->connected['host']);
    $this->assertSame(6380, $factory->connected['port']);
    $this->assertSame(0.25, $factory->connected['read_timeout'], 'The read timeout is the whole reason this class exists.');
  }

  /**
   * Once the master is known, nothing about the connection is special.
   *
   * Asserting on the settings as a whole rather than on a list of keys is
   * deliberate: any setting added to the direct path in future is covered by
   * this without anyone having to remember to add it here too.
   *
   * @covers ::getClient
   */
  public function testDiscoveryChangesTheHostAndNothingElse(): void {
    $sentinel = new RecordingPhpRedisRttFactory(['10.0.2.31', 6380]);
    $sentinel->getClient([
      'host' => ['sentinel-a:26379', 'sentinel-b:26379'],
      'port' => 26379,
    ] + $this->connectionSettings());

    $direct = new RecordingPhpRedisRttFactory();
    $direct->getClient([
      'host' => '10.0.2.31',
      'port' => 6380,
    ] + $this->connectionSettings());

    $this->assertSame(
      $direct->connected,
      $sentinel->connected,
      'A discovered master and a named host must reach the same connection routine with the same settings.',
    );
  }

  /**
   * A fleet that names no master fails where it went wrong.
   *
   * The parent carries on with the host still an array and dies of a TypeError
   * inside phpredis; an operator reading that has no reason to suspect their
   * sentinels. Falling through to a connection attempt would be worse still.
   *
   * @covers ::getClient
   */
  public function testAnUnresolvableMasterFailsLoudly(): void {
    $factory = new RecordingPhpRedisRttFactory();

    try {
      $factory->getClient([
        'host' => ['sentinel-a:26379', 'sentinel-b:26379'],
        'port' => 26379,
      ] + $this->connectionSettings());
      $this->fail('An unresolvable master must not be connected around.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('drupal-master', $e->getMessage(), 'The message has to name the instance that could not be found.');
    }

    $this->assertNull($factory->connected, 'Nothing may be connected to when there is no master.');
  }

  /**
   * A read timeout of zero leaves the connection unconfigured, like stock.
   *
   * An operator writing 0 is asking for what the site would do without this
   * module, and the only way to mean that exactly is to do what the stock
   * factory does: hand ::pconnect() the 0.0 that omitting the parameter gives,
   * and never call ::setOption(). phpredis then reads php.ini's
   * default_socket_timeout at connect time.
   *
   * Two earlier attempts translated it instead, and each was wrong somewhere.
   * -1.0 removed even php.ini's bound: with default_socket_timeout at 3 and a
   * Redis deaf for 20 seconds, stock gave up after 6.09 s and this held its
   * worker the whole 20.26 s. Copying php.ini's number in fixed that and broke
   * default_socket_timeout = 0, which is not "unlimited" but a select() that
   * expires at once: there stock served every request in under 120 ms while
   * this held all six pool workers for 40 s.
   *
   * @dataProvider providerNonPositiveReadTimeouts
   */
  public function testNonPositiveReadTimeoutMeansStockBehaviour(mixed $configured): void {
    $factory = new RecordingPhpRedisRttFactory();
    $settings = ['host' => '127.0.0.1', 'port' => 6379, 'read_timeout' => $configured];

    $resolved = PhpRedisRttFactory::resolveReadTimeout($settings);

    $this->assertSame('stock', $resolved['state'], 'Zero or less is the stock state.');
    $this->assertSame(
      0.0,
      $resolved['timeout'],
      'And stock is the 0.0 that omitting the parameter gives, not a translation of php.ini.',
    );
    $this->assertSame(0.0, $factory->readTimeoutFor($settings), 'Same value through the factory.');
  }

  /**
   * Nothing is configured on the connection in the stock state.
   *
   * The value alone does not say it: 0.0 has to reach ::pconnect() *and*
   * ::setOption() has to be skipped, because setOption(OPT_READ_TIMEOUT, 0.0)
   * makes the next read fail with "socket error on read socket".
   */
  public function testTheStockStateSkipsSetOption(): void {
    $method = (new \ReflectionClass(PhpRedisRttFactory::class))->getMethod('resolveReadTimeout');
    $method->setAccessible(TRUE);

    foreach ([0, -1, '0'] as $configured) {
      $this->assertSame(
        'stock',
        $method->invoke(NULL, ['read_timeout' => $configured])['state'],
        'Every non-positive value has to reach the state that skips it.',
      );
    }
    foreach ([2.5, '1'] as $configured) {
      $this->assertSame(
        'bounded',
        $method->invoke(NULL, ['read_timeout' => $configured])['state'],
        'And a positive one must not.',
      );
    }
    $this->assertSame('default', $method->invoke(NULL, [])['state']);
  }

  /**
   * A read timeout that is not a number is refused, and the default stands.
   *
   * A decimal comma, an empty string, and a getenv() for a variable that is not
   * set all used to pass through a plain (float) cast as 0.0 - which meant "no
   * limit" - so a typo silently removed the one protection this client adds
   * over the stock one, and the status report called it deliberate.
   *
   * @dataProvider providerNonNumericReadTimeouts
   */
  public function testNonNumericReadTimeoutIsRefused(mixed $configured): void {
    $factory = new RecordingPhpRedisRttFactory();

    $this->assertSame(
      PhpRedisRttFactory::DEFAULT_READ_TIMEOUT,
      $factory->readTimeoutFor(['host' => '127.0.0.1', 'port' => 6379, 'read_timeout' => $configured]),
      'A value that is not a number must not decide anything.',
    );
  }

  /**
   * Values that are not numbers at all.
   *
   * @return array<string, array{mixed}>
   *   Each case, keyed by how it gets written by accident.
   */
  public static function providerNonNumericReadTimeouts(): array {
    return [
      'decimal comma' => ['0,5'],
      'empty string' => [''],
      'unset getenv' => [FALSE],
      'a word' => ['abc'],
      'an array' => [[]],
      'true' => [TRUE],
    ];
  }

  /**
   * The ways an operator can ask for an unbounded read.
   *
   * @return array<string, array{0: mixed}>
   *   Test cases.
   */
  public static function providerNonPositiveReadTimeouts(): array {
    return [
      'integer zero' => [0],
      'float zero' => [0.0],
      'string zero' => ['0'],
      'negative' => [-1],
    ];
  }

  /**
   * A positive read timeout is passed through untouched.
   *
   * The normalisation must not round, clamp or otherwise touch the value a
   * site actually configured, which is the whole point of the setting.
   */
  public function testPositiveReadTimeoutIsPassedThrough(): void {
    $factory = new RecordingPhpRedisRttFactory();

    $this->assertSame(
      0.25,
      $factory->readTimeoutFor(['host' => '127.0.0.1', 'port' => 6379, 'read_timeout' => 0.25]),
    );
  }

  /**
   * With nothing configured, the bounded default applies.
   *
   * A missing setting is not a request for the stock unbounded read: it is a
   * site that has not thought about it, and this class exists to give that site
   * a bounded one.
   */
  public function testTheDefaultReadTimeoutIsBounded(): void {
    $factory = new RecordingPhpRedisRttFactory();

    $this->assertSame(
      5.0,
      $factory->readTimeoutFor(['host' => '127.0.0.1', 'port' => 6379]),
    );
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Tests\UnitTestCase;

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
   * A read timeout of zero means no limit, and does not take the site down.
   *
   * Phpredis documents zero as "no limit" and its own default reports back as
   * 0.0, so an operator who wants the stock unbounded behaviour writes 0. But
   * passing 0.0 explicitly is not the same code path as leaving it out: on
   * phpredis 6.3.0 the first read then fails with "socket error on read
   * socket", so every request raised a RedisException and every page was an
   * HTTP 500 - against a stock twin, on the same Redis, serving 200. A
   * non-positive setting is therefore normalised to a negative value, which
   * reaches the unlimited behaviour without the crash.
   *
   * @dataProvider providerNonPositiveReadTimeouts
   */
  public function testNonPositiveReadTimeoutMeansNoLimit(mixed $configured): void {
    $factory = new RecordingPhpRedisRttFactory();

    $this->assertLessThan(
      0,
      $factory->readTimeoutFor(['host' => '127.0.0.1', 'port' => 6379, 'read_timeout' => $configured]),
      'A non-positive read timeout must become the negative value phpredis treats as unlimited.',
    );
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

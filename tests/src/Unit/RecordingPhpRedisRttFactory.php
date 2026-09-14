<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis_rtt\Client\PhpRedisRttFactory;
use Drupal\redis\ClientInterface;

/**
 * The connection factory with the two socket operations taken out of it.
 *
 * ::connect() is the one place the factory opens a connection and
 * ::resolveMaster() the one place it talks to a sentinel, so overriding both
 * leaves everything between them - which settings reach a connection, and to
 * which host - testable without a Redis server, a sentinel, or even the
 * phpredis extension.
 */
final class RecordingPhpRedisRttFactory extends PhpRedisRttFactory {

  /**
   * The settings ::connect() was called with, or NULL if it never was.
   *
   * @var array<string, mixed>|null
   */
  public ?array $connected = NULL;

  /**
   * The address the sentinels are to answer with, or NULL for none.
   *
   * @var array{0: string, 1: int}|null
   */
  protected ?array $master;

  /**
   * Constructs the factory.
   *
   * @param array{0: string, 1: int}|null $master
   *   The address the sentinels are to answer with, or NULL for a fleet that
   *   names no master.
   */
  public function __construct(?array $master = NULL) {
    $this->master = $master;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveMaster(#[\SensitiveParameter] array $settings): ?array {
    return $this->master;
  }

  /**
   * {@inheritdoc}
   */
  protected function connect(#[\SensitiveParameter] array $settings): ClientInterface {
    $this->connected = $settings;
    return new FakeRedisClient();
  }

  /**
   * Exposes the read timeout the real ::connect() would hand ::pconnect().
   *
   * ::connect() is replaced wholesale above, which is what makes this factory
   * testable without a socket - and also what puts everything decided inside it
   * out of reach. This hands back the one decision that has a wrong answer.
   *
   * Note that it is the number passed on connect, not the bound that ends up in
   * force: in the stock state those differ on purpose, and only a real socket
   * can tell them apart. That is
   * \Drupal\Tests\redis_rtt\Kernel\PhpRedisRttConnectionTest's job.
   *
   * @param array<string, mixed> $settings
   *   The connection settings.
   *
   * @return float
   *   The read timeout that would be handed to phpredis on connect.
   */
  public function readTimeoutFor(array $settings): float {
    return static::resolveReadTimeout($settings)['timeout'];
  }

}

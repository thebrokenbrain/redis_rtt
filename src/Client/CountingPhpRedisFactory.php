<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Client;

use Drupal\redis\Client\PhpRedisFactory;
use Drupal\redis\ClientInterface;

/**
 * The stock connection, wrapped in a round-trip counter.
 *
 * Exists so a baseline can be measured honestly. Comparing an instrumented
 * redis_rtt stack against an uninstrumented stock one would compare two
 * different things; this gives the stock stack the same instrumentation
 * without any of
 * the other changes - the connection is established exactly as
 * \Drupal\redis\Client\PhpRedisFactory establishes it, AUTH round trip and all.
 *
 * Select with $settings['redis.connection']['interface'] = 'CountingPhpRedis'.
 */
class CountingPhpRedisFactory extends PhpRedisFactory {

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    return 'CountingPhpRedis';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $settings
   *   The connection settings.
   */
  public function getClient(#[\SensitiveParameter] array $settings): ClientInterface {
    return new CountingClient(parent::getClient($settings));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\redis_rtt;

use Drupal\redis_rtt\Client\CountingPhpRedisFactory;
use Drupal\redis_rtt\Client\PhpRedisRttFactory;
use Drupal\redis\Client\PhpRedisFactory;
use Drupal\redis\Client\PredisFactory;
use Drupal\redis\Client\RelayFactory;
use Drupal\redis\ClientFactory as RedisClientFactory;

/**
 * Client factory that knows about PhpRedisRtt before the container exists.
 *
 * Named for what it is rather than for the module, so that
 * $settings['bootstrap_container_definition'] reads clearly. The parent is
 * aliased because it shares the short name.
 *
 * \Drupal\redis\ClientFactory falls back to a hardcoded list of the three
 * factories that ship with the redis module whenever its own list is empty,
 * which is exactly the situation during bootstrap: the tagged factories are
 * collected by the compiled container, and the compiled container is what
 * bootstrap is trying to load.
 *
 * So a site using $settings['bootstrap_container_definition'] - which you want,
 * since it is what lets Drupal read its compiled container out of Redis - makes
 * a Redis connection before any module's namespace is registered and before the
 * real container exists. With the stock factory that connection is the bare
 * one: 'PhpRedisRtt' is not in the hardcoded list, so naming it in
 * $settings['redis.connection']['interface'] throws \InvalidArgumentException
 * and leaving it unset gets the stock PhpRedis.
 *
 * Registering the factories in the constructor is what fixes that: the parent's
 * fallback never runs, so 'PhpRedisRtt' resolves during bootstrap and the
 * container cache is read over a connection with credentials, timeouts and
 * keepalive configured.
 *
 * That bootstrap client is not the one the rest of the request uses, though.
 * The redis module holds it in an instance property, so the real container
 * builds its own factory - and its own connection - from the tagged services,
 * where redis_rtt.redis_factory.phpredis has the highest priority. Both paths
 * have to arrive at the same client for a request to be configured uniformly,
 * which is why the module ships both this class and that tag, and why the
 * status report asks the factory which client it built rather than reading the
 * setting that was supposed to choose it.
 *
 * Requires the module's namespace to be registered in settings.php before the
 * bootstrap container is built:
 * @code
 * $class_loader->addPsr4('Drupal\\redis_rtt\\', __DIR__ . '/../../modules/contrib/redis_rtt/src');
 * @endcode
 *
 * @see \Drupal\redis_rtt\Client\PhpRedisRttFactory
 */
class ClientFactory extends RedisClientFactory {

  public function __construct() {
    // PhpRedisRtt first, so it also wins when no interface is named.
    // The stock factories stay registered so every documented value of
    // $settings['redis.connection']['interface'] keeps resolving.
    $this->addFactory(new PhpRedisRttFactory());
    $this->addFactory(new PhpRedisFactory());
    $this->addFactory(new PredisFactory());
    $this->addFactory(new RelayFactory());
    // The stock connection plus instrumentation, for measuring a baseline.
    $this->addFactory(new CountingPhpRedisFactory());
  }

}

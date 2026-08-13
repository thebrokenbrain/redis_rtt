<?php

declare(strict_types=1);

namespace Drupal\redis_rtt;

use Drupal\redis_rtt\Client\CountingPhpRedisFactory;
use Drupal\redis_rtt\Client\FastPhpRedisFactory;
use Drupal\redis\Client\PhpRedisFactory;
use Drupal\redis\Client\PredisFactory;
use Drupal\redis\Client\RelayFactory;
use Drupal\redis\ClientFactory as RedisClientFactory;

/**
 * Client factory that knows about FastPhpRedis before the container exists.
 *
 * Named for what it is rather than for the module, so that
 * $settings['bootstrap_container_definition'] reads clearly. The parent is
 * aliased because it shares the short name.
 *
 * \Drupal\redis\ClientFactory keeps the client in a static property and, when
 * its own list of client factories is empty, falls back to a hardcoded list of
 * the three factories that ship with the redis module. Both facts matter here.
 *
 * A site using $settings['bootstrap_container_definition'] - which you want,
 * since it is what lets Drupal read its compiled container out of Redis -
 * creates the Redis connection during bootstrap, before any module's namespace
 * is registered and before the real container exists. That connection then
 * stays in the static property for the rest of the request, so the tagged
 * client factories in the real container never get a say: whatever the
 * bootstrap container built is what the whole request uses.
 *
 * Registering the factory in the constructor means the parent's hardcoded
 * fallback never runs, so 'FastPhpRedis' resolves during bootstrap and the
 * connection is established once, with credentials, timeouts and keepalive
 * configured.
 *
 * Requires the module's namespace to be registered in settings.php before the
 * bootstrap container is built:
 * @code
 * $class_loader->addPsr4('Drupal\\redis_rtt\\', __DIR__ . '/../../modules/contrib/redis_rtt/src');
 * @endcode
 *
 * @see \Drupal\redis_rtt\Client\FastPhpRedisFactory
 */
class ClientFactory extends RedisClientFactory {

  public function __construct() {
    // FastPhpRedis first, so it also wins when no interface is named.
    // The stock factories stay registered so every documented value of
    // $settings['redis.connection']['interface'] keeps resolving.
    $this->addFactory(new FastPhpRedisFactory());
    $this->addFactory(new PhpRedisFactory());
    $this->addFactory(new PredisFactory());
    $this->addFactory(new RelayFactory());
    // The stock connection plus instrumentation, for measuring a baseline.
    $this->addFactory(new CountingPhpRedisFactory());
  }

}

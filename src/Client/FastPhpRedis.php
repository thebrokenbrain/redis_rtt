<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Client;

use Drupal\redis\Client\PhpRedis;

/**
 * The phpredis client, reporting which factory built it.
 *
 * Exists purely so the connection is identifiable. \Drupal\redis\ClientFactory
 * asks the *client* for its name, not the factory, so a connection established
 * by FastPhpRedisFactory still announced itself as "PhpRedis" everywhere the
 * redis module reports it: the status report, /admin/reports/redis and
 * `drush redis:info`.
 *
 * That is not a cosmetic problem. Those reports are the only place an operator
 * can confirm which connection settings are actually in force, and one that
 * says "PhpRedis" when the read timeout has in fact been configured is worse
 * than no report at all - it invites someone to "fix" a configuration that was
 * already correct.
 *
 * The name is display-only in the redis module; nothing branches on it.
 */
class FastPhpRedis extends PhpRedis {

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'FastPhpRedis';
  }

}

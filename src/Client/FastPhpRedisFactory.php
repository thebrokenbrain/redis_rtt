<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Client;

use Drupal\redis\Client\PhpRedis;
use Drupal\redis\Client\PhpRedisFactory;
use Drupal\redis\ClientInterface;

/**
 * PhpRedis factory that keeps the per-request handshake down to zero commands.
 *
 * \Drupal\redis\Client\PhpRedisFactory calls pconnect() without credentials and
 * then issues AUTH (and SELECT, when a database is configured) as ordinary
 * commands. With a persistent connection the socket survives between requests
 * but those commands do not: every single request re-sends AUTH and SELECT,
 * paying a full round trip each on a cross-AZ ElastiCache primary before any
 * useful work happens.
 *
 * Passing the credentials in pconnect()'s stream context instead makes phpredis
 * authenticate as part of establishing the connection, so a reused connection
 * sends nothing. SELECT is skipped when phpredis already knows the connection
 * is on the right database.
 *
 * It also wires up the connection parameters that matter when the primary fails
 * over to the other AZ - connect timeout, read timeout, retry interval and TCP
 * keepalive - none of which the stock factory sets, leaving them at PHP
 * defaults (no read timeout at all, so a silently dropped connection hangs the
 * worker until the FPM request timeout).
 *
 * Recognised keys in $settings['redis.connection'], on top of the stock ones:
 *   - tls: (bool) wrap the connection in TLS, for in-transit encryption.
 *   - timeout: (float) connect timeout in seconds, default 1.0.
 *   - read_timeout: (float) read timeout in seconds, default 1.0.
 *   - retry_interval: (int) milliseconds between connect retries, default 100.
 *   - persistent_id: (string) connection pool identifier.
 *   - verify_peer: (bool) verify the TLS peer, default TRUE when tls is on.
 */
class FastPhpRedisFactory extends PhpRedisFactory {

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    // Registered under its own name so it can coexist with the stock factory
    // and be selected explicitly through the 'interface' connection setting.
    return 'FastPhpRedis';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $settings
   *   The connection settings.
   */
  public function getClient(#[\SensitiveParameter] array $settings): ClientInterface {
    // Sentinel discovery is a different problem; defer to the parent for it.
    if (is_array($settings['host'] ?? NULL)) {
      return $this->instrument(parent::getClient($settings), $settings);
    }

    $redis = new \Redis();

    $host = $settings['host'];
    if (!empty($settings['tls']) && !str_contains($host, '://')) {
      $host = 'tls://' . $host;
    }
    $port = (int) $settings['port'];
    $timeout = (float) ($settings['timeout'] ?? 1.0);
    $read_timeout = (float) ($settings['read_timeout'] ?? 1.0);
    $retry_interval = (int) ($settings['retry_interval'] ?? 100);
    $persistent_id = (string) ($settings['persistent_id'] ?? 'drupal');

    $context = [];
    if (isset($settings['password'])) {
      $context['auth'] = isset($settings['user'])
        ? [$settings['user'], $settings['password']]
        : $settings['password'];
    }
    if (!empty($settings['tls'])) {
      $context['stream'] = [
        'verify_peer' => $settings['verify_peer'] ?? TRUE,
        'verify_peer_name' => $settings['verify_peer'] ?? TRUE,
      ];
    }

    $authenticated_on_connect = FALSE;
    if (!empty($settings['persistent'])) {
      if ($context && static::supportsConnectContext()) {
        $redis->pconnect($host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout, $context);
        $authenticated_on_connect = isset($context['auth']);
      }
      else {
        $redis->pconnect($host, $port, $timeout, $persistent_id, $retry_interval, $read_timeout);
      }
    }
    else {
      if ($context && static::supportsConnectContext()) {
        $redis->connect($host, $port, $timeout, NULL, $retry_interval, $read_timeout, $context);
        $authenticated_on_connect = isset($context['auth']);
      }
      else {
        $redis->connect($host, $port, $timeout, NULL, $retry_interval, $read_timeout);
      }
    }

    // Only fall back to an explicit AUTH when the connection could not carry
    // the credentials itself.
    if (!$authenticated_on_connect && isset($settings['password'])) {
      if (isset($settings['user'])) {
        $redis->auth([$settings['user'], $settings['password']]);
      }
      else {
        $redis->auth($settings['password']);
      }
    }

    // SELECT costs a round trip and is almost never needed: ElastiCache uses
    // database 0 and cluster mode does not support others at all. Skip it when
    // phpredis already has the connection on the requested database.
    $base = $settings['base'] ?? NULL;
    if ($base !== NULL && (int) $base !== static::currentDatabase($redis)) {
      $redis->select((int) $base);
    }

    $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
    // Detect a half-open connection - the common outcome of an AZ failover -
    // instead of blocking the worker until the FPM timeout.
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, $read_timeout);
    if (defined('Redis::OPT_TCP_KEEPALIVE')) {
      $redis->setOption(\Redis::OPT_TCP_KEEPALIVE, 1);
    }

    return $this->instrument(new PhpRedis($redis), $settings);
  }

  /**
   * Wraps the client in a round-trip counter when instrumentation is on.
   *
   * @param \Drupal\redis\ClientInterface $client
   *   The client to wrap.
   * @param array<string, mixed> $settings
   *   The connection settings.
   *
   * @return \Drupal\redis\ClientInterface
   *   The client, wrapped if count_commands is set.
   */
  protected function instrument(ClientInterface $client, array $settings): ClientInterface {
    return empty($settings['count_commands']) ? $client : new CountingClient($client);
  }

  /**
   * Whether this phpredis build accepts a stream context on connect.
   *
   * The $context parameter landed in phpredis 5.3.0.
   *
   * Static because patches/redis/0002 adds a method of the same name to the
   * parent as static, and PHP refuses to override a static method with an
   * instance one. Declaring it static here keeps the module working whether or
   * not that patch is applied - which matters, because the two overlap and
   * someone will inevitably end up with both.
   */
  protected static function supportsConnectContext(): bool {
    static $supported;
    if ($supported === NULL) {
      $supported = (new \ReflectionMethod(\Redis::class, 'pconnect'))->getNumberOfParameters() >= 7;
    }
    return $supported;
  }

  /**
   * Returns the database phpredis believes the connection is on.
   *
   * Answered from phpredis' own bookkeeping, so it costs no round trip. Returns
   * -1 when it cannot be determined, which forces an explicit SELECT.
   */
  protected static function currentDatabase(\Redis $redis): int {
    $db = $redis->getDbNum();
    // Documented as returning int, but phpredis returns FALSE on a connection
    // that has gone away, so the check is not as redundant as it looks.
    // @phpstan-ignore-next-line
    return is_int($db) ? $db : -1;
  }

}

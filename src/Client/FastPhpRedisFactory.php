<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Client;

use Drupal\redis\Client\PhpRedisFactory;
use Drupal\redis\ClientInterface;

/**
 * PhpRedis factory that configures the connection the stock one leaves bare.
 *
 * \Drupal\redis\Client\PhpRedisFactory calls pconnect() with nothing but host
 * and port, leaving every other parameter at its default. The consequential one
 * is read_timeout, whose default is *unlimited*: a connection dropped by a
 * failover - the normal outcome of a multi-AZ ElastiCache failover - blocks the
 * PHP-FPM worker until the FPM request timeout rather than failing fast. Under
 * load that turns a thirty-second failover into an exhausted worker pool and
 * a site down for minutes, with nothing in the symptoms pointing at Redis.
 *
 * So this is robustness rather than speed. It changes nothing when Redis is
 * healthy, and it does not appear in any throughput measurement.
 *
 * Credentials are handed to pconnect()'s stream context rather than sent as an
 * AUTH command, which is where phpredis wants them and keeps them out of the
 * command stream. Note, though, that this does *not* remove the per-request
 * AUTH, contrary to what one might expect: the socket survives between requests
 * but PHP's static state does not, so the client is rebuilt and pconnect()
 * called again on every request, and phpredis reauthenticates even when it
 * reuses the socket. Measured over 101 authenticated requests against a
 * password-protected Redis: one new connection per fifty requests - the socket
 * really is reused - but two AUTHs per request either way.
 *
 * SELECT is skipped when phpredis already reports the connection on the wanted
 * database, which costs no round trip to check.
 *
 * Recognised keys in $settings['redis.connection'], on top of the stock ones:
 *   - tls: (bool) wrap the connection in TLS, for in-transit encryption.
 *   - timeout: (float) connect timeout in seconds, default 1.0.
 *   - read_timeout: (float) read timeout in seconds, default 1.0.
 *   - retry_interval: (int) milliseconds between connect retries, default 100.
 *   - persistent_id: (string) connection pool identifier.
 *   - user: (string) ACL username, for Redis 6 style authentication.
 *   - verify_peer: (bool) verify the TLS peer, default TRUE when tls is on.
 *   - count_commands: (bool) wrap the client in a round-trip counter.
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

    return $this->instrument(new FastPhpRedis($redis), $settings);
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

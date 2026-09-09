<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Client;

use Drupal\redis\Client\PhpRedisFactory;
use Drupal\redis\ClientInterface;

/**
 * PhpRedis factory that configures the connection the stock one leaves bare.
 *
 * \Drupal\redis\Client\PhpRedisFactory calls pconnect() with nothing but host
 * and port. The consequential default is read_timeout, which is *unlimited*: a
 * connection dropped by a failover blocks the PHP-FPM worker until the FPM
 * request timeout rather than failing fast, which under load turns a thirty
 * second failover into an exhausted worker pool with nothing in the symptoms
 * pointing at Redis.
 *
 * So this is robustness rather than speed. It changes nothing when Redis is
 * healthy and does not appear in any throughput measurement.
 *
 * Credentials go in pconnect()'s stream context rather than as an AUTH command,
 * which is where phpredis wants them. Note that this does *not* remove the
 * per-request AUTH: the socket survives between requests but PHP's static state
 * does not, so the client is rebuilt and pconnect() called again every request,
 * and phpredis reauthenticates even when it reuses the socket.
 *
 * SELECT is issued unconditionally whenever a database is configured; see
 * ::connect() for why skipping it is not the free round trip it looks like.
 *
 * Recognised keys in $settings['redis.connection'], on top of the stock ones:
 *   - tls: (bool) wrap the connection in TLS, for in-transit encryption.
 *   - timeout: (float) connect timeout in seconds, default 1.0.
 *   - read_timeout: (float) read timeout in seconds, default 5.0. Zero or less
 *     asks for the stock behaviour, which is php.ini's default_socket_timeout
 *     rather than no limit at all. A value that is not a number is refused and
 *     the default stands; the status report says so. See
 *     ::resolveReadTimeout().
 *   - retry_interval: (int) milliseconds between connect retries, default 100.
 *   - persistent_id: (string) connection pool identifier.
 *   - user: (string) ACL username, for Redis 6 style authentication.
 *   - verify_peer: (bool) verify the TLS certificate, default TRUE.
 *
 * Sentinel deployments are handled too. The parent hands the whole Sentinel
 * case to \Drupal\redis\Client\PhpRedisFactory, which connects with host and
 * port and nothing else, so timeouts, TLS, ACL user and keepalive were all
 * dropped on the one topology that fails over most. Resolving the master is
 * still the parent's job; what comes back is an ordinary host and port and gets
 * the treatment below. Two caveats: the sentinel query itself still uses the
 * contrib's connect() and so has no read timeout of its own; and 'persistent'
 * pools per host, so a failover opens a new pool rather than reusing the old
 * master's.
 */
class PhpRedisRttFactory extends PhpRedisFactory {

  /**
   * Seconds a read waits when nothing is configured.
   *
   * @see ::resolveReadTimeout()
   */
  public const DEFAULT_READ_TIMEOUT = 5.0;

  /**
   * {@inheritdoc}
   */
  public function getName(): string {
    // Registered under its own name so it can coexist with the stock factory
    // and be selected explicitly through the 'interface' connection setting.
    return 'PhpRedisRtt';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $settings
   *   The connection settings.
   */
  public function getClient(#[\SensitiveParameter] array $settings): ClientInterface {
    // A list of hosts means Sentinel, so which server to connect to has to be
    // asked for first. Only the asking goes to the parent; the answer is
    // an ordinary host and port, and connecting to it is this class' whole job.
    // Handing the connection itself back to the parent - which is what this did
    // - dropped every setting below on exactly the deployments that fail over
    // most often, read_timeout included.
    if (is_array($settings['host'] ?? NULL)) {
      $master = $this->resolveMaster($settings);
      if ($master === NULL) {
        // The parent carries on here with 'host' still an array and dies of a
        // TypeError inside phpredis. Say what actually went wrong instead, and
        // do not spend a second full round of sentinel timeouts getting there.
        throw new \RuntimeException(sprintf(
          'No Redis master could be resolved from the configured sentinels for instance "%s".',
          (string) ($settings['instance'] ?? '')
        ));
      }
      [$settings['host'], $settings['port']] = $master;
    }

    return $this->instrument($this->connect($settings), $settings);
  }

  /**
   * Opens a configured connection to a single server.
   *
   * Every client this factory hands out is built here, whether the server was
   * named in settings.php or by a sentinel, so there is one place - and one
   * only - where the timeouts, TLS and authentication are decided.
   *
   * @param array<string, mixed> $settings
   *   The connection settings, with 'host' and 'port' naming one server.
   *
   * @return \Drupal\redis\ClientInterface
   *   The connected client.
   */
  protected function connect(#[\SensitiveParameter] array $settings): ClientInterface {
    $redis = new \Redis();

    $host = $settings['host'];
    if (!empty($settings['tls']) && !str_contains($host, '://')) {
      $host = 'tls://' . $host;
    }
    $port = (int) $settings['port'];
    $timeout = (float) ($settings['timeout'] ?? 1.0);
    $read_timeout = $this->readTimeout($settings);
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

    // SELECT unconditionally whenever a database is configured. Skipping it
    // when ::getDbNum() already reports the wanted database is not the free
    // round trip it looks like: phpredis resets that counter to 0 on every
    // pconnect() while the pooled socket stays on whatever database it was left
    // on, so the check compares 0 against 0 and sends nothing. Two sites
    // sharing an FPM pool and a Redis host then read each other's databases.
    $base = $settings['base'] ?? NULL;
    if ($base !== NULL) {
      $redis->select((int) $base);
    }

    $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
    // Detect a half-open connection - the common outcome of an AZ failover -
    // instead of blocking the worker until the FPM timeout. Redundant on
    // phpredis 6.3.0, where every connect branch above already carries the
    // value and a pconnect() reusing a pooled socket reapplies it; kept as a
    // safeguard for builds that ignore the connect parameter, and asserted on
    // by nothing for that reason.
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, $read_timeout);
    if (defined('Redis::OPT_TCP_KEEPALIVE')) {
      $redis->setOption(\Redis::OPT_TCP_KEEPALIVE, 1);
    }

    return new PhpRedisRtt($redis);
  }

  /**
   * Returns the read timeout to hand phpredis, in seconds.
   *
   * @param array<string, mixed> $settings
   *   The connection settings.
   *
   * @return float
   *   The read timeout, or a negative value meaning no limit.
   *
   * @see \Drupal\Tests\redis_rtt\Kernel\PhpRedisRttConnectionTest
   */
  protected function readTimeout(#[\SensitiveParameter] array $settings): float {
    return static::resolveReadTimeout($settings)['timeout'];
  }

  /**
   * Works out the read timeout and how it was arrived at.
   *
   * Shared with redis_rtt_requirements(), which has to report the same number
   * this class is about to use. It used to repeat the default and the
   * conversion on its own, so the two could drift and the status report could
   * describe a connection that did not exist.
   *
   * The default is five seconds, not one. One is aggressive for a cache read
   * over a network hop: a garbage collection pause on the Redis side, or the
   * few hundred milliseconds a failover takes, exceed it. Measured with a Redis
   * deaf for four seconds, a one-second limit turned a slow request into a 500
   * and took twelve of sixty requests in a burst with it, where the stock
   * backend served all sixty - slowly. At five, none of the sixty failed.
   *
   * Three things this has to get right, and each of them was got wrong before:
   *
   * A **non-numeric** setting is not a request for anything. `'0,5'` written
   * with a decimal comma, an empty string, or a getenv() that returned FALSE
   * because the variable is not set, all became 0.0 through a plain (float)
   * cast, and 0.0 meant "no limit" - so a typo silently removed the one
   * protection this class adds, and the status report called it deliberate.
   * The value is now refused, the default stands, and ::$state says so, so the
   * status report can show it as a problem.
   *
   * **Zero means the stock behaviour**, and stock is not "unlimited". The
   * stock factory calls ::pconnect() without a read timeout and never calls
   * ::setOption(), which leaves phpredis on php.ini's default_socket_timeout -
   * 60 seconds out of the box, not forever. Passing -1.0 here removed even
   * that: measured against a Redis deaf for 20 seconds with
   * default_socket_timeout at 3, stock gave up after 6.09 s and this held the
   * worker for the whole 20.26 s. Worse than the thing it was imitating.
   *
   * And it cannot be passed on as **0.0** literally: measured on phpredis
   * 6.3.0, ::connect() accepts 0.0 but ::setOption(OPT_READ_TIMEOUT, 0.0)
   * makes the next read fail with "socket error on read socket". This class
   * calls both. A default_socket_timeout of 0 or less is the one case that
   * genuinely means unlimited, and -1.0 is how phpredis is told that.
   *
   * @param array<string, mixed> $settings
   *   The connection settings.
   *
   * @return array{timeout: float, state: string, raw: mixed}
   *   The seconds to use; how it was decided - 'default', 'bounded', 'stock'
   *   or 'invalid'; and what was configured, for reporting an invalid one.
   */
  public static function resolveReadTimeout(#[\SensitiveParameter] array $settings): array {
    $raw = $settings['read_timeout'] ?? NULL;

    if ($raw === NULL) {
      return ['timeout' => static::DEFAULT_READ_TIMEOUT, 'state' => 'default', 'raw' => NULL];
    }
    if (!is_numeric($raw)) {
      return ['timeout' => static::DEFAULT_READ_TIMEOUT, 'state' => 'invalid', 'raw' => $raw];
    }

    $seconds = (float) $raw;
    if ($seconds > 0) {
      return ['timeout' => $seconds, 'state' => 'bounded', 'raw' => $raw];
    }

    return ['timeout' => static::stockReadTimeout(), 'state' => 'stock', 'raw' => $raw];
  }

  /**
   * What phpredis would wait for if this class configured nothing.
   *
   * @return float
   *   php.ini's default_socket_timeout, or -1.0 when that itself is unlimited.
   */
  protected static function stockReadTimeout(): float {
    $seconds = (float) ini_get('default_socket_timeout');

    return $seconds > 0 ? $seconds : -1.0;
  }

  /**
   * Asks the sentinels which server is currently the master.
   *
   * @param array<string, mixed> $settings
   *   The connection settings, with 'host' holding the list of sentinels and
   *   'instance' naming the monitored master.
   *
   * @return array{0: string, 1: int}|null
   *   The master's host and port, or NULL when no sentinel would name one.
   */
  protected function resolveMaster(#[\SensitiveParameter] array $settings): ?array {
    // The parent's discovery, on a throwaway client: it leaves the one it is
    // handed connected to whichever sentinel answered, and a sentinel is not
    // something the rest of the request should be talking to.
    $address = $this->askForMaster(new \Redis(), $settings);

    return is_array($address) && count($address) === 2
      ? [(string) $address[0], (int) $address[1]]
      : NULL;
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
   * The $context parameter landed in phpredis 5.3.0, and the extension version
   * is what decides it. This used to count the parameters of ::pconnect() by
   * reflection, which is the wrong instrument: 5.3.4 and 5.3.7 declare three
   * in their arginfo while accepting all seven at runtime. On those builds the
   * count came back short, the context was dropped, and with it went
   * $context['stream'] - which is to say verify_peer and verify_peer_name.
   *
   * A TLS connection to a Redis with a private CA then failed outright, and on
   * a build that did connect it would have been connecting without verifying
   * the certificate, silently, while the class docblock promised otherwise.
   * Neither is acceptable from a check nobody could see fail.
   *
   * Static so it can be called without an instance; the parent declares no
   * method of this name, so nothing is being overridden either way.
   */
  protected static function supportsConnectContext(): bool {
    static $supported;
    if ($supported === NULL) {
      $version = phpversion('redis');
      $supported = $version !== FALSE && version_compare($version, '5.3.0', '>=');
    }
    return $supported;
  }

}

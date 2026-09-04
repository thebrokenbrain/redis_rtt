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
 *
 * Those keys configure the connection to the Redis server. In a Sentinel
 * deployment - 'host' given as a list - they configure the connection to the
 * master, which is the connection every command then travels over, but not the
 * discovery exchange that finds it: that is the parent's, and it reaches each
 * sentinel in turn with a fixed 0.5 second connect timeout, in the clear, and
 * authenticates with the password alone, ignoring 'user'. Its read timeout is
 * the same unbounded default this class exists to replace, so a sentinel that
 * accepts the connection and then goes quiet blocks the worker exactly as a
 * stock Redis connection would. Two further Sentinel caveats worth knowing:
 * what a sentinel returns is an IP address, so 'tls' is then verified against
 * an IP and needs either a certificate carrying that IP or verify_peer FALSE;
 * and 'persistent' pools per host, so a failover opens a new pool rather than
 * reusing the old master's.
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
    // A list of hosts means Sentinel, so which server to connect to has to be
    // asked for first. Only the asking is deferred to the parent; the answer is
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

    return new FastPhpRedis($redis);
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

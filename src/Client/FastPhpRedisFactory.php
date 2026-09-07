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
 *   - read_timeout: (float) read timeout in seconds, default 1.0. Zero or
 *     less means no limit, which is the stock behaviour and the one this
 *     class exists to replace; see ::readTimeout().
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

    // SELECT unconditionally whenever a database is configured, exactly as the
    // stock factory does. Skipping it on the strength of ::getDbNum() looked
    // like a free round trip and is not: phpredis resets its own bookkeeping to
    // 0 on every pconnect() while the pooled socket stays on whatever database
    // it was left on, so the comparison reports 0 == 0 and sends nothing. Two
    // sites sharing an FPM pool and a Redis host - a multisite, or two vhosts -
    // then read and write each other's databases, which is a site serving
    // another site's data. Verified against phpredis 6.3.0, where after a
    // select(5) a fresh pconnect() reports getDbNum() = 0 while CLIENT INFO
    // reports db=5.
    $base = $settings['base'] ?? NULL;
    if ($base !== NULL) {
      $redis->select((int) $base);
    }

    $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
    // Detect a half-open connection - the common outcome of an AZ failover -
    // instead of blocking the worker until the FPM timeout.
    //
    // Redundant on phpredis 6.3.0, and knowingly kept: every ::connect() and
    // ::pconnect() branch above already carries $read_timeout, and measured
    // here a pconnect() that reuses a pooled socket reapplies it too (a socket
    // opened with 5.0 reports 0.25 after a second pconnect asking for 0.25).
    // Removing this line therefore changes nothing observable, which is why no
    // test asserts on it - a test that appeared to would in fact be asserting
    // on what ::connect() did. It stays as a safeguard for builds where the
    // connect parameter is ignored, not as behaviour anything depends on.
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, $read_timeout);
    if (defined('Redis::OPT_TCP_KEEPALIVE')) {
      $redis->setOption(\Redis::OPT_TCP_KEEPALIVE, 1);
    }

    return new FastPhpRedis($redis);
  }

  /**
   * Returns the read timeout to hand phpredis, in seconds.
   *
   * A non-positive setting means "no limit", which is what phpredis documents
   * and what an operator writing 0 is asking for. It cannot be passed on as
   * written, though, and the reason is not where this used to say it was.
   *
   * Measured on phpredis 6.3.0, against a real server, in four combinations:
   * connect() with 0.0 and no setOption() works and reports back 0.0;
   * connect() with the parameter omitted and setOption(OPT_READ_TIMEOUT, 0.0)
   * afterwards fails the next read with "socket error on read socket"; so does
   * passing 0.0 to both; and omitting both works. In other words the connect
   * parameter accepts zero perfectly well and ::setOption() is what rejects it.
   * This class calls both, so a site that set 0 got a RedisException on every
   * request and an HTTP 500 on every page, where the stock factory - which
   * calls neither - serves the site normally.
   *
   * Saying which of the two breaks matters, because the obvious "fix" suggested
   * by blaming connect() is to stop passing the parameter, and that still
   * leaves the setOption() call and still takes the site down. A negative value
   * is accepted by both and reaches the unlimited behaviour reliably, so that
   * is what a non-positive setting is normalised to.
   *
   * \Drupal\Tests\redis_rtt\Kernel\FastPhpRedisConnectionTest asserts this
   * against a real socket; nothing in tests/src/Unit can, because the double
   * there replaces ::connect() wholesale.
   *
   * Honouring it rather than refusing it is deliberate. An unbounded read is
   * the failure mode this class exists to avoid, and choosing it throws that
   * away - but it is an explicit setting, written by someone who wanted the
   * stock behaviour back, and silently overriding an operator is worse than
   * letting them have what they asked for.
   *
   * @param array<string, mixed> $settings
   *   The connection settings.
   *
   * @return float
   *   The read timeout, or a negative value meaning no limit.
   */
  protected function readTimeout(#[\SensitiveParameter] array $settings): float {
    $read_timeout = (float) ($settings['read_timeout'] ?? 1.0);

    return $read_timeout > 0 ? $read_timeout : -1.0;
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

}

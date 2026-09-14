<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Client;

use Drupal\redis\Client\PhpRedisFactory;
use Drupal\redis\ClientInterface;

/**
 * PhpRedis factory that configures the connection the stock one leaves bare.
 *
 * \Drupal\redis\Client\PhpRedisFactory calls pconnect() with nothing but host
 * and port. The consequential default is read_timeout, which is then php.ini's
 * default_socket_timeout - 60 seconds out of the box, and unlimited only where
 * php.ini itself says 0 or less. Either way it is far longer than a cache read
 * has any business taking: a connection dropped by a failover holds the PHP-FPM
 * worker for it rather than failing fast, which under load turns a thirty
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
    $resolved = static::resolveReadTimeout($settings);
    $read_timeout = $resolved['timeout'];
    // What reaches ::pconnect() is what the stock factory sends - 0.0 in the
    // stock state, which is what omitting the parameter gives. What reaches
    // ::setOption() below is not the same number, and the difference is the
    // whole point: see the note there.
    $in_force = $resolved['state'] === 'stock'
      ? (float) ini_get('default_socket_timeout')
      : $read_timeout;
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
    // instead of blocking the worker until the FPM timeout.
    //
    // Called unconditionally, and in the stock state with php.ini's number
    // rather than with the 0.0 that went to ::pconnect(). Skipping the call
    // there looks like the exact imitation of the stock factory and is one
    // only on a fresh socket: phpredis applies the connect parameter to the
    // stream just when it is greater than zero, so a pooled socket that
    // carried a bounded connection earlier in the pool keeps that bound.
    // Measured on phpredis 6.3.0: a connection with read_timeout 0.25
    // followed, on the same CLIENT ID, by one asking for the stock behaviour
    // with default_socket_timeout at 60 - the read died after 0.25 s, and the
    // status report said 60 s. It goes the other way too: with
    // default_socket_timeout at 1, a preceding read_timeout of 5 held the
    // worker 4.09 s where a fresh socket gave up at 1.00 s.
    //
    // Re-imposing php.ini's own number is not the translation this class used
    // to do. A default_socket_timeout of 0 means reads expire at once and one
    // below zero means never, and passing either of those to ::setOption()
    // reproduces what the stock factory's connection does with the same
    // php.ini - both measured against the twin, on a Redis that answers and on
    // one that does not.
    $redis->setOption(\Redis::OPT_READ_TIMEOUT, $in_force);
    if (defined('Redis::OPT_TCP_KEEPALIVE')) {
      $redis->setOption(\Redis::OPT_TCP_KEEPALIVE, 1);
    }

    return new PhpRedisRtt($redis);
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
   * **Zero means the stock behaviour**, which is php.ini's
   * default_socket_timeout: that is what bounds a connection the stock factory
   * opened. ::connect() reaches it by passing the same 0.0 that factory passes
   * and then imposing php.ini's own number on the connection - not by leaving
   * ::setOption() uncalled, which is an imitation that holds on a fresh socket
   * and breaks on a pooled one. See the note at that call.
   *
   * Two earlier attempts at this were wrong, in opposite directions. Passing
   * -1.0 removed even php.ini's bound: with default_socket_timeout at 3 and a
   * Redis deaf for 20 seconds, stock gave up after 6.09 s and this held the
   * worker for the whole 20.26 s. Copying php.ini's number into the connection
   * fixed that case and broke another: default_socket_timeout of 0 is not
   * "unlimited", it is a select() with a zero timeout, so it expires
   * immediately - and there stock served every request in under 120 ms while
   * this, still translating 0 to "no limit", held all six pool workers for the
   * full 40 s of the burst. Only the negative values mean forever.
   *
   * So nothing is invented any more: what is imposed in the stock state is
   * php.ini's number and nothing else, whatever it says. What must still never
   * reach ::setOption() is the literal 0.0 this method returns - measured on
   * phpredis 6.3.0, it makes the next read fail with "socket error on read
   * socket" - and it does not: it goes to ::pconnect(), where it is what
   * omitting the parameter means.
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

    // 0.0 is what the stock factory passes by leaving the parameter out, and
    // it is what ::connect() hands ::pconnect(). It is NOT what ends up in
    // force: 'stock' tells ::connect() and the status report to look php.ini's
    // default_socket_timeout up instead. The two are different questions and
    // this array only answers the first.
    return ['timeout' => 0.0, 'state' => 'stock', 'raw' => $raw];
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

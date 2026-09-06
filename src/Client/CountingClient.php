<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Client;

use Drupal\redis\ClientInterface;

/**
 * Wraps a Redis client and counts round trips.
 *
 * The number that matters in a cross-AZ topology is not the number of Redis
 * commands but the number of times PHP has to wait for the network. A pipeline
 * of forty commands is one wait; forty separate commands are forty. This
 * decorator counts both so the difference is visible, plus the wall time
 * actually spent blocked on Redis.
 *
 * Enable with $settings['redis.connection']['count_commands'] = TRUE. Intended
 * for a canary task or a load test, not for every task in production: the
 * bookkeeping itself is cheap but the per-command hrtime() calls are not free.
 */
class CountingClient implements ClientInterface {

  /**
   * Number of network waits.
   *
   * One per command sent on its own, and one per exec(). A pipeline is one
   * wait: phpredis holds the commands locally and exec() hands them over.
   *
   * A MULTI block is not, and counting it as one under-reports. phpredis sends
   * each command inside MULTI as it is called and reads back +QUEUED, so a
   * WATCH / GET / MULTI / DEL / EXEC release costs five waits, not three.
   * Nothing in this module uses MULTI - its locks and its batches are Lua - so
   * the error only ever ran against the stock backend it is compared with.
   */
  public static int $roundTrips = 0;

  /**
   * Number of Redis commands issued, pipelined or not.
   *
   * Not counting exec(), which batches nothing of its own: with ::pipeline()
   * the commands are held locally and exec() only hands them over, so it is a
   * wait rather than a command. ::$roundTrips counts it.
   */
  public static int $commands = 0;

  /**
   * Nanoseconds spent blocked on Redis.
   */
  public static int $nanoseconds = 0;

  /**
   * Commands seen per method name, for spotting the hot ones.
   *
   * @var array<string, int>
   */
  public static array $byCommand = [];

  /**
   * Whether a pipeline is currently open.
   */
  protected bool $inPipeline = FALSE;

  /**
   * Whether the open block is a MULTI, where queued commands still cost a wait.
   */
  protected bool $inMulti = FALSE;

  public function __construct(protected ClientInterface $inner) {}

  /**
   * {@inheritdoc}
   *
   * @param string $name
   *   The Redis command.
   * @param mixed[] $arguments
   *   The command arguments.
   *
   * @return mixed
   *   Whatever the underlying client returns.
   */
  public function __call(string $name, array $arguments) {
    $lower = strtolower($name);

    if ($lower === 'pipeline' || $lower === 'multi') {
      $this->inPipeline = TRUE;
      $this->inMulti = $lower === 'multi';
      if ($this->inMulti) {
        // MULTI itself is sent and acknowledged.
        static::$roundTrips++;
      }
      return $this->inner->__call($name, $arguments);
    }

    // exec() is not a Redis command here: ::pipeline() batches locally and
    // exec() is the PHP call that hands the batch over, so counting it would
    // report one command more than actually went to Redis - on a request with
    // thirty pipelines, thirty. It is still a network wait, and it is counted
    // as one below.
    if ($lower !== 'exec') {
      static::$commands++;
      static::$byCommand[$lower] = (static::$byCommand[$lower] ?? 0) + 1;
    }

    if ($this->inPipeline && $lower !== 'exec') {
      if ($this->inMulti) {
        // Sent now and answered with +QUEUED, so it is a wait like any other.
        static::$roundTrips++;
      }
      // Otherwise queued locally, with no network wait yet.
      return $this->inner->__call($name, $arguments);
    }

    $started = hrtime(TRUE);
    try {
      return $this->inner->__call($name, $arguments);
    }
    finally {
      static::$nanoseconds += hrtime(TRUE) - $started;
      static::$roundTrips++;
      if ($lower === 'exec') {
        $this->inPipeline = FALSE;
        $this->inMulti = FALSE;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Says so out loud: instrumentation is not free and should not be left on
   * fleet-wide, so it belongs in any report that names the client.
   */
  public function getName() {
    return $this->inner->getName() . ' (instrumented)';
  }

  /**
   * {@inheritdoc}
   */
  public function scan(string $match, int $count = 1000) {
    return $this->inner->scan($match, $count);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The Redis INFO payload.
   */
  public function info(): array {
    return $this->inner->info();
  }

  /**
   * {@inheritdoc}
   */
  public function addIgnorePattern(string $key): void {
    $this->inner->addIgnorePattern($key);
  }

  /**
   * Resets every counter.
   */
  public static function reset(): void {
    static::$roundTrips = 0;
    static::$commands = 0;
    static::$nanoseconds = 0;
    static::$byCommand = [];
  }

}

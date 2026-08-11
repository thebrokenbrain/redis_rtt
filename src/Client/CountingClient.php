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
   * Number of network waits: one per non-pipelined command, one per exec().
   */
  public static int $roundTrips = 0;

  /**
   * Number of Redis commands issued, pipelined or not.
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
      return $this->inner->__call($name, $arguments);
    }

    static::$commands++;
    static::$byCommand[$lower] = (static::$byCommand[$lower] ?? 0) + 1;

    if ($this->inPipeline && $lower !== 'exec') {
      // Queued locally, no network wait yet.
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

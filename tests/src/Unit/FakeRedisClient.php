<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * An in-memory Redis stand-in that counts network waits.
 *
 * Faithful enough for the commands the cache, lock and checksum backends use:
 * hashes, strings, DEL, MGET, INCR, EVAL of the specific scripts involved, and
 * phpredis' pipeline semantics where queued commands return the client and
 * exec() returns the ordered replies.
 *
 * The counter that matters is $roundTrips: one per command issued outside a
 * pipeline, one per exec(). That is what a cross-AZ hop actually costs, and it
 * is what the tests assert on - asserting on the number of commands would miss
 * the point entirely, since batching deliberately increases that number.
 */
final class FakeRedisClient implements ClientInterface {

  /**
   * Key/value store. Hashes are arrays, strings are strings.
   *
   * @var array<string, array<string, string>|string>
   */
  public array $data = [];

  /**
   * Network waits.
   */
  public int $roundTrips = 0;

  /**
   * Commands issued, pipelined or not.
   */
  public int $commands = 0;

  /**
   * Log of command names, in order.
   *
   * @var string[]
   */
  public array $log = [];

  /**
   * Queued pipeline commands.
   *
   * @var array<int, array{0: string, 1: mixed[]}>
   */
  private array $queue = [];

  /**
   * Whether a pipeline is open.
   */
  private bool $piping = FALSE;

  /**
   * {@inheritdoc}
   *
   * @param string $name
   *   The Redis command.
   * @param mixed[] $arguments
   *   The command arguments.
   *
   * @return mixed
   *   The reply, or $this while a pipeline is open.
   */
  public function __call(string $name, array $arguments) {
    $name = strtolower($name);

    if ($name === 'pipeline' || $name === 'multi') {
      if ($this->piping) {
        throw new \LogicException('Nested pipeline: phpredis cannot do this.');
      }
      $this->piping = TRUE;
      $this->queue = [];
      return $this;
    }

    if ($name === 'exec') {
      if (!$this->piping) {
        throw new \LogicException('exec() without an open pipeline.');
      }
      $this->piping = FALSE;
      $replies = [];
      foreach ($this->queue as [$command, $args]) {
        $replies[] = $this->run($command, $args);
      }
      $this->queue = [];
      $this->roundTrips++;
      return $replies;
    }

    $this->commands++;
    $this->log[] = $name;

    if ($this->piping) {
      $this->queue[] = [$name, $arguments];
      return $this;
    }

    $this->roundTrips++;
    return $this->run($name, $arguments);
  }

  /**
   * Executes a single command against the in-memory store.
   *
   * @param string $name
   *   The command name, lowercased.
   * @param mixed[] $args
   *   The arguments.
   *
   * @return mixed
   *   The reply.
   */
  private function run(string $name, array $args) {
    switch ($name) {
      case 'hgetall':
        return $this->data[$args[0]] ?? [];

      case 'hmset':
        $this->data[$args[0]] = array_map('strval', $args[1]);
        return TRUE;

      case 'hget':
        return $this->data[$args[0]][$args[1]] ?? FALSE;

      case 'hset':
        if (!isset($this->data[$args[0]])) {
          $this->data[$args[0]] = [];
        }
        $this->data[$args[0]][$args[1]] = (string) $args[2];
        return 1;

      case 'expire':
        // TTLs are not simulated; nothing here depends on eviction.
        return TRUE;

      case 'get':
        $value = $this->data[$args[0]] ?? FALSE;
        return is_array($value) ? FALSE : $value;

      case 'set':
        $options = $args[2] ?? [];
        if (is_array($options) && in_array('nx', $options, TRUE) && isset($this->data[$args[0]])) {
          return FALSE;
        }
        $this->data[$args[0]] = (string) $args[1];
        return TRUE;

      case 'del':
        $keys = is_array($args[0]) ? $args[0] : $args;
        $deleted = 0;
        foreach ($keys as $key) {
          if (isset($this->data[$key])) {
            unset($this->data[$key]);
            $deleted++;
          }
        }
        return $deleted;

      case 'mget':
        return array_map(fn ($key) => $this->data[$key] ?? FALSE, $args[0]);

      case 'incr':
        $this->data[$args[0]] = (string) (((int) ($this->data[$args[0]] ?? 0)) + 1);
        return (int) $this->data[$args[0]];

      case 'eval':
        return $this->runScript((string) $args[0], $args[1] ?? []);

      case 'watch':
      case 'unwatch':
      case 'discard':
        return TRUE;
    }

    throw new \LogicException("FakeRedisClient does not implement '$name'.");
  }

  /**
   * Interprets the Lua scripts the backends use.
   *
   * @param string $script
   *   The script body.
   * @param mixed[] $args
   *   Keys followed by arguments.
   *
   * @return int
   *   The script's return value.
   */
  private function runScript(string $script, array $args): int {
    // Cache entry invalidation.
    if (str_contains($script, "'valid'")) {
      $key = $args[0];
      $valid = $this->data[$key]['valid'] ?? FALSE;
      if ($valid && $valid !== '0' && $valid !== '') {
        $this->data[$key]['valid'] = '0';
        return 1;
      }
      return 0;
    }

    // Lock release.
    if (str_contains($script, 'DEL')) {
      [$key, $id] = $args;
      if (($this->data[$key] ?? NULL) === $id) {
        unset($this->data[$key]);
        return 1;
      }
      return 0;
    }

    // Lock extension.
    if (str_contains($script, 'PEXPIRE')) {
      [$key, $id] = $args;
      return ($this->data[$key] ?? NULL) === $id ? 1 : 0;
    }

    throw new \LogicException('FakeRedisClient met an unknown script.');
  }

  /**
   * Resets the counters, keeping the stored data.
   */
  public function resetCounters(): void {
    $this->roundTrips = 0;
    $this->commands = 0;
    $this->log = [];
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Fake';
  }

  /**
   * {@inheritdoc}
   */
  public function scan(string $match, int $count = 1000) {
    yield from [];
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   Always empty.
   */
  public function info(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function addIgnorePattern(string $key): void {}

}

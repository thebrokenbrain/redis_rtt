<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client whose EVAL fails by answering FALSE, without raising.
 *
 * This is what a rejected script actually looks like most of the time, and it
 * is not what \Drupal\Tests\redis_rtt\Unit\ScriptRefusingClient does. Measured
 * on phpredis 5.3.7 and 6.3.0 and on Relay 0.40.0, against Redis 7:
 *
 * - `rename-command EVAL ""` answers `ERR unknown command 'EVAL'`, which comes
 *   back as FALSE - or a reply set of FALSEs from a pipeline - with
 *   ::getLastError() set and nothing thrown, on both clients.
 * - An ACL without `eval` raises on phpredis, and on Relay raises only outside
 *   a pipeline: inside one it answers `[FALSE, ...]` with ::getLastError() set.
 *
 * So the double answers FALSE and records the reason, and the connection stays
 * usable afterwards, which is also what was measured.
 *
 * It runs its own pipeline rather than handing one to the inner client, so that
 * a queued EVAL can land in the reply set as FALSE while everything else is
 * answered normally.
 */
final class ScriptFailingClient implements ClientInterface {

  /**
   * How many times a script was attempted.
   */
  public int $scriptAttempts = 0;

  /**
   * The client's last-error slot.
   */
  protected string $lastError = '';

  /**
   * Whether a pipeline is open.
   */
  protected bool $piping = FALSE;

  /**
   * Commands queued in the open pipeline.
   *
   * @var array<int, array{0: string, 1: mixed[]}>
   */
  protected array $queue = [];

  /**
   * Constructs the double.
   *
   * @param \Drupal\Tests\redis_rtt\Unit\FakeRedisClient $inner
   *   The in-memory Redis everything but EVAL is served from.
   * @param string $error
   *   What Redis leaves in the last-error slot when the script is rejected.
   * @param string $name
   *   The client name, for the Predis guard.
   */
  public function __construct(
    protected FakeRedisClient $inner,
    protected string $error = "ERR unknown command 'EVAL', with args beginning with: ",
    protected string $name = 'ScriptFailing',
  ) {}

  /**
   * {@inheritdoc}
   *
   * @param string $name
   *   The Redis command.
   * @param mixed[] $arguments
   *   The command arguments.
   *
   * @return mixed
   *   FALSE for a script, whatever the inner client says otherwise.
   */
  public function __call(string $name, array $arguments) {
    $lower = strtolower($name);

    if ($lower === 'clearlasterror') {
      $this->lastError = '';
      return TRUE;
    }
    if ($lower === 'getlasterror') {
      return $this->lastError === '' ? NULL : $this->lastError;
    }

    if ($lower === 'pipeline' || $lower === 'multi') {
      $this->piping = TRUE;
      $this->queue = [];
      return $this;
    }
    if ($lower === 'close') {
      $this->piping = FALSE;
      $this->queue = [];
      return TRUE;
    }
    if ($lower === 'exec') {
      $this->piping = FALSE;
      $replies = [];
      foreach ($this->queue as [$command, $args]) {
        $replies[] = $this->one($command, $args);
      }
      $this->queue = [];
      return $replies;
    }

    if ($this->piping) {
      $this->queue[] = [$lower, $arguments];
      return $this;
    }

    return $this->one($lower, $arguments);
  }

  /**
   * Answers one command, queued or not.
   *
   * @param string $name
   *   The lowercased command name.
   * @param mixed[] $arguments
   *   The command arguments.
   *
   * @return mixed
   *   FALSE for a script, the inner client's answer otherwise.
   */
  protected function one(string $name, array $arguments) {
    if ($name === 'eval') {
      $this->scriptAttempts++;
      $this->lastError = $this->error;
      return FALSE;
    }

    return $this->inner->__call($name, $arguments);
  }

  /**
   * Puts a message in the last-error slot, as an earlier command would have.
   *
   * @param string $message
   *   What Redis said.
   */
  public function seedLastError(string $message): void {
    $this->lastError = $message;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->name;
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
   *   The inner client's info.
   */
  public function info(): array {
    return $this->inner->info();
  }

  /**
   * {@inheritdoc}
   */
  public function addIgnorePattern(string $key): void {}

}

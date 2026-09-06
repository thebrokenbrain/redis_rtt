<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client whose pipelines always fail, and that records being closed.
 *
 * Stands in for the one failure mode that matters here: a read timeout partway
 * through a pipeline. What follows on a real connection - replies left queued
 * on the socket, so the next command reads the previous one's - cannot be
 * simulated usefully, and is reproduced against a real Redis in
 * redis_rtt-auditoria instead. What can be asserted here is the part this
 * module controls: that the connection is taken out of service.
 */
final class FailingPipelineClient implements ClientInterface {

  /**
   * Whether ::close() has been called.
   */
  public bool $closed = FALSE;

  /**
   * {@inheritdoc}
   *
   * @param string $name
   *   The Redis command.
   * @param mixed[] $arguments
   *   The command arguments.
   *
   * @return mixed
   *   The client, for queued commands.
   */
  public function __call(string $name, array $arguments) {
    $name = strtolower($name);
    if ($name === 'close') {
      $this->closed = TRUE;
      return TRUE;
    }
    if ($name === 'exec') {
      throw new \RuntimeException('read error on connection');
    }

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'FailingPipeline';
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

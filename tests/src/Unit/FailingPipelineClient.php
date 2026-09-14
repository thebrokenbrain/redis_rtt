<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client whose pipelines always fail, and that records being closed.
 *
 * Stands in for the one failure mode that matters here: a read timeout partway
 * through a pipeline. What a real connection does next is a property of the
 * phpredis build, not of this module - see the note on
 * \Drupal\redis_rtt\Redis\Pipeline - so it is not simulated here. What can
 * be asserted is the part this module controls: that a connection whose
 * pipeline failed is taken out of service rather than reused.
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

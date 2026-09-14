<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client whose pipeline answers with less than was queued.
 *
 * Both shapes come from phpredis itself: ::exec() returns FALSE outright for
 * several connection states, and a reply set can come back shorter than the
 * queue after an error mid-flight. Neither raises. The point of the double is
 * that both look like an ordinary answer to the caller.
 */
final class TruncatingClient implements ClientInterface {

  /**
   * How to spoil the next reply set: 'short', 'false' or NULL for intact.
   */
  public ?string $spoil = NULL;

  public function __construct(protected FakeRedisClient $inner) {}

  /**
   * {@inheritdoc}
   *
   * @param string $name
   *   The Redis command.
   * @param mixed[] $arguments
   *   The command arguments.
   *
   * @return mixed
   *   The inner client's answer, spoiled on ::exec() when asked for.
   */
  public function __call(string $name, array $arguments) {
    $reply = $this->inner->__call($name, $arguments);
    if (strtolower($name) !== 'exec' || $this->spoil === NULL) {
      return $reply;
    }

    $spoil = $this->spoil;
    $this->spoil = NULL;
    if ($spoil === 'false') {
      return FALSE;
    }

    return is_array($reply) ? array_slice($reply, 0, max(0, count($reply) - 1)) : $reply;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Truncating';
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

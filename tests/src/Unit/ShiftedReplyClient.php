<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client whose pipeline answers the right number of replies, shifted by one.
 *
 * This is the shape a desynchronised socket really produces, measured against a
 * TCP proxy that drags a queue in from earlier: phpredis 5.3.7, phpredis 6.3.0
 * and Relay 0.40.0 all read exactly as many replies as commands were queued,
 * and the leftover arrives at the front. So the reply set is the right length
 * and every answer in it belongs to the command before it.
 *
 * A count check cannot see this. What refuses it is that every reply but the
 * marker is a hash, so the shift puts an array where the timestamp belongs.
 */
final class ShiftedReplyClient implements ClientInterface {

  /**
   * Whether the next ::exec() comes back shifted.
   */
  public bool $shifted = FALSE;

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
   *   The inner client's answer, shifted by one when asked for.
   */
  public function __call(string $name, array $arguments) {
    $reply = $this->inner->__call($name, $arguments);
    if (strtolower($name) !== 'exec' || !$this->shifted || !is_array($reply)) {
      return $reply;
    }

    $this->shifted = FALSE;
    // A leftover at the front, and the last real answer falls off the end:
    // same length, everything one position late.
    array_unshift($reply, 'sobra-de-antes');
    array_pop($reply);

    return $reply;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Shifted';
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

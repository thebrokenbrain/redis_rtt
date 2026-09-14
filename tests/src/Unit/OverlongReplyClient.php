<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client whose pipeline answers with more replies than were queued.
 *
 * A reply set longer than the queue, which is a shape no supported client has
 * been observed to produce. Measured against a TCP proxy that drags a real
 * queue in from earlier on the socket, phpredis 5.3.7, phpredis 6.3.0 and Relay
 * 0.40.0 all read exactly as many replies as commands were queued, and the
 * leftover arrives at the front: the set is the right length with its contents
 * shifted by one. See \Drupal\Tests\redis_rtt\Unit\ShiftedReplyClient for
 * that one.
 *
 * So this double pins a contract rather than a scenario: if a client ever did
 * hand back more replies than were asked for, the marker must not be read out
 * of them. The leftover goes last because that is where it would do damage -
 * array_pop() would take it as the timestamp. An earlier version of this
 * docblock claimed this was what phpredis does, which was wrong, and the
 * measurement that showed it also showed which check actually protects the
 * marker in the shape that can happen: is_scalar(), not the count.
 */
final class OverlongReplyClient implements ClientInterface {

  /**
   * Whether the next ::exec() carries one leftover reply.
   */
  public bool $overlong = FALSE;

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
   *   The inner client's answer, with a leftover prepended when asked for.
   */
  public function __call(string $name, array $arguments) {
    $reply = $this->inner->__call($name, $arguments);
    if (strtolower($name) !== 'exec' || !$this->overlong) {
      return $reply;
    }

    $this->overlong = FALSE;

    return is_array($reply) ? array_merge($reply, ['sobra']) : $reply;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Overlong';
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

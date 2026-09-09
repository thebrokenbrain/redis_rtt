<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client whose pipeline answers with more replies than were queued.
 *
 * The shape phpredis produces when a queue is dragged in from earlier on the
 * same socket: ::exec() hands back the leftovers along with the answers, so the
 * reply set is longer than what this request asked for.
 *
 * The leftover goes last, which is the position that does the damage, because
 * the marker is taken with array_pop(). A leftover at the front is harmless -
 * it is skipped for not being an array and the pop still finds the real marker
 * - so a double that put it there would exercise the count check without ever
 * making it matter, and every mutation of that check would survive.
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

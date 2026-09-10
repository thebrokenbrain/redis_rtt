<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client answering the shape phpredis really produces on a desynced socket.
 *
 * Measured through a TCP proxy that drags a queue in from earlier on the
 * socket, against phpredis 6.3.0 and four kinds of leftover. The reply set is
 * always the right length, so no count check can see it, and the leftover
 * arrives at the front - but phpredis does not hand back a clean shift the way
 * Relay does. Its parser desynchronises too, and the last slot comes back as a
 * raw fragment of the protocol: the string "*14", which is scalar and is not a
 * timestamp.
 *
 * That is the shape that used to be adopted as a flush marker, giving 0.0 -
 * 1970, "this bin was never emptied" - and serving entries a deleteAll() had
 * just retired.
 *
 * \Drupal\Tests\redis_rtt\Unit\ShiftedReplyClient is the same accident as Relay
 * produces it, where the last slot is an array instead.
 */
final class DesyncedReplyClient implements ClientInterface {

  /**
   * Whether the next ::exec() comes back desynchronised.
   */
  public bool $desynced = FALSE;

  /**
   * The raw protocol fragment phpredis leaves in the last slot.
   */
  public string $fragment = '*14';

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
   *   The inner client's answer, desynchronised when asked for.
   */
  public function __call(string $name, array $arguments) {
    $reply = $this->inner->__call($name, $arguments);
    if (strtolower($name) !== 'exec' || !$this->desynced || !is_array($reply)) {
      return $reply;
    }

    $this->desynced = FALSE;
    // Somebody else's answer at the front, everything one position late, and a
    // protocol fragment where the marker belongs. Same length as the queue.
    array_unshift($reply, [
      'cid' => 'ajeno',
      'created' => '1.0',
      'data' => 's:5:"AJENO";',
      'tags' => '',
      'checksum' => '0',
      'valid' => '1',
      'expire' => '-1',
      'serialized' => '1',
    ]);
    array_pop($reply);
    $reply[count($reply) - 1] = $this->fragment;

    return $reply;
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return 'Desynced';
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

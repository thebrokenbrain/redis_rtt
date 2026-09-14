<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;

/**
 * A client that answers everything except EVAL, which Redis refuses.
 *
 * Stands in for a Redis with scripting removed from the ACL, which several
 * managed providers ship by default. Everything else has to keep working,
 * because the point of the fallback is that the site does.
 */
final class ScriptRefusingClient implements ClientInterface {

  /**
   * How many times a script was attempted.
   */
  public int $scriptAttempts = 0;

  public function __construct(
    protected FakeRedisClient $inner,
    protected string $name = 'ScriptRefusing',
    protected string $message = "NOPERM User default has no permissions to run the 'eval' command",
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
   *   Whatever the inner client answers, for anything but EVAL.
   */
  public function __call(string $name, array $arguments) {
    if (strtolower($name) === 'eval') {
      $this->scriptAttempts++;
      throw new \RedisException($this->message);
    }
    return $this->inner->__call($name, $arguments);
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

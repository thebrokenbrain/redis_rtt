<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Kernel;

/**
 * Decides whether an unreachable Redis is a skip or a failure.
 *
 * The kernel tests are the only ones that exercise Redis, so a run in which
 * they all skip proves nothing at all - and it is indistinguishable from a
 * clean run at the two places anyone looks. PHPUnit reports the same number of
 * tests either way (137 here, with 627 assertions against 210) and exits 0 in
 * both, so a CI job that checks the exit status goes green having executed none
 * of the Redis work.
 *
 * The distinction this makes is the one that carries information: if the
 * environment says where Redis is, then not finding it there is a failure,
 * because somebody meant for it to be reachable. Only a run that never named a
 * server gets to skip.
 */
trait RedisAvailabilityTrait {

  /**
   * Returns the Redis this test should use, or skips when none was asked for.
   *
   * @return array{0: string, 1: int}
   *   The host and port.
   */
  protected function requireRedis(): array {
    $configured = getenv('REDIS_HOST') !== FALSE || getenv('REDIS_PORT') !== FALSE;
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);

    $socket = @fsockopen($host, $port, $errno, $error, 1);
    if ($socket === FALSE) {
      $message = sprintf('No Redis reachable at %s:%d (%s).', $host, $port, $error ?: "errno $errno");
      if ($configured) {
        // REDIS_HOST or REDIS_PORT was set, so a Redis was meant to be there.
        // Skipping here would turn a broken service into a green run.
        $this->fail($message . ' REDIS_HOST/REDIS_PORT are set, so this is a failure, not a skip.');
      }
      $this->markTestSkipped($message . ' Set REDIS_HOST and REDIS_PORT to run this.');
    }
    fclose($socket);

    return [$host, $port];
  }

}

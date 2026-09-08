<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Redis;

use Drupal\redis\ClientInterface;

/**
 * Cleanup for a pipeline that failed while its replies were still in flight.
 *
 * Everything in this module that opens a pipeline needs this, which is why it
 * does not live on any one of them:
 * \Drupal\redis_rtt\Cache\PipeliningRedisBackend::invalidateMultiple() and
 * \Drupal\redis_rtt\Lock\LuaRedisLock::releaseAll().
 */
final class Pipeline {

  /**
   * Closes a connection that failed mid-pipeline, so nothing reuses it.
   *
   * The hazard is a read timeout partway through a pipeline: replies left
   * queued on the socket, so the next command reads the *previous* command's
   * reply. On a persistent connection that socket is handed to the next
   * request, which is how a read of one key returns the value of another.
   *
   * How much of that is real depends on the phpredis build, and the honest
   * answer is version-dependent:
   *
   * - On phpredis 6.3.0 it does not happen. The extension marks the socket dead
   *   on the read error itself and drops it from the persistent pool, so the
   *   next pconnect() gets a fresh one; measured in round 12 by watching
   *   CLIENT ID change across the failure.
   * - Below that, it is unmeasured. drupal/redis suggests ext-redis
   *   "^4.0|^5.0", and this module constrains no version at all, so a site can
   *   be running a build where the reply queue does survive.
   *
   * The cleanup therefore stays: it costs one close() on a path that has
   * already failed, and it is the difference between a bad request and a bad
   * worker. What it must not do is claim to fix a thing nobody has reproduced
   * on the version in front of it.
   *
   * Stock redis never reaches this at all, and not because it handles it: it
   * sets no read timeout, so it waits out the stall instead of timing out. The
   * bounded read this module adds is the right trade, but it has to clean up
   * after itself.
   *
   * @param \Drupal\redis\ClientInterface|null $client
   *   The client whose connection failed, if there was one.
   */
  public static function discard(?ClientInterface $client): void {
    if (!$client) {
      return;
    }
    try {
      $client->close();
    }
    catch (\Exception) {
      // Already gone, which is the state being aimed for.
    }
  }

}

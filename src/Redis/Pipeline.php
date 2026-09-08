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
   * A read timeout inside a pipeline leaves phpredis holding a socket with
   * replies still queued on it, and the next command reads the *previous*
   * command's reply. On a persistent connection that socket is then handed to
   * the next request, which is how a read of one key returns the value of
   * another - measured across bins, a cache.render get answering with a
   * cache.entity value.
   *
   * Closing it costs nothing when Redis is healthy, because this only runs
   * after a failure, and phpredis reconnects on the next command.
   *
   * Stock redis never sees this, and not because it handles it: it sets no read
   * timeout at all, so it waits out the stall instead of timing out. The
   * bounded read this module adds is the right trade - an unbounded one turns a
   * failover into an outage - but it has to clean up after itself.
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

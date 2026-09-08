<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Redis;

use Drupal\redis\ClientInterface;

/**
 * Remembers that this connection's Redis will not run Lua.
 *
 * Two of this module's optimisations replace a multi-command protocol with a
 * single EVAL. That is the whole saving, and on an ordinary Redis it is free.
 * On a managed one it may not be: several providers ship with scripting
 * removed from the default ACL, and some operators disable it deliberately.
 *
 * Before this class existed, such a Redis took the site down. The first cache
 * invalidation raised an uncaught RedisException and every page became a 500,
 * with no setting to turn the EVAL off and no way back except editing
 * settings.php. That is a worse trade than the one the module offers: it sells
 * itself as costing nothing over stock, and refusing to serve pages is not
 * nothing.
 *
 * So the refusal is caught once, remembered for the process, and every call
 * site falls back to the implementation it inherits from the redis module -
 * the same commands the stock backend would have sent. The site keeps working
 * and loses only the round trips the script was saving.
 *
 * The flag is per process rather than per object because the connection is:
 * \Drupal\redis\ClientFactory holds one client in a static and hands it to
 * every bin, to the tag checksums and to the lock. One refusal answers for all
 * of them.
 */
final class Scripting {

  /**
   * Whether an EVAL has already been refused on this connection.
   */
  private static bool $refused = FALSE;

  /**
   * Whether to skip Lua entirely and use the inherited path.
   *
   * Two reasons to skip it. One is a Redis that refused a script earlier in
   * this process. The other is Predis, which the redis module also offers and
   * which takes eval() in protocol order - script, numkeys, then the keys -
   * where phpredis and Relay take the keys as one array and numkeys last.
   * Sending the phpredis shape to Predis puts an array where the key count
   * goes, Redis answers "value is not an integer", and every page 500s.
   *
   * That is worth detecting up front rather than learning from the failure,
   * because the failure does not look like a refusal and would propagate.
   *
   * @param \Drupal\redis\ClientInterface $client
   *   The connection the script would run on.
   *
   * @return bool
   *   TRUE when the script must not be attempted.
   */
  public static function unavailable(ClientInterface $client): bool {
    if (static::$refused) {
      return TRUE;
    }

    // Anchored, not a substring search: "PhpRedisRtt" contains "pRedis", so a
    // stripos() here matched this module's own client and switched Lua off
    // for everybody. The suite caught it; the note stays so it is not redone.
    return str_starts_with(strtolower((string) $client->getName()), 'predis');
  }

  /**
   * Whether an exception is Redis refusing to run a script.
   *
   * Matched on the message because phpredis reports server errors as
   * RedisException with no code to branch on. Both halves have to be present:
   * a refusal that names neither EVAL nor scripting is somebody else's problem
   * and must keep propagating, and a message naming EVAL without a refusal
   * marker is a script that failed on its own merits - a bug in this module,
   * which must not be quietly downgraded into a fallback.
   *
   * @param \Throwable $e
   *   The exception raised while running or queueing a script.
   *
   * @return bool
   *   TRUE if Redis refused to run scripts at all.
   */
  public static function refuses(\Throwable $e): bool {
    $message = $e->getMessage();
    if (stripos($message, 'eval') === FALSE && stripos($message, 'scripting') === FALSE) {
      return FALSE;
    }
    foreach (['NOPERM', 'unknown command', 'not allowed', 'disabled'] as $marker) {
      if (stripos($message, $marker) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Records that this connection refuses scripts.
   */
  public static function markRefused(): void {
    static::$refused = TRUE;
  }

  /**
   * Forgets the refusal.
   *
   * For tests, and for the case where the connection is replaced mid-process:
   * a failover can put a differently configured Redis behind the same client.
   */
  public static function reset(): void {
    static::$refused = FALSE;
  }

}

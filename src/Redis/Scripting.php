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
   * Matched on the message because there is no code to branch on. Both halves
   * have to be present: a refusal that names neither EVAL nor scripting is
   * somebody else's problem and must keep propagating, and a message naming
   * EVAL without a refusal marker is a script that failed on its own merits -
   * a bug in this module, which must not be quietly downgraded into a
   * fallback.
   *
   * An exception is only half of it. This used to be the whole detection, on
   * the strength of a docblock that said phpredis reports server errors as
   * RedisException. It does not: measured on phpredis 5.3.7 and 6.3.0, only a
   * short family - NOPERM, OOM, READONLY, BUSY, the AUTH ones - is raised, and
   * the whole -ERR class comes back as FALSE with ::getLastError() set and
   * nothing thrown. So a Redis with `rename-command EVAL ""` answered
   * `ERR unknown command 'EVAL'`, this returned FALSE, and the fallback never
   * fired: the invalidation did not invalidate, the lock was not released, and
   * the page still said 200. Relay does the same, and additionally does not
   * raise for an ACL refusal inside a pipeline. See ::refusedReply().
   *
   * @param \Throwable $e
   *   The exception raised while running or queueing a script.
   *
   * @return bool
   *   TRUE if Redis refused to run scripts at all.
   */
  public static function refuses(\Throwable $e): bool {
    return static::isRefusal($e->getMessage());
  }

  /**
   * Whether a script reply says the script did not run.
   *
   * The scripts in this module cannot answer FALSE on their own: the cache
   * invalidation returns 0 or 1, and the lock scripts return 0 or the result of
   * a DEL. A FALSE - or a FALSE anywhere in a pipeline's reply set - therefore
   * always means Redis rejected the command, whatever the reason.
   *
   * Which is why the caller must act on this and not only on ::refusedReply():
   * a rejection nobody recognises still has to be answered by doing the work
   * the inherited way, or the invalidation is silently lost. Only a recognised
   * refusal is worth remembering for the rest of the request.
   *
   * @param mixed $reply
   *   What ::eval() or ::exec() answered.
   *
   * @return bool
   *   TRUE if the script did not run.
   */
  public static function failedReply(mixed $reply): bool {
    if ($reply === FALSE) {
      return TRUE;
    }
    if (!is_array($reply)) {
      return FALSE;
    }
    foreach ($reply as $one) {
      if ($one === FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether a script reply is Redis refusing to run scripts at all.
   *
   * The other half of ::refuses(), for the rejections that arrive as a return
   * value instead of an exception - which is most of them. The reason is only
   * available from the client's own last-error slot, so ::clearError() has to
   * have been called before the script was sent or this reads a stale one.
   *
   * @param \Drupal\redis\ClientInterface $client
   *   The connection the script ran on.
   * @param mixed $reply
   *   What ::eval() or ::exec() answered.
   *
   * @return bool
   *   TRUE if Redis refused to run scripts at all.
   */
  public static function refusedReply(ClientInterface $client, mixed $reply): bool {
    if (!static::failedReply($reply)) {
      return FALSE;
    }

    return static::isRefusal(static::lastError($client));
  }

  /**
   * Empties the client's last-error slot before a script is sent.
   *
   * Without this, ::refusedReply() can read an error left by an unrelated
   * command earlier in the request. Local to the extension: no round trip, and
   * excluded from the round-trip counter for that reason.
   *
   * @param \Drupal\redis\ClientInterface $client
   *   The connection the script will run on.
   */
  public static function clearError(ClientInterface $client): void {
    try {
      $client->clearLastError();
    }
    catch (\Throwable) {
      // A client that does not offer one cannot leave a stale error either.
    }
  }

  /**
   * Reads the client's last-error slot.
   *
   * @param \Drupal\redis\ClientInterface $client
   *   The connection.
   *
   * @return string
   *   The message, or the empty string when there is none to be had.
   */
  protected static function lastError(ClientInterface $client): string {
    try {
      return (string) $client->getLastError();
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Whether a message from Redis is a refusal to run scripts.
   *
   * @param string $message
   *   The message, from an exception or from the client's last-error slot.
   *
   * @return bool
   *   TRUE if it names both a script and a refusal.
   */
  protected static function isRefusal(string $message): bool {
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

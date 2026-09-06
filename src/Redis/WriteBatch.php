<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Redis;

use Drupal\Core\Site\Settings;
use Drupal\redis\ClientFactory;
use Drupal\redis\ClientInterface;

/**
 * Sends the cache writes of bins that can recover from being wrong in batches.
 *
 * Building a page for the first time writes hundreds of render cache entries,
 * and the stock backend spends one network wait on each: measured on a heavy
 * site, 643 entries in 625 round trips. They can travel a hundred at a time for
 * the cost of one wait, and they are a little over half of all the writing a
 * cold page does.
 *
 * WHAT THIS IS NOT
 *
 * An earlier version of this module held the writes of *every* bin until the
 * end of the request. That opened a window in which another process could
 * delete a key and the flush then recreated it - an absent key is
 * indistinguishable from one that never existed. In cache.entity it made a
 * saved node revert, and the next save from the edit form wrote the stale
 * values back into the database. It was removed rather than defaulted off.
 *
 * This is deliberately much smaller, and only takes bins where resurrecting an
 * entry has been shown to be harmless:
 *
 * - Nothing deletes their keys individually. Verified on Drupal 10.6: no caller
 *   of VariationCache::delete() exists in core, RenderCache has no delete
 *   method at all, and MONITOR over a node save, an edit, three tag
 *   invalidations and a full cron recorded zero DEL/UNLINK/HDEL against the
 *   render bin out of 15,806 commands.
 * - They are invalidated through cache tags, and a resurrected entry carries
 *   the tag counter from before the invalidation, so it is discarded on read.
 *   Verified end to end: the four render entries of a node were dumped, the
 *   node was edited, the entries were restored byte for byte, and the page kept
 *   serving the new title.
 * - None of them is a chained-fast bin, so there is no "last write" marker that
 *   has to reach Redis behind the data it announces.
 *
 * cache.entity, cache.default and the chained-fast bins each fail one of those,
 * and they lose almost nothing by writing immediately: cache.entity already
 * writes 3,109 entries in 91 round trips, because Drupal saves entities in
 * bulk. Every other bin - including whatever bins a contrib module declares -
 * also writes immediately, because the list is of bins that have been checked,
 * not of bins that have been ruled out.
 *
 * WHAT IS STILL TRUE
 *
 * A contributed module that deletes a render key by hand is not covered.
 * Nothing stops it, and no measurement can rule it out - only bound it. A site
 * doing that should take its bin out of redis_rtt_batched_bins.
 *
 * Neither is a cache write issued from a destructor that runs after the last
 * shutdown function. ::sendOnShutdown() re-arms itself, which covers anything
 * written from another shutdown function, but PHP will not run a function
 * registered from that late in teardown. Core writes nothing there - what it
 * writes late (MenuActiveTrail, AliasManager, LibraryDiscoveryCollector) goes
 * out through DestructableInterface::destruct() during kernel.terminate, well
 * before shutdown, and is batched normally.
 */
class WriteBatch {

  /**
   * Applies a write only when it would not undo a newer one.
   *
   * A batched write carries the state captured when ::set() was called. Sent
   * unconditionally it overwrites whatever another process wrote to that key in
   * the meantime. Comparing the entry's own creation stamp orders the two, and
   * costs no round trip: the script travels in the pipeline the write was
   * already going to be in. Equal stamps let the write through, which is what
   * the stock backend does with two writes in the same millisecond.
   *
   * It cannot tell "deleted" from "never existed" - that is the limit described
   * in the class docblock, and the reason for the bin list.
   *
   * KEYS[1] is the key, ARGV[1] the creation stamp to compare, ARGV[2] the TTL
   * in seconds or an empty string for none, and ARGV[3..] the field/value
   * pairs.
   */
  protected const WRITE_IF_NOT_NEWER = <<<'LUA'
local stored = redis.call('HGET', KEYS[1], 'created')
if stored and tonumber(stored) and tonumber(stored) > tonumber(ARGV[1]) then
  return 0
end
redis.call('HMSET', KEYS[1], unpack(ARGV, 3))
if ARGV[2] ~= '' then
  redis.call('EXPIRE', KEYS[1], ARGV[2])
end
return 1
LUA;

  /**
   * Pending writes, keyed by the fully prefixed Redis key.
   *
   * @var array<string, array{hash: array<string, mixed>, ttl: int|null}>
   */
  protected array $pending = [];

  /**
   * Bins whose writes travel in a batch. Every other bin writes immediately.
   *
   * @var string[]
   */
  protected array $batchedBins;

  /**
   * How many pending writes force a send.
   */
  protected int $limit;

  /**
   * Whether batching is on at all.
   */
  protected bool $enabled;

  /**
   * Whether the end-of-request send has been registered.
   */
  protected bool $shutdownRegistered = FALSE;

  /**
   * Guards against a send nesting inside another one.
   */
  protected bool $sending = FALSE;

  /**
   * Pipelines actually sent.
   */
  protected int $sendCount = 0;

  /**
   * Entries handed over, whether or not they have been sent yet.
   */
  protected int $queuedWrites = 0;

  /**
   * Queued entries replaced by a later write before being sent.
   */
  protected int $supersededWrites = 0;

  /**
   * The Redis client, resolved on first use.
   */
  protected ?ClientInterface $client = NULL;

  /**
   * Constructs the batch.
   *
   * @param \Drupal\redis\ClientFactory $clientFactory
   *   Resolved lazily, so building this service opens no connection.
   * @param \Drupal\Core\Site\Settings|null $settings
   *   (optional) Falls back to the static accessor, because cache backends are
   *   built before the container is available.
   */
  public function __construct(
    protected ClientFactory $clientFactory,
    ?Settings $settings = NULL,
  ) {
    $get = $settings
      ? fn (string $name, mixed $default): mixed => $settings->get($name, $default)
      : fn (string $name, mixed $default): mixed => Settings::get($name, $default);

    $this->enabled = (bool) $get('redis_rtt_batch_writes', TRUE);
    $this->limit = max(1, (int) $get('redis_rtt_max_batched_writes', 100));
    // A list of what may be batched, not a list of what may not. The three
    // conditions above were checked against these four bins one at a time; a
    // bin nobody has checked has not earned the benefit of the doubt, and an
    // exclusion list would hand it to every bin a contrib module adds. That
    // inverted default is what the removed version got wrong.
    $this->batchedBins = (array) $get('redis_rtt_batched_bins', [
      'render',
      'data',
      'menu',
      'dynamic_page_cache',
    ]);
  }

  /**
   * Whether writes of this bin travel in a batch.
   *
   * @param string $bin
   *   The cache bin.
   *
   * @return bool
   *   TRUE when the caller may hand writes of that bin to ::add(). Unknown bins
   *   are not batched.
   */
  public function handles(string $bin): bool {
    return $this->enabled && in_array($bin, $this->batchedBins, TRUE);
  }

  /**
   * Queues a write.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   * @param array<string, mixed> $hash
   *   The entry, already built by the caller - including its cache tag
   *   checksum, so that waiting cannot change what ends up stored.
   * @param int|null $ttl
   *   Lifetime in seconds, or NULL for none.
   */
  public function add(string $key, array $hash, ?int $ttl): void {
    if (isset($this->pending[$key])) {
      $this->supersededWrites++;
    }
    $this->pending[$key] = ['hash' => $hash, 'ttl' => $ttl];
    $this->queuedWrites++;

    $this->registerShutdown();

    if (count($this->pending) >= $this->limit) {
      $this->send();
    }
  }

  /**
   * Returns a queued entry, so a read never misses this process' own write.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   *
   * @return array<string, mixed>|null
   *   The queued entry as it would be read back, or NULL if nothing is queued
   *   for that key.
   */
  public function getPending(string $key): ?array {
    return $this->pending[$key]['hash'] ?? NULL;
  }

  /**
   * Drops queued writes for the given keys.
   *
   * Called before a delete reaches Redis, so that this process cannot undo its
   * own delete. The guarantee stops at the process boundary.
   *
   * @param string[] $keys
   *   Fully prefixed Redis keys.
   */
  public function drop(array $keys): void {
    foreach ($keys as $key) {
      unset($this->pending[$key]);
    }
  }

  /**
   * Drops every queued write whose key starts with a prefix, for ::deleteAll().
   *
   * @param string $prefix
   *   The fully prefixed bin key prefix, including its trailing separator.
   */
  public function dropByPrefix(string $prefix): void {
    foreach (array_keys($this->pending) as $key) {
      if (str_starts_with($key, $prefix)) {
        unset($this->pending[$key]);
      }
    }
  }

  /**
   * Marks a queued entry invalid in place, without a round trip.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   *
   * @return bool
   *   TRUE if the entry was queued and has been invalidated, so that the caller
   *   does not also have to send an invalidation for it.
   */
  public function invalidatePending(string $key): bool {
    if (!isset($this->pending[$key])) {
      return FALSE;
    }
    $this->pending[$key]['hash']['valid'] = 0;
    return TRUE;
  }

  /**
   * Sends everything queued as a single pipeline.
   *
   * A failure propagates, exactly as the stock backend's write does. Batching
   * changes how many waits a request spends, and it should not quietly change
   * whether a request notices that Redis has stopped accepting writes: a full
   * instance under a noeviction policy, or a replica promoted to read-only,
   * both answer reads and refuse writes, and stock returns a 500 for that.
   *
   * The queue is emptied before the send, so a failure drops those entries
   * rather than holding them for the next attempt. Re-queueing would risk
   * sending an entry that a delete has since made wrong, which is the whole
   * failure this design exists to avoid; and they are cache entries, so the
   * cost of dropping them is that something recomputes them.
   *
   * @throws \Exception
   *   Whatever the client raises. ::sendQuietly() is the variant for callers
   *   with no request left to fail.
   */
  public function send(): void {
    if ($this->sending || !$this->pending) {
      return;
    }
    // Taken out before the first call, so that a write triggered from inside
    // serialization cannot be sent twice or lost.
    $writes = $this->pending;
    $this->pending = [];
    $this->sending = TRUE;

    try {
      $client = $this->client ??= $this->clientFactory->getClient();
      $client->pipeline();
      foreach ($writes as $key => $write) {
        $client->eval(
          static::WRITE_IF_NOT_NEWER,
          $this->arguments($key, $write['hash'], $write['ttl']),
          1
        );
      }
      $client->exec();
      $this->sendCount++;
    }
    finally {
      $this->sending = FALSE;
    }
  }

  /**
   * Sends everything queued, reporting a failure rather than raising it.
   *
   * This is what the end of the request uses. By then the response has been
   * sent - in PHP-FPM the client already has every byte of it - so there is no
   * request left to fail, and an exception here would only put a fatal in the
   * log after the fact. Everything else calls ::send() and gets the stock
   * behaviour.
   */
  public function sendQuietly(): void {
    try {
      $this->send();
    }
    catch (\Exception $e) {
      if (Settings::get('redis_rtt_log_errors', FALSE)) {
        // phpcs:ignore Drupal.Semantics.FunctionTriggerError
        trigger_error('redis_rtt: batched write failed: ' . $e->getMessage(), E_USER_WARNING);
      }
    }
  }

  /**
   * Flattens an entry into the arguments ::WRITE_IF_NOT_NEWER expects.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   * @param array<string, mixed> $hash
   *   The entry fields.
   * @param int|null $ttl
   *   Lifetime in seconds, or NULL for none.
   *
   * @return array<int, string>
   *   The key, the creation stamp, the TTL, then the field/value pairs.
   */
  protected function arguments(string $key, array $hash, ?int $ttl): array {
    $arguments = [$key, (string) ($hash['created'] ?? 0), $ttl === NULL ? '' : (string) $ttl];
    foreach ($hash as $field => $value) {
      $arguments[] = (string) $field;
      $arguments[] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
    }
    return $arguments;
  }

  /**
   * What the batch did during this request.
   *
   * @return array{batches: int, writes: int, pending: int, superseded: int}
   *   Pipelines sent, entries queued, entries still waiting, and entries
   *   replaced before they were sent.
   */
  public function getStats(): array {
    return [
      'batches' => $this->sendCount,
      'writes' => $this->queuedWrites,
      'pending' => count($this->pending),
      'superseded' => $this->supersededWrites,
    ];
  }

  /**
   * Sends at the end of the request, and re-arms for anything written after.
   *
   * The re-arming is the point. A shutdown function that writes to cache runs
   * *after* this one, and without a fresh registration its writes would sit in
   * the queue until the process died: no exception, no log, and - worse - the
   * previous version of the key still being served while the code that updated
   * it had every reason to believe it had. Registering again from inside a
   * shutdown function works: both PHP and Drupal's own dispatcher walk the list
   * by index, so entries appended while it runs are picked up.
   *
   * ::send() and ::sendQuietly() deliberately do not touch the flag. A batch
   * that goes out mid-request leaves the registration standing, because it has
   * not run yet and will still catch everything written later in the request.
   */
  public function sendOnShutdown(): void {
    $this->sendQuietly();
    $this->shutdownRegistered = FALSE;
  }

  /**
   * Registers the end-of-request send, unless one is already pending.
   */
  protected function registerShutdown(): void {
    if ($this->shutdownRegistered) {
      return;
    }
    $this->shutdownRegistered = TRUE;
    if (function_exists('drupal_register_shutdown_function')) {
      drupal_register_shutdown_function([$this, 'sendOnShutdown']);
    }
    else {
      register_shutdown_function([$this, 'sendOnShutdown']);
    }
  }

}

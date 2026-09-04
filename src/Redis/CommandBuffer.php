<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Redis;

use Drupal\Core\Site\Settings;
use Drupal\redis\ClientFactory;
use Drupal\redis\ClientInterface;

/**
 * Collects cache writes and flushes them as one pipeline at end of request.
 *
 * The stock \Drupal\redis\Cache\RedisBackend pipelines the items of a single
 * ::setMultiple() call, but each bin issues its own pipeline and most Drupal
 * code writes one item at a time. An authenticated request therefore performs
 * dozens of independent write round trips (render, dynamic_page_cache, data,
 * entity, menu, default...). On a cross-AZ ElastiCache primary each one costs a
 * full network round trip.
 *
 * This buffer keys pending writes by the final Redis key, which gives three
 * wins at once:
 *   - All writes of the request collapse into a single pipeline (1 round trip).
 *   - Repeated writes to the same key within a request are deduplicated; only
 *     the last one is sent.
 *   - Deletes and invalidations can be resolved against pending writes without
 *     touching the network at all.
 *
 * Ordering is preserved for correctness within this process: a delete of a
 * pending key drops the pending write instead of racing with it. Deletes issued
 * by *other* processes are not seen; ::flush() documents what that costs.
 *
 * Chained-fast "last write timestamp" markers are queued here too, and are
 * always sent after the writes, in the same pipeline. ::queueMarker() explains
 * why publishing a marker ahead of its data is the one reordering that cannot
 * be tolerated.
 *
 * Durability note: buffered entries are cache data only. If the PHP worker dies
 * before shutdown the entries are simply not written and are recomputed on the
 * next request. Cache tag invalidations are never buffered - those are the
 * source of truth for consistency and always go out immediately.
 */
class CommandBuffer implements CommandBufferInterface {

  /**
   * Pending hash writes, keyed by the fully prefixed Redis key.
   *
   * Each value is ['hash' => array, 'ttl' => int|null].
   *
   * @var array<string, array{hash: array<string, mixed>, ttl: int|null}>
   */
  protected array $writes = [];

  /**
   * Pending "last write timestamp" markers, keyed by the fully prefixed key.
   *
   * Kept for the life of the process rather than dropped once sent: 'dirty'
   * says whether the copy in Redis still covers everything this process has
   * queued for that bin, and 'value' is the highest timestamp any caller has
   * asked for, so a marker can never be published moving backwards.
   *
   * @var array<string, array{value: float, prefix: string, dirty: bool}>
   */
  protected array $markers = [];

  /**
   * Whether the shutdown flush has been registered.
   */
  protected bool $shutdownRegistered = FALSE;

  /**
   * Guards against re-entrant flushes (a flush must never nest a pipeline).
   */
  protected bool $flushing = FALSE;

  /**
   * Maximum number of pending keys before an intermediate flush is forced.
   */
  protected int $maxPending;

  /**
   * Whether buffering is enabled at all.
   */
  protected bool $enabled;

  /**
   * Number of pipelines actually sent, for instrumentation.
   */
  protected int $flushCount = 0;

  /**
   * Number of writes that were deduplicated away, for instrumentation.
   */
  protected int $dedupedWrites = 0;

  /**
   * The Redis client, resolved on first use.
   *
   * @var \Drupal\redis\ClientInterface|null
   */
  protected ?ClientInterface $client = NULL;

  /**
   * Constructs a CommandBuffer.
   *
   * @param \Drupal\redis\ClientFactory $clientFactory
   *   The Redis client factory. Resolved lazily so that merely building this
   *   service does not open a connection.
   * @param \Drupal\Core\Site\Settings|null $settings
   *   (optional) The settings object. Falls back to the static accessor when
   *   absent, because this object can be built before the container exists.
   */
  public function __construct(
    protected ClientFactory $clientFactory,
    ?Settings $settings = NULL,
  ) {
    $get = $settings
      ? fn (string $key, $default) => $settings->get($key, $default)
      : fn (string $key, $default) => Settings::get($key, $default);
    $this->enabled = (bool) $get('redis_rtt_defer_writes', TRUE);
    $this->maxPending = (int) $get('redis_rtt_max_pending_writes', 512);
  }

  /**
   * Returns whether write buffering is active.
   */
  public function isEnabled(): bool {
    return $this->enabled;
  }

  /**
   * Queues a cache hash write.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   * @param array<string, mixed> $hash
   *   The hash fields as built by the cache backend.
   * @param int|null $ttl
   *   The TTL in seconds, or NULL for no expiry.
   */
  public function queueWrite(string $key, array $hash, ?int $ttl): void {
    if (isset($this->writes[$key])) {
      $this->dedupedWrites++;
    }
    $this->writes[$key] = ['hash' => $hash, 'ttl' => $ttl];

    // This entry becomes visible to the rest of the cluster when it is sent,
    // which is later than the timestamp any marker for its bin is carrying
    // right now. Re-arm that marker so it is republished with the write.
    foreach ($this->markers as $marker_key => $marker) {
      if (!$marker['dirty'] && str_starts_with($key, $marker['prefix'])) {
        $this->markers[$marker_key]['dirty'] = TRUE;
      }
    }

    $this->registerShutdownFlush();

    if (count($this->writes) >= $this->maxPending) {
      $this->flush();
    }
  }

  /**
   * Queues a chained-fast "last write timestamp" marker.
   *
   * \Drupal\Core\Cache\ChainedFastBackend keeps one timestamp per bin in the
   * consistent backend and throws away every fast-backend entry older than it.
   * The marker is therefore a promise to the other web nodes: everything
   * written to this bin before this moment is already readable here. Deferring
   * the data without deferring the marker breaks that promise in the worst
   * possible way. Another node reads the marker, discards its own copy as
   * stale, re-primes it from a Redis that does not hold the new value yet, and
   * stamps the re-primed copy *later* than the marker. Nothing moves the marker
   * again, so that node serves pre-write data until something else writes to
   * the bin - which cache tag invalidation and a cache rebuild on the writing
   * node will not fix.
   *
   * Two things are needed to close that, and neither is sufficient alone:
   *   - The marker leaves after every queued write, in the same pipeline, so it
   *     can never be visible before the data it describes.
   *   - Its value is raised to the moment it is actually sent, because a
   *     timestamp taken when the write was queued is still older than a copy
   *     another node primed while the write sat in this buffer.
   *
   * @param string $key
   *   The fully prefixed Redis key of the marker.
   * @param float $value
   *   The timestamp the caller wants published, treated as a lower bound.
   * @param string $prefix
   *   The key prefix of the bin this marker describes, so that a later write to
   *   that bin can re-arm it.
   */
  public function queueMarker(string $key, float $value, string $prefix): void {
    $this->markers[$key] = [
      'value' => max($this->markers[$key]['value'] ?? 0.0, $value),
      'prefix' => $prefix,
      'dirty' => TRUE,
    ];

    // Armed before the immediate path too, not only on the deferred one: if the
    // write below fails - a failover, a moment of READONLY on a replica - the
    // marker stays dirty and something has to come back for it. Without this,
    // a process whose only buffered work was a bin clear would end with the bin
    // cleared in Redis and its marker never published.
    $this->registerShutdownFlush();

    // A marker only has to wait when this buffer is holding data for its bin
    // that the marker would otherwise announce before it is readable. With
    // nothing pending there is nothing to be ordered behind, and waiting is
    // actively harmful: core writes this marker from ::delete(),
    // ::invalidate() and ::deleteAll() too, and those reach Redis immediately.
    // Holding their marker back withholds the invalidation from every other
    // web node - for the rest of the process, since nothing flushes a marker on
    // its own - while they keep serving what was just cleared.
    if (!$this->hasPendingWritesFor($prefix)) {
      $this->flushMarkers();
    }
  }

  /**
   * Whether any buffered write belongs to the given bin.
   *
   * @param string $prefix
   *   The key prefix of the bin.
   *
   * @return bool
   *   TRUE when at least one pending write would be announced by that bin's
   *   marker.
   */
  protected function hasPendingWritesFor(string $prefix): bool {
    foreach (array_keys($this->writes) as $key) {
      if (str_starts_with($key, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Publishes the markers that have nothing buffered to wait for.
   *
   * A marker whose bin does have pending writes is deliberately left alone: it
   * is waiting for them, and publishing it here would announce them before
   * Redis holds them, which is the whole failure this class exists to prevent.
   * So this is per bin, never "every dirty marker" - a delete in one bin must
   * not drag another bin's marker out ahead of its data.
   *
   * The write costs one round trip, exactly what the stock backend would have
   * spent, and for the same reason: the other web nodes cannot be left
   * believing their copy of a bin that was just cleared is still current.
   */
  protected function flushMarkers(): void {
    if ($this->flushing) {
      return;
    }

    $dirty = array_filter(
      $this->markers,
      fn (array $marker): bool => $marker['dirty'] && !$this->hasPendingWritesFor($marker['prefix']),
    );
    if (!$dirty) {
      return;
    }

    $stamp = round(microtime(TRUE) + .001, 3);
    try {
      $client = $this->getClient();
      foreach ($dirty as $key => $marker) {
        $client->set($key, max($marker['value'], $stamp));
        $this->markers[$key]['dirty'] = FALSE;
      }
    }
    catch (\Exception $e) {
      // Left dirty, so a later flush retries it. Never fatal, for the same
      // reason a failed pipeline is not: this is cache metadata, and the cost
      // of losing it is a re-read.
      if (Settings::get('redis_rtt_log_errors', FALSE)) {
        // phpcs:ignore Drupal.Semantics.FunctionTriggerError
        trigger_error('redis_rtt: marker write failed: ' . $e->getMessage(), E_USER_WARNING);
      }
    }
  }

  /**
   * Makes sure a flush will happen before the process ends.
   *
   * The registration is repeated after every flush, so that anything queued
   * once the buffer has already been drained - a cache write from a later
   * shutdown function, or a marker the chained-fast backend defers - still gets
   * a pipeline of its own. PHP appends functions registered during shutdown to
   * the list it is walking, so they do run.
   */
  protected function registerShutdownFlush(): void {
    if ($this->shutdownRegistered) {
      return;
    }
    $this->shutdownRegistered = TRUE;
    // Runs after fastcgi_finish_request(), so these round trips are off the
    // critical path of the response the user is waiting for. Falls back to
    // the plain PHP function because this object can be built before
    // core/includes/bootstrap.inc has been loaded.
    if (function_exists('drupal_register_shutdown_function')) {
      drupal_register_shutdown_function([$this, 'flush']);
    }
    else {
      register_shutdown_function([$this, 'flush']);
    }
  }

  /**
   * Returns a pending write, if any.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   *
   * @return array<string, mixed>|null
   *   The pending hash, or NULL if the key is not buffered.
   */
  public function getPendingHash(string $key): ?array {
    return $this->writes[$key]['hash'] ?? NULL;
  }

  /**
   * Drops pending writes for the given keys.
   *
   * Called before a delete reaches Redis so that a buffered write cannot
   * resurrect an entry this process deleted. It can only inspect this
   * process's own pending writes; see ::flush() for the cross-process case.
   *
   * @param string[] $keys
   *   Fully prefixed Redis keys.
   */
  public function dropWrites(array $keys): void {
    foreach ($keys as $key) {
      unset($this->writes[$key]);
    }
  }

  /**
   * Marks a pending write as invalid without a round trip.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   *
   * @return bool
   *   TRUE if the key was pending and has been invalidated in place, FALSE if
   *   the caller still has to invalidate it in Redis.
   */
  public function invalidatePending(string $key): bool {
    if (!isset($this->writes[$key])) {
      return FALSE;
    }
    $this->writes[$key]['hash']['valid'] = 0;
    return TRUE;
  }

  /**
   * Drops every pending write whose key starts with the given prefix.
   *
   * Used by deleteAll() / removeBin() so a buffered write cannot survive a bin
   * wipe.
   *
   * @param string $prefix
   *   The fully prefixed bin key prefix.
   */
  public function dropWritesByPrefix(string $prefix): void {
    foreach (array_keys($this->writes) as $key) {
      if (str_starts_with($key, $prefix)) {
        unset($this->writes[$key]);
      }
    }
  }

  /**
   * Sends every pending write as a single pipeline.
   *
   * Writes are replayed exactly as they were captured: there is no
   * compare-and-set and no re-read of the key. A DEL issued by *another*
   * process while an entry sat in this buffer is therefore undone here, which
   * brings the entry back with its pre-delete data until it expires or is
   * written again. Catching that would need a tombstone written by the deleter
   * and read by this flush: memory on every delete, a lifetime that has to
   * outlive the longest buffer window, and it would still miss deletes from
   * processes that do not run this module, so it is deliberately not done.
   * ::deleteAll() is not exposed to this - it stamps a marker that the backend
   * compares against every entry's creation time when reading, so a resurrected
   * entry older than the marker is ignored. A single-key delete has no
   * equivalent marker.
   */
  public function flush(): void {
    if ($this->flushing) {
      return;
    }

    // The marker must claim a moment no earlier than the writes it travels
    // with, and those only become visible now. Same millisecond handling as
    // core's ChainedFastBackend::markAsOutdated(), for the same reason: an
    // entry another node stamped earlier in this millisecond may predate them.
    $stamp = round(microtime(TRUE) + .001, 3);
    $markers = [];
    foreach ($this->markers as $key => $marker) {
      if ($marker['dirty']) {
        $markers[$key] = max($marker['value'], $stamp);
      }
    }

    if (!$this->writes && !$markers) {
      return;
    }

    $this->flushing = TRUE;
    $writes = $this->writes;
    $this->writes = [];

    try {
      $client = $this->getClient();
      $client->pipeline();
      foreach ($writes as $key => $write) {
        $client->hMset($key, $write['hash']);
        if (isset($write['ttl'])) {
          $client->expire($key, $write['ttl']);
        }
      }
      // Last in the pipeline, always: Redis runs the commands in the order they
      // arrive, so a reader can see the data without the marker - harmless, it
      // just costs that reader one more round trip later - but never the marker
      // without the data.
      foreach ($markers as $key => $value) {
        $client->set($key, $value);
      }
      $client->exec();
      // Only now is the copy in Redis known to cover what was queued. If the
      // pipeline threw, the markers stay dirty and the next flush retries them.
      foreach (array_keys($markers) as $key) {
        $this->markers[$key]['dirty'] = FALSE;
      }
      $this->flushCount++;
    }
    catch (\Exception $e) {
      // A cache write failure must never take down the request. The entries are
      // simply recomputed next time.
      if (Settings::get('redis_rtt_log_errors', FALSE)) {
        // phpcs:ignore Drupal.Semantics.FunctionTriggerError
        trigger_error('redis_rtt: buffered cache flush failed: ' . $e->getMessage(), E_USER_WARNING);
      }
    }
    finally {
      $this->flushing = FALSE;
      // Anything queued from here on needs a flush of its own.
      $this->shutdownRegistered = FALSE;
    }
  }

  /**
   * Returns instrumentation counters.
   *
   * @return array{pipelines: int, pending: int, deduped: int}
   *   Counters describing what the buffer did this request.
   */
  public function getStats(): array {
    return [
      'pipelines' => $this->flushCount,
      'pending' => count($this->writes),
      'deduped' => $this->dedupedWrites,
    ];
  }

  /**
   * Lazily resolves the Redis client.
   */
  protected function getClient(): ClientInterface {
    return $this->client ??= $this->clientFactory->getClient();
  }

}

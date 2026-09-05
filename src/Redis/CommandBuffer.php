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
   * Marker key of every chained-fast bin that has queued a write, by prefix.
   *
   * Needed because a bin can go a whole process without core handing over a
   * marker, and a buffered write still has to be announced. See ::flush().
   *
   * @var array<string, string>
   */
  protected array $bins = [];

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
   * Records which marker key describes a bin that is buffering writes.
   *
   * A bin can go a whole process without core handing over a marker - on
   * Drupal 11.2 markAsOutdated() skips the write while the published stamp is
   * still in the future - and a buffered write still has to be announced.
   *
   * @param string $prefix
   *   The key prefix of the bin.
   * @param string $marker_key
   *   The fully prefixed Redis key of that bin's chained-fast marker.
   */
  public function registerBin(string $prefix, string $marker_key): void {
    $this->bins[$prefix] = $marker_key;
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

  /**
   * {@inheritdoc}
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
   * Applies a buffered write only when it would not undo a newer one.
   *
   * A buffered write carries the state captured when ::set() was called. Sent
   * unconditionally it overwrites whatever another process wrote to that key in
   * the meantime, and for bins whose entries carry no cache tags - cache_config
   * above all, whose entries never expire - nothing would ever correct it: the
   * database holds the new configuration and Redis the old one, permanently.
   *
   * Comparing the entry's own creation stamp is enough to order two writes, and
   * it costs no round trip: the script travels in the same pipeline the write
   * was already in.
   *
   * What this does NOT cover, and cannot without a tombstone: a key another
   * process DELETED during the buffer window is absent, not newer, so the write
   * recreates it. That is survivable only because the entries with no way of
   * recovering from it never reach this buffer - see ::flush() and
   * \Drupal\redis_rtt\Cache\DeferredRedisBackend::setMultiple().
   */
  private const WRITE_IF_NOT_NEWER = <<<'LUA'
    local stored = redis.call('HGET', KEYS[1], 'created')
    if stored and tonumber(stored) and tonumber(stored) > tonumber(ARGV[1]) then
      return 0
    end
    redis.call('HMSET', KEYS[1], unpack(ARGV, 2))
    return 1
    LUA;

  /**
   * Flattens an entry into the argument list ::WRITE_IF_NOT_NEWER expects.
   *
   * @param string $key
   *   The fully prefixed Redis key.
   * @param array<string, mixed> $hash
   *   The entry fields.
   *
   * @return array<int, string>
   *   The key, the creation stamp to compare against, then field/value pairs.
   */
  protected function writeArguments(string $key, array $hash): array {
    $arguments = [$key, (string) ($hash['created'] ?? 0)];
    foreach ($hash as $field => $value) {
      $arguments[] = (string) $field;
      $arguments[] = (string) $value;
    }
    return $arguments;
  }

  /**
   * Sends every pending write as a single pipeline.
   *
   * Writes are replayed with one guard: ::WRITE_IF_NOT_NEWER refuses to
   * overwrite an entry whose creation stamp is newer than the buffered one, so
   * a write another process made during the buffer window survives.
   *
   * A DEL issued by another process is still undone: an absent key is not a
   * newer key, so the write recreates it with its pre-delete data. Closing that
   * would take a tombstone written by every delete and read here - memory on
   * every delete, a lifetime that has to outlive the longest buffer window, and
   * it would still miss deletes from processes that do not run this module - so
   * it is deliberately not done.
   *
   * What bounds the damage instead is that the entries which could never
   * recover from it are never buffered in the first place:
   * \Drupal\redis_rtt\Cache\DeferredRedisBackend::setMultiple() writes an entry
   * with neither an expiry nor a cache tag synchronously, where the stock
   * backend writes it. A resurrected entry therefore always has something that
   * will eventually correct it - a TTL that expires it, or a tag whose next
   * invalidation kills it. Without that rule, a config object deleted on one
   * web node came back on all of them and stayed until a human rebuilt caches.
   *
   * ::deleteAll() is not exposed to this either: it stamps a marker the backend
   * compares against every entry's creation time when reading, so a resurrected
   * entry older than the marker is ignored. A single-key delete has no
   * equivalent marker.
   */
  public function flush(): void {
    if ($this->flushing) {
      return;
    }

    $dirty = [];
    foreach ($this->markers as $key => $marker) {
      if ($marker['dirty']) {
        $dirty[$key] = $marker['value'];
      }
    }

    // Bins with pending writes whose marker this process has never been handed.
    // Core 11.2's ChainedFastBackend::markAsOutdated() stamps 50 ms ahead and
    // then skips the write while that stamp is still in the future, so a
    // buffered write can otherwise land with no marker at all - and every other
    // node goes on serving the copy it primed before it. Asking whether the bin
    // has a marker at all costs nothing here: it rides in the data pipeline.
    $probe = [];
    if ($this->writes) {
      foreach ($this->bins as $prefix => $marker_key) {
        if (isset($this->markers[$marker_key])) {
          continue;
        }
        foreach (array_keys($this->writes) as $key) {
          if (str_starts_with($key, $prefix)) {
            $probe[$marker_key] = $prefix;
            break;
          }
        }
      }
    }

    if (!$this->writes && !$dirty) {
      return;
    }

    $this->flushing = TRUE;
    $writes = $this->writes;
    $this->writes = [];

    try {
      $client = $this->getClient();
      $client->pipeline();
      foreach ($writes as $key => $write) {
        $client->eval(self::WRITE_IF_NOT_NEWER, $this->writeArguments($key, $write['hash']), 1);
        if (isset($write['ttl'])) {
          $client->expire($key, $write['ttl']);
        }
      }
      foreach (array_keys($probe) as $marker_key) {
        $client->exists($marker_key);
      }
      $replies = $client->exec();

      // Only now is the data readable by anyone else, so this is the earliest
      // moment a marker may claim. Stamping before the pipeline - which is what
      // this did - let the marker predate its own writes by however long the
      // transmission took: measured at 7.4 ms for 512 entries of 8 KB, and
      // 53 ms at 64 KB. A node that primed its fast backend inside that window
      // stamped its pre-write copy later than the marker and kept it for good.
      $stamp = round(microtime(TRUE) + .001, 3);

      $publish = [];
      foreach ($dirty as $key => $value) {
        $publish[$key] = max($value, $stamp);
      }

      // The exists() replies arrive after one reply per data command, in the
      // order they were queued.
      $offset = is_array($replies) ? count($replies) - count($probe) : -1;
      $index = 0;
      foreach ($probe as $marker_key => $prefix) {
        if ($offset >= 0 && !empty($replies[$offset + $index])) {
          $publish[$marker_key] = $stamp;
          $this->markers[$marker_key] = [
            'value' => $stamp,
            'prefix' => $prefix,
            'dirty' => TRUE,
          ];
        }
        $index++;
      }

      if ($publish) {
        // A second trip, deliberately. A marker sent inside the data pipeline
        // is stamped before Redis has applied that pipeline, which is the whole
        // defect. This runs from the shutdown function, after
        // fastcgi_finish_request(), so the extra wait is nobody's.
        $client->pipeline();
        foreach ($publish as $key => $value) {
          $client->set($key, $value);
        }
        $client->exec();
        foreach (array_keys($publish) as $key) {
          if (isset($this->markers[$key])) {
            $this->markers[$key]['dirty'] = FALSE;
          }
        }
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

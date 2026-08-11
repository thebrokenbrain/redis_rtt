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
 * Ordering is preserved for correctness: a delete of a pending key drops the
 * pending write instead of racing with it.
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

    if (!$this->shutdownRegistered) {
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

    if (count($this->writes) >= $this->maxPending) {
      $this->flush();
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
   * resurrect a deleted entry.
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
   */
  public function flush(): void {
    if ($this->flushing || !$this->writes) {
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
      $client->exec();
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

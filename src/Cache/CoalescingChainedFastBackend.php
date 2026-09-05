<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\ChainedFastBackend;

/**
 * Chained fast backend that writes its "last write" marker at most twice.
 *
 * \Drupal\Core\Cache\ChainedFastBackend::markAsOutdated() writes a timestamp to
 * the consistent backend on every set, delete, invalidate and deleteAll. It
 * guards against repeats with a millisecond-resolution comparison, which stops
 * helping as soon as two writes to the same bin are more than a millisecond
 * apart - so a request that writes a bin repeatedly (a discovery rebuild, a
 * config import, a cron run) pays a Redis write per operation on top of the
 * operation itself.
 *
 * This subclass keeps the first marker write exactly where core puts it, so the
 * "this bin changed" signal is handed to the consistent backend just as early
 * as before, and coalesces every subsequent *write* of the same request into a
 * single one at shutdown carrying the final timestamp. Worst case for a request
 * that only writes is therefore two marker writes per bin instead of N.
 *
 * Deletes and invalidations are never coalesced. They take effect the moment
 * they are issued rather than when the marker lands, so deferring their
 * announcement leaves every other web node serving what was just cleared - and
 * "until shutdown" is a request for a web process but minutes for the cron
 * runs, queue workers and config imports this class exists for.
 *
 * When the marker actually reaches Redis is the consistent backend's business,
 * and it matters: a marker that arrives before the data it describes is worse
 * than no marker at all, because it makes the other web nodes re-prime their
 * fast backends from a Redis that still holds the old value. With
 * \Drupal\redis_rtt\Cache\DeferredRedisBackend underneath, both marker writes
 * are queued in the shared command buffer and leave behind the data. This class
 * registers its shutdown callback from the constructor, before the buffer
 * registers its own flush, so the coalesced second marker is queued in time to
 * travel in that same pipeline rather than costing a pipeline of its own.
 *
 * @see \Drupal\redis_rtt\Redis\CommandBuffer::queueMarker()
 */
class CoalescingChainedFastBackend extends ChainedFastBackend {

  /**
   * Whether the marker has been published at least once this request.
   */
  protected bool $markerPublished = FALSE;

  /**
   * Whether a marker write is pending for shutdown.
   */
  protected bool $markerPending = FALSE;

  /**
   * Whether the operation currently running may have its marker coalesced.
   *
   * Only a write may. A write's data goes to the consistent backend in the same
   * breath, so deferring its announcement defers a fact nobody can act on yet.
   * A delete or an invalidation is the opposite: it is applied the moment it is
   * issued, and until its marker is published every other web node keeps
   * serving what it was told to drop. Set around ::set() and ::setMultiple()
   * only, so a removal - including any core adds later - is announced at once,
   * exactly as \Drupal\Core\Cache\ChainedFastBackend announces it.
   */
  protected bool $coalescable = FALSE;

  public function __construct(CacheBackendInterface $consistent_backend, CacheBackendInterface $fast_backend, $bin) {
    parent::__construct($consistent_backend, $fast_backend, $bin);
    // Registered here rather than when a marker is first deferred, so that this
    // callback runs before any flush the consistent backend registers later:
    // the marker is then queued in time to join that flush instead of trailing
    // it. The bootstrap, config and discovery bins are built early enough that
    // core/includes/bootstrap.inc may not be loaded yet.
    if (function_exists('drupal_register_shutdown_function')) {
      drupal_register_shutdown_function([$this, 'flushMarker']);
    }
    else {
      register_shutdown_function([$this, 'flushMarker']);
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param string $cid
   *   The cache ID.
   * @param mixed $data
   *   The data to store.
   * @param int $expire
   *   A Unix timestamp, or Cache::PERMANENT.
   * @param string[] $tags
   *   The cache tags.
   */
  public function set($cid, $data, $expire = Cache::PERMANENT, array $tags = []): void {
    $this->coalescable = TRUE;
    try {
      parent::set($cid, $data, $expire, $tags);
    }
    finally {
      $this->coalescable = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, array{data: mixed, expire?: int, tags?: string[]}> $items
   *   The items to write, keyed by cache ID.
   */
  public function setMultiple(array $items): void {
    $this->coalescable = TRUE;
    try {
      parent::setMultiple($items);
    }
    finally {
      $this->coalescable = FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function markAsOutdated(): void {
    // Same clock handling as core: never move the marker backwards, and add a
    // millisecond so entries written earlier in this millisecond are covered.
    $now = round(microtime(TRUE) + .001, 3);
    if ($now <= $this->getLastWriteTimestamp()) {
      return;
    }
    $this->lastWriteTimestamp = $now;

    if ($this->markerPublished && $this->coalescable) {
      // A second or later write of the same request: only move the local view.
      // The network write is deferred to shutdown and collapses into one.
      $this->markerPending = TRUE;
      return;
    }

    // The first marker of the request, and every marker a delete or an
    // invalidation asks for, goes to the consistent backend immediately,
    // exactly like core. Publishing carries any pending stamp with it, because
    // $now is the largest of them.
    $this->markerPublished = TRUE;
    $this->markerPending = FALSE;
    $this->writeMarker();
  }

  /**
   * Publishes a pending marker. Registered as a shutdown function.
   */
  public function flushMarker(): void {
    if ($this->markerPending) {
      $this->markerPending = FALSE;
      $this->writeMarker();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    parent::reset();
    $this->markerPublished = FALSE;
    $this->markerPending = FALSE;
  }

  /**
   * Writes the marker to the consistent backend.
   */
  protected function writeMarker(): void {
    $this->consistentBackend->set(self::LAST_WRITE_TIMESTAMP_PREFIX . $this->bin, $this->lastWriteTimestamp);
  }

}

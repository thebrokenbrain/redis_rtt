<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

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
 * "this bin changed" signal reaches other web nodes just as early as before,
 * and coalesces every subsequent write of the same request into a single one at
 * shutdown carrying the final timestamp. Worst case is therefore two marker
 * writes per bin per request instead of N, with the same visibility guarantee.
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

  public function __construct(CacheBackendInterface $consistent_backend, CacheBackendInterface $fast_backend, $bin) {
    parent::__construct($consistent_backend, $fast_backend, $bin);
    // The bootstrap, config and discovery bins are built early enough that
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
   */
  protected function markAsOutdated(): void {
    // Same clock handling as core: never move the marker backwards, and add a
    // millisecond so entries written earlier in this millisecond are covered.
    $now = round(microtime(TRUE) + .001, 3);
    if ($now <= $this->getLastWriteTimestamp()) {
      return;
    }
    $this->lastWriteTimestamp = $now;

    if (!$this->markerPublished) {
      // First write of the request behaves exactly like core.
      $this->markerPublished = TRUE;
      $this->writeMarker();
      return;
    }

    // Subsequent writes only move the local view; the network write is
    // deferred to shutdown and collapses into one.
    $this->markerPending = TRUE;
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

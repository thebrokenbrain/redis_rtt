<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Kernel;

/**
 * Runs the same cache backend contract against a bin whose writes are batched.
 *
 * This is the one that matters. Core's generic test uses the "page" bin, which
 * is not on this module's batched list, so the inherited run exercises the
 * write path that behaves exactly like stock - and would keep passing if the
 * batched path were completely broken.
 *
 * Here the test bins are added to redis_rtt_batched_bins, so every inherited
 * assertion about setting, getting, deleting, invalidating and expiring runs
 * against writes that are queued in memory first. Anything the queue gets wrong
 * - a read that misses its own write, a delete a flush undoes, a lifetime lost
 * on the way - fails a test that already exists.
 *
 * @group redis_rtt
 */
class BatchedBinCacheTest extends RedisRttCacheTest {

  /**
   * {@inheritdoc}
   *
   * The bins core's contract touches: its default one and the second bin it
   * uses to check that operations do not leak across bins.
   */
  protected array $extraBatchedBins = ['page', 'bootstrap'];

  /**
   * The bin under test really is batched, or this class asserts nothing.
   *
   * Without this, a change to the default bin list - or to how the setting is
   * read - would silently turn every inherited test back into a copy of the
   * parent class.
   */
  public function testTheBinUnderTestIsActuallyBatched(): void {
    $batch = \Drupal::service('redis_rtt.write_batch');
    $this->assertTrue($batch->handles($this->getTestBin()), 'The bin the inherited tests use must be batched.');

    $backend = $this->getCacheBackend();
    $backend->set('encolada', 'value');
    $this->assertSame(1, $batch->getStats()['pending'], 'The write must still be waiting in the queue.');

    $this->assertNotEmpty($backend->get('encolada'), 'And a read must find it there anyway.');
  }

}

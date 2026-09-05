<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\CoalescingChainedFastBackend;

/**
 * Checks what the coalesced marker still announces, and how soon.
 *
 * \Drupal\Core\Cache\ChainedFastBackend writes its "last write timestamp"
 * marker to the consistent backend after every set, delete, invalidate and
 * deleteAll, and that marker is how the other web nodes learn to throw away
 * their own copy. Collapsing those writes is the point of the subclass; the
 * question each of these tests asks is which of them may be collapsed.
 *
 * A write may: its data goes to the consistent backend in the same breath, so
 * deferring the announcement defers a fact nobody could act on any earlier. A
 * removal may not: it takes effect when it is issued, and every moment its
 * marker is withheld is a moment the other nodes keep serving what was just
 * dropped. "Until shutdown" is a request for a web process, and minutes for the
 * cron runs, queue workers and config imports this class exists for.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Cache\CoalescingChainedFastBackend
 * @group redis_rtt
 */
class CoalescingChainedFastBackendTest extends UnitTestCase {

  /**
   * The cache ID the bin's marker is stored under.
   */
  protected const MARKER = 'last_write_timestamp_cache_discovery';

  /**
   * The backend standing in for Redis, which records every write.
   */
  protected CountingMemoryBackend $consistent;

  /**
   * The bin under test.
   */
  protected CoalescingChainedFastBackend $bin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->consistent = new CountingMemoryBackend();
    // The constructor registers a shutdown flush; these tests drive
    // ::flushMarker() by hand instead, so nothing here depends on shutdown
    // order.
    $this->bin = new CoalescingChainedFastBackend($this->consistent, new CountingMemoryBackend(), 'discovery');
  }

  /**
   * The marker writes recorded so far.
   *
   * @return string[]
   *   One entry per marker write, in order.
   */
  protected function markers(): array {
    return array_values(array_filter(
      $this->consistent->written,
      fn (string $cid): bool => $cid === self::MARKER,
    ));
  }

  /**
   * The first write publishes its marker exactly where core publishes it.
   *
   * @covers ::markAsOutdated
   */
  public function testTheFirstWritePublishesItsMarkerImmediately(): void {
    $this->bin->set('a', 1);

    $this->assertCount(1, $this->markers(), 'The "this bin changed" signal must be handed over as early as core hands it over.');
  }

  /**
   * Further writes of the same request collapse into one marker at shutdown.
   *
   * This is what the class is for: a discovery rebuild or a config import pays
   * one marker write per operation upstream, because core's millisecond guard
   * stops helping as soon as two writes are more than a millisecond apart.
   *
   * @covers ::markAsOutdated
   * @covers ::flushMarker
   */
  public function testFurtherWritesCoalesceIntoOneMarker(): void {
    $this->bin->set('a', 1);
    usleep(2000);
    $this->bin->set('b', 2);
    usleep(2000);
    $this->bin->set('c', 3);

    $this->assertCount(1, $this->markers(), 'Only the first write should have published; the rest wait.');

    $this->bin->flushMarker();

    $this->assertCount(2, $this->markers(), 'Worst case is two marker writes per bin per request, not one per write.');
  }

  /**
   * A delete publishes its marker at once, even after a write already did.
   *
   * The regression this guards: with the deferral keyed only on "has anything
   * been published yet", a process that wrote once and then deleted left the
   * marker sitting at the pre-delete stamp for the rest of its life. A second
   * web node that primed its fast backend in between went on serving the
   * deleted entry - measured at five seconds after the delete in a two-node
   * reproduction, and bounded only by when the writing process exited.
   *
   * @covers ::markAsOutdated
   */
  public function testDeletesAfterWritesPublishTheirMarkerImmediately(): void {
    $this->bin->set('a', 1);
    usleep(2000);
    $this->assertCount(1, $this->markers());

    $this->bin->delete('b');

    $this->assertCount(2, $this->markers(), 'A delete nobody is told about is a delete that has not happened for the other nodes.');
  }

  /**
   * The same holds for every other way of removing entries.
   *
   * @covers ::markAsOutdated
   */
  public function testEveryRemovalPublishesItsMarkerImmediately(): void {
    $cases = [
      'deleteMultiple' => fn () => $this->bin->deleteMultiple(['x']),
      'deleteAll' => fn () => $this->bin->deleteAll(),
      'invalidate' => fn () => $this->bin->invalidate('x'),
      'invalidateMultiple' => fn () => $this->bin->invalidateMultiple(['x']),
      'invalidateAll' => fn () => $this->bin->invalidateAll(),
    ];

    foreach ($cases as $name => $removal) {
      $this->consistent = new CountingMemoryBackend();
      $this->bin = new CoalescingChainedFastBackend($this->consistent, new CountingMemoryBackend(), 'discovery');

      // A write first, so the removal is never the request's first marker and
      // cannot pass for the wrong reason.
      $this->bin->set('a', 1);
      usleep(2000);
      $removal();

      $this->assertCount(2, $this->markers(), sprintf('::%s() must announce itself immediately.', $name));
    }
  }

  /**
   * A write after a removal may still coalesce.
   *
   * The rule is about what the marker announces, not about a process being
   * poisoned by its first delete: once the removal is published, the writes
   * that follow are back to announcing data that travels with them.
   *
   * @covers ::markAsOutdated
   */
  public function testWritesAfterRemovalsCoalesceAgain(): void {
    $this->bin->delete('a');
    usleep(2000);
    $this->assertCount(1, $this->markers());

    $this->bin->set('b', 2);
    usleep(2000);
    $this->bin->set('c', 3);

    $this->assertCount(1, $this->markers(), 'Writes still coalesce; only removals are exempt.');
  }

  /**
   * Publishing a removal's marker clears the write's pending one.
   *
   * Otherwise shutdown writes a second marker carrying a timestamp the removal
   * already published - a round trip that buys nothing.
   *
   * @covers ::markAsOutdated
   * @covers ::flushMarker
   */
  public function testPendingMarkersAreSettledByTheRemovalThatOvertakesThem(): void {
    $this->bin->set('a', 1);
    usleep(2000);
    $this->bin->set('b', 2);
    usleep(2000);
    $this->bin->delete('c');
    $this->assertCount(2, $this->markers());

    $this->bin->flushMarker();

    $this->assertCount(2, $this->markers(), 'The delete already carried the pending stamp; shutdown has nothing left to send.');
  }

  /**
   * The marker never moves backwards, exactly as in core.
   *
   * @covers ::markAsOutdated
   */
  public function testTheMarkerNeverMovesBackwards(): void {
    $this->bin->set('a', 1, Cache::PERMANENT);
    usleep(2000);
    $this->bin->delete('b');

    $published = $this->consistent->get(self::MARKER);
    $this->assertNotFalse($published);
    $before = (float) $published->data;
    $this->assertGreaterThan(0.0, $before);

    $this->bin->flushMarker();

    $republished = $this->consistent->get(self::MARKER);
    $this->assertNotFalse($republished);
    $this->assertGreaterThanOrEqual($before, (float) $republished->data);
  }

}

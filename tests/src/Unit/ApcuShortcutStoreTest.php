<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\ApcuShortcutStore;

/**
 * Tests that the in-process memo cannot grow without end.
 *
 * With APCu off - the normal state of a CLI process, which this class's
 * docblock names - the memo is the entire store. Unbounded, a long drush run
 * or a queue worker touching many distinct elements grew it one entry per
 * element for the life of the process: measured at 42 MB for 60,000 elements,
 * a straight line with no plateau.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Cache\ApcuShortcutStore
 * @group redis_rtt
 */
class ApcuShortcutStoreTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['redis_rtt_shortcut_memo_limit' => 10]);
  }

  /**
   * Reads the memo, which is protected because nothing else should touch it.
   *
   * @param \Drupal\redis_rtt\Cache\ApcuShortcutStore $store
   *   The store to look inside.
   *
   * @return array<string, mixed>
   *   The memo.
   */
  protected function memo(ApcuShortcutStore $store): array {
    $property = (new \ReflectionObject($store))->getProperty('memo');
    $property->setAccessible(TRUE);

    return $property->getValue($store);
  }

  /**
   * Writing far past the limit keeps the memo at the limit.
   *
   * @covers ::memoise
   * @covers ::set
   */
  public function testTheMemoStopsGrowingAtItsLimit(): void {
    $store = new ApcuShortcutStore('render');
    for ($i = 0; $i < 500; $i++) {
      $store->set("elemento:$i", ['cid' => "c$i"]);
    }

    $this->assertCount(10, $this->memo($store), 'The memo is held at its limit.');
  }

  /**
   * What is dropped is the oldest, and the newest is still readable.
   *
   * @covers ::memoise
   */
  public function testTheOldestGoesFirst(): void {
    $store = new ApcuShortcutStore('render');
    for ($i = 0; $i < 15; $i++) {
      $store->set("elemento:$i", ['cid' => "c$i"]);
    }

    $this->assertNull($store->get('elemento:0'), 'The first one written is gone.');
    $this->assertSame(['cid' => 'c14'], $store->get('elemento:14'), 'The last one is there.');
    $this->assertSame(['cid' => 'c5'], $store->get('elemento:5'), 'And so is the oldest kept.');
  }

  /**
   * Rewriting a key already held does not evict anything.
   *
   * @covers ::memoise
   */
  public function testRewritingHeldKeysDoesNotEvict(): void {
    $store = new ApcuShortcutStore('render');
    for ($i = 0; $i < 10; $i++) {
      $store->set("elemento:$i", ['cid' => "c$i"]);
    }
    for ($i = 0; $i < 10; $i++) {
      $store->set("elemento:$i", ['cid' => "nuevo$i"]);
    }

    $this->assertCount(10, $this->memo($store));
    $this->assertSame(['cid' => 'nuevo0'], $store->get('elemento:0'), 'Nothing was evicted.');
  }

  /**
   * Note on ::get() and the memo, which is deliberately not tested here.
   *
   * ::get() memoises what it read out of APCu, and a mutation that deletes that
   * line survives this suite. It is not a coverage hole that a test can close:
   * with APCu off - which is how this suite runs, and the normal state of a CLI
   * process - the memo IS the store, so a read that misses it has nowhere else
   * to look and the line is unreachable. The mutant is equivalent in this
   * environment and only distinguishable with the extension enabled.
   *
   * A test guarded by markTestSkipped() was written and then removed: it turned
   * the suite's summary into "OK, but incomplete, skipped, or risky", which
   * reads like a pass and hides whether the thing ran at all. Stating it here
   * is more honest than a skip nobody reads.
   */

  /**
   * However the limit is written, one entry always fits.
   *
   * 0, a negative number and a word all reach the constructor, and none of them
   * may leave a memo that remembers nothing.
   *
   * Note that the max(1, ...) in the constructor is not what makes this true,
   * and this test does not pin it: the eviction is guarded by
   * count($memo) >= $limit, which behaves identically for a limit of 1, 0 or
   * -1, so removing the floor changes no observable behaviour. That mutant is
   * equivalent, not uncovered.
   *
   * @covers ::__construct
   * @covers ::memoise
   */
  public function testOneEntryAlwaysFits(): void {
    foreach ([0, -1, '0', 'abc'] as $configured) {
      new Settings(['redis_rtt_shortcut_memo_limit' => $configured]);
      $store = new ApcuShortcutStore('render');
      $store->set('una', ['cid' => 'c1']);

      $this->assertSame(
        ['cid' => 'c1'],
        $store->get('una'),
        'Whatever the setting says, one entry has to fit.'
      );
    }
  }

  /**
   * Rewriting a key already held evicts nothing, including the oldest.
   *
   * The existing test for this rewrites every key in order, starting with the
   * oldest - and the oldest is exactly the one an unguarded eviction would drop
   * and then immediately put back, so it passed either way. Rewriting a key in
   * the middle is what tells them apart.
   *
   * @covers ::memoise
   */
  public function testRewritingMiddleKeyEvictsNothing(): void {
    new Settings(['redis_rtt_shortcut_memo_limit' => 10]);
    $store = new ApcuShortcutStore('render');
    for ($i = 0; $i < 10; $i++) {
      $store->set("elemento:$i", ['cid' => "c$i"]);
    }

    $store->set('elemento:5', ['cid' => 'nuevo5']);

    $this->assertCount(10, $this->memo($store), 'Still full, not one more and not one less.');
    $this->assertSame(['cid' => 'nuevo5'], $store->get('elemento:5'), 'The rewrite landed.');
    $this->assertSame(
      ['cid' => 'c0'],
      $store->get('elemento:0'),
      'And the oldest is still there: rewriting what is held must not make room.'
    );
  }

  /**
   * The documented default is the one in force.
   *
   * @covers ::__construct
   */
  public function testTheDefaultLimitIsTheDocumentedOne(): void {
    new Settings([]);
    $store = new ApcuShortcutStore('render');
    for ($i = 0; $i < 1001; $i++) {
      $store->set("elemento:$i", ['cid' => "c$i"]);
    }

    $this->assertCount(1000, $this->memo($store), 'The README says 1000, and so does the code.');
  }

}

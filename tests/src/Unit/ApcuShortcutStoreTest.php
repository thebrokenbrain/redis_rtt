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

}

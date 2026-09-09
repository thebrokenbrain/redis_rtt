<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction\TransactionManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\PipeliningRedisBackend;

/**
 * Tests that a good write is not eaten by a pending delete.
 *
 * ::setMultiple() routes already-expired items to ::deleteMultiple(). Inside a
 * database transaction that defers the delete to the commit and refuses to
 * serve the cache ID until then, so writing the same cache ID properly
 * afterwards left the good value unreadable and then deleted.
 *
 * The stock backend does not hit this, but only because it queues a
 * double-prefixed key that matches nothing. Fixing that is what exposed it.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Cache\PipeliningRedisBackend
 * @group redis_rtt
 */
class ExpiredWriteInTransactionTest extends UnitTestCase {

  /**
   * The in-memory Redis.
   */
  protected FakeRedisClient $client;

  /**
   * Builds the container, with or without an open transaction.
   *
   * @param bool $in_transaction
   *   Whether the database reports a transaction in progress.
   */
  protected function container(bool $in_transaction): void {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000000);
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn($in_transaction);
    // ::deleteMultiple() registers its commit callback here when a transaction
    // is open; without it the deferral cannot even be reached.
    $database->method('transactionManager')
      ->willReturn($this->createMock(TransactionManagerInterface::class));

    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    $container->set('database', $database);
    \Drupal::setContainer($container);
  }

  /**
   * Builds the backend.
   *
   * @return \Drupal\redis_rtt\Cache\PipeliningRedisBackend
   *   A backend for the render bin.
   */
  protected function backend(): PipeliningRedisBackend {
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $checksum->method('isValid')->willReturn(TRUE);
    $backend = new PipeliningRedisBackend('render', $this->client, $checksum, new PhpSerialize());
    $backend->setPrefix('p');

    return $backend;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->client = new FakeRedisClient();
  }

  /**
   * Writes an expired entry and then a good one, and reads the good one back.
   *
   * @param bool $in_transaction
   *   Whether to run inside a transaction.
   *
   * @return string
   *   The data read back, or 'MISS'.
   */
  protected function writeExpiredThenGood(bool $in_transaction): string {
    $this->container($in_transaction);
    $backend = $this->backend();
    $backend->setMultiple([
      'uno' => ['data' => 'CADUCADO', 'expire' => 1000000 - 7200, 'tags' => []],
    ]);
    $backend->set('uno', 'BUENO', CacheBackendInterface::CACHE_PERMANENT);
    $item = $backend->get('uno');

    return $item ? $item->data : 'MISS';
  }

  /**
   * The later write wins, transaction or not.
   *
   * @covers ::setMultiple
   */
  public function testGoodWriteSurvivesInsideTransaction(): void {
    $this->assertSame('BUENO', $this->writeExpiredThenGood(TRUE));
  }

  /**
   * The control: outside a transaction this always worked.
   *
   * If this were the only one passing, the fix would be doing nothing.
   *
   * @covers ::setMultiple
   */
  public function testGoodWriteSurvivesOutsideTransaction(): void {
    $this->assertSame('BUENO', $this->writeExpiredThenGood(FALSE));
  }

  /**
   * An expired write on its own still removes the entry.
   *
   * The fix must not turn "write something already expired" into "keep the old
   * value", which is the obvious way to get this wrong.
   *
   * @covers ::setMultiple
   */
  public function testExpiredWriteAloneStillRemoves(): void {
    $this->container(TRUE);
    $backend = $this->backend();
    $backend->set('dos', 'V1', CacheBackendInterface::CACHE_PERMANENT);
    $this->assertNotFalse($backend->get('dos'), 'There to begin with.');

    $backend->setMultiple([
      'dos' => ['data' => 'CADUCADO', 'expire' => 1000000 - 7200, 'tags' => []],
    ]);

    $this->assertFalse($backend->get('dos'), 'And gone afterwards.');
  }

}

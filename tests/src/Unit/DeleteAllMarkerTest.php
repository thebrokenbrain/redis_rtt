<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\PipeliningRedisBackend;

/**
 * Tests how the "last delete all" marker is taken off the read pipeline.
 *
 * The marker decides whether an entry survived a deleteAll(), and deleteAll()
 * deletes nothing: it writes a timestamp and entries older than it stop
 * answering. Getting the marker wrong therefore serves content a flush was
 * supposed to have retired, silently and with no error anywhere.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Cache\PipeliningRedisBackend
 * @group redis_rtt
 */
class DeleteAllMarkerTest extends UnitTestCase {

  /**
   * The shared connection, as in production: one client, several backends.
   */
  protected TruncatingClient $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->client = new TruncatingClient(new FakeRedisClient());

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(1000000);
    $database = $this->createMock(Connection::class);
    $database->method('inTransaction')->willReturn(FALSE);

    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    $container->set('database', $database);
    \Drupal::setContainer($container);
  }

  /**
   * Builds a backend on the shared client.
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
   * A read that finds nothing holds no opinion about the last flush.
   *
   * The stock backend fetches the marker lazily from ::expandEntry(), which
   * never runs without rows, so after a pure miss it still sees a deleteAll()
   * that arrives afterwards. This backend used to take the marker anyway and
   * go blind to that flush for the rest of the request.
   *
   * @covers ::getMultiple
   */
  public function testPureMissDoesNotFixTheMarker(): void {
    $reader = $this->backend();
    $other = $this->backend();

    $missing = ['no-esta'];
    $this->assertSame([], $reader->getMultiple($missing), 'Nothing found, as arranged.');

    $reader->set('uno', 'V1');
    $other->deleteAll();

    $this->assertFalse(
      $reader->get('uno'),
      'A flush by another process must be seen by a reader that only missed.'
    );
  }

  /**
   * A reply set that did not come back whole must not lower a real marker.
   *
   * The distinction that matters: an absent marker is legitimately 0.0, and
   * the stock backend caches that same 0.0 on its own lazy read, so nothing
   * can be told apart there. It is when the bin HAS been emptied that reading
   * a timestamp out of a broken reply matters - 0.0 or 1.0 is 1970, which is
   * falsy, which means "never emptied", which serves what the flush retired.
   *
   * @covers ::getMultiple
   */
  public function testShortReplyDoesNotLowerExistingMarker(): void {
    $writer = $this->backend();
    $writer->set('uno', 'V1');
    $this->backend()->deleteAll();

    $reader = $this->backend();
    $this->client->spoil = 'short';
    $cids = ['uno'];
    $reader->getMultiple($cids);

    $this->assertFalse(
      $reader->get('uno'),
      'The entry predates a flush that really happened, and must stay retired.'
    );
  }

  /**
   * A pipeline that answered FALSE is a clean miss, and fixes nothing.
   *
   * @covers ::getMultiple
   */
  public function testFalseReplyIsCleanMiss(): void {
    $writer = $this->backend();
    $writer->set('uno', 'V1');
    $this->backend()->deleteAll();

    $reader = $this->backend();
    $this->client->spoil = 'false';
    $cids = ['uno'];
    $this->assertSame([], $reader->getMultiple($cids), 'No replies, no rows, no error.');

    $this->assertFalse($reader->get('uno'), 'And the flush is still honoured afterwards.');
  }

  /**
   * The control: with the reply intact the flush is honoured just the same.
   *
   * If this failed alongside the two above, they would be proving something
   * about the fake rather than about the marker.
   *
   * @covers ::getMultiple
   */
  public function testTheIntactPathHonoursTheFlush(): void {
    $writer = $this->backend();
    $writer->set('uno', 'V1');
    $this->backend()->deleteAll();

    $reader = $this->backend();
    $cids = ['uno'];

    $this->assertSame([], $reader->getMultiple($cids), 'Retired by the flush.');
  }

}

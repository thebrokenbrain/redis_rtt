<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\redis\ClientFactory;
use Drupal\redis\ClientInterface;
use Drupal\redis_rtt\Cache\PipeliningRedisBackend;
use Drupal\redis_rtt\Lock\LuaRedisLock;
use Drupal\redis_rtt\Redis\Pipeline;

/**
 * Tests that a failed pipeline takes its connection out of service.
 *
 * Both places in this module that open a pipeline route their failure path
 * through Pipeline::discard(). Neither was covered: the test that used to
 * assert this was deleted along with the class it lived in, while the method
 * it covered stayed. Round 12 found the whole file could be deleted with the
 * suite still green.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Redis\Pipeline
 * @group redis_rtt
 */
class PipelineDiscardTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

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
   * An invalidation pipeline that fails closes its connection, and re-raises.
   *
   * @covers ::discard
   */
  public function testFailedInvalidatePipelineClosesItsConnection(): void {
    $client = new FailingPipelineClient();
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $backend = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $backend->setPrefix('p');

    try {
      $backend->invalidateMultiple(['uno', 'dos']);
      $this->fail('The failure must propagate: the caller decides what to do.');
    }
    catch (\RuntimeException) {
      // Expected. ::invalidateMultiple() does not swallow.
    }

    $this->assertTrue(
      $client->closed,
      'A connection that failed mid-pipeline must not be handed to the next request.'
    );
  }

  /**
   * The read path closes its connection too, which it used not to.
   *
   * ::getMultiple() is the pipeline this module opens most often, and it was
   * the only one of the three without this. The docblock on the class being
   * covered said "everything in this module" and listed the other two.
   *
   * @covers ::discard
   */
  public function testFailedReadPipelineClosesItsConnection(): void {
    $client = new FailingPipelineClient();
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    $backend = new PipeliningRedisBackend('render', $client, $checksum, new PhpSerialize());
    $backend->setPrefix('p');

    $cids = ['uno', 'dos'];
    try {
      $backend->getMultiple($cids);
      $this->fail('The failure must propagate.');
    }
    catch (\RuntimeException) {
      // Expected.
    }

    $this->assertTrue(
      $client->closed,
      'A read that failed mid-pipeline must not hand its connection on.'
    );
  }

  /**
   * Releasing every lock at once has the same failure path.
   *
   * @covers ::discard
   */
  public function testFailedReleasePipelineClosesItsConnection(): void {
    $client = new FailingPipelineClient();
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn($client);
    $lock = new LuaRedisLock($factory, FALSE);
    $lock->acquire('un-nombre');

    try {
      $lock->releaseAll();
      $this->fail('The failure must propagate.');
    }
    catch (\RuntimeException) {
      // Expected.
    }

    $this->assertTrue($client->closed, 'The lock connection must be taken out of service too.');
  }

  /**
   * A failure before any connection existed is not itself a failure.
   *
   * @covers ::discard
   */
  public function testDiscardingNoConnectionIsSafe(): void {
    Pipeline::discard(NULL);

    $this->expectNotToPerformAssertions();
  }

  /**
   * A connection already gone is the state being aimed for, not an error.
   *
   * Closing a socket the server has dropped can raise, and that must not turn
   * a handled pipeline failure into an unhandled one on the way out.
   *
   * @covers ::discard
   */
  public function testCloseThatRaisesIsSwallowed(): void {
    $client = new class implements ClientInterface {

      /**
       * {@inheritdoc}
       *
       * @param string $name
       *   The Redis command.
       * @param mixed[] $arguments
       *   The command arguments.
       *
       * @return mixed
       *   Never returns.
       */
      public function __call(string $name, array $arguments) {
        throw new \RedisException('Connection lost');
      }

      /**
       * {@inheritdoc}
       */
      public function getName() {
        return 'AlreadyGone';
      }

      /**
       * {@inheritdoc}
       */
      public function scan(string $match, int $count = 1000) {
        yield from [];
      }

      /**
       * {@inheritdoc}
       *
       * @return array<string, mixed>
       *   Always empty.
       */
      public function info(): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function addIgnorePattern(string $key): void {}

    };

    Pipeline::discard($client);

    $this->expectNotToPerformAssertions();
  }

}

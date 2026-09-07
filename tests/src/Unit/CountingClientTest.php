<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis\ClientInterface;
use Drupal\redis_rtt\Client\CountingClient;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the counter every published figure of this module rests on.
 *
 * WHY THIS EXISTS.
 *
 * It did not, and the omission was circular: the module's case is made in round
 * trips, round trips are what \Drupal\redis_rtt\Client\CountingClient defines,
 * and nothing checked the definition. Disconnecting $roundTrips, $commands or
 * $nanoseconds one at a time left the whole suite green, so the number in every
 * table could have been arbitrary and no test would have said so.
 *
 * The three definitions being asserted here, because they are the ones that are
 * easy to get wrong and impossible to notice:
 *
 * - A pipeline is ONE wait. phpredis holds the commands locally and exec()
 *   hands them over, so N commands in a pipeline cost one round trip, not N.
 * - A MULTI block is NOT. phpredis sends each command inside MULTI as it is
 *   called and reads back +QUEUED, so a WATCH/GET/MULTI/DEL/EXEC release costs
 *   five waits. Counting it as one under-reports, and it under-reports on the
 *   stock backend this module is compared against - that is, in the direction
 *   that flatters this module.
 * - exec() is a wait but not a command. Counting it as a command would report
 *   one more than went to Redis, per pipeline.
 *
 * @group redis_rtt
 * @coversDefaultClass \Drupal\redis_rtt\Client\CountingClient
 */
class CountingClientTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    CountingClient::reset();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    CountingClient::reset();
    parent::tearDown();
  }

  /**
   * A command on its own is one wait and one command.
   *
   * @covers ::__call
   */
  public function testLooseCommandIsOneWait(): void {
    $client = $this->client();
    $client->get('k');

    $this->assertSame(1, CountingClient::$roundTrips);
    $this->assertSame(1, CountingClient::$commands);
    $this->assertSame(['get' => 1], CountingClient::$byCommand);
  }

  /**
   * Five loose commands are five waits.
   *
   * @covers ::__call
   */
  public function testLooseCommandsEachCostOneWait(): void {
    $client = $this->client();
    for ($i = 0; $i < 5; $i++) {
      $client->get('k' . $i);
    }

    $this->assertSame(5, CountingClient::$roundTrips);
    $this->assertSame(5, CountingClient::$commands);
  }

  /**
   * A pipeline of five commands is one wait, and exec() is not a command.
   *
   * @covers ::__call
   */
  public function testPipelineIsOneWait(): void {
    $client = $this->client();
    $client->pipeline();
    for ($i = 0; $i < 5; $i++) {
      $client->hgetall('k' . $i);
    }
    $client->exec();

    $this->assertSame(1, CountingClient::$roundTrips, 'A pipeline costs one wait however many commands it carries.');
    $this->assertSame(5, CountingClient::$commands, 'Only the five commands count.');
    $this->assertArrayNotHasKey('exec', CountingClient::$byCommand, 'exec() is a wait, not a command.');
    $this->assertArrayNotHasKey('pipeline', CountingClient::$byCommand, 'pipeline() is a local batching call.');
  }

  /**
   * A MULTI block costs one wait per command, plus MULTI and EXEC.
   *
   * This is the case the counter used to get wrong, and it got it wrong against
   * the stock backend rather than this one, so the error flattered this module.
   *
   * @covers ::__call
   */
  public function testMultiBlockCostsOneWaitPerCommand(): void {
    $client = $this->client();
    $client->multi();
    for ($i = 0; $i < 5; $i++) {
      $client->get('k' . $i);
    }
    $client->exec();

    $this->assertSame(7, CountingClient::$roundTrips, 'MULTI + five queued commands + EXEC are seven waits.');

    // Known and deliberate: MULTI and EXEC are real Redis commands, and this
    // counter does not count them, so redis-cmds reads two low per MULTI block.
    // Asserted rather than left implicit because the bias is not symmetric: it
    // only lands on the stock backend, which is the one that uses MULTI, so it
    // under-reports the side this module is measured against. Anyone changing
    // this has to change this assertion, and should re-check every published
    // command figure when they do.
    $this->assertSame(5, CountingClient::$commands, 'MULTI and EXEC are not counted as commands.');
    $this->assertArrayNotHasKey('multi', CountingClient::$byCommand);
    $this->assertArrayNotHasKey('exec', CountingClient::$byCommand);
  }

  /**
   * The stock lock release costs five waits, not three.
   *
   * The concrete sequence the module's documentation quotes, asserted so the
   * claim and the counter cannot drift apart.
   *
   * @covers ::__call
   */
  public function testTheStockLockReleaseCostsFiveWaits(): void {
    $client = $this->client();
    $client->watch('lock');
    $client->get('lock');
    $client->multi();
    $client->del('lock');
    $client->exec();

    $this->assertSame(5, CountingClient::$roundTrips);
  }

  /**
   * After exec(), the next command is a wait of its own again.
   *
   * Without clearing the flags, everything after the first pipeline of the
   * request would be counted as free.
   *
   * @covers ::__call
   */
  public function testTheBlockStateIsClearedByExec(): void {
    $client = $this->client();
    $client->pipeline();
    $client->get('a');
    $client->exec();
    $client->get('b');

    $this->assertSame(2, CountingClient::$roundTrips, 'The command after exec() is a wait of its own.');
  }

  /**
   * Two pipelines in a row are two waits.
   *
   * @covers ::__call
   */
  public function testConsecutivePipelinesAreCountedSeparately(): void {
    $client = $this->client();
    for ($pipeline = 0; $pipeline < 2; $pipeline++) {
      $client->pipeline();
      $client->get('a');
      $client->get('b');
      $client->exec();
    }

    $this->assertSame(2, CountingClient::$roundTrips);
  }

  /**
   * Time spent waiting is accumulated, and only for commands that wait.
   *
   * @covers ::__call
   */
  public function testTimeIsAccumulatedForWaitsOnly(): void {
    $client = $this->client(500_000);

    $client->get('k');
    $loose = CountingClient::$nanoseconds;
    $this->assertGreaterThan(0, $loose, 'A loose command must contribute its wait.');

    // The commands inside a pipeline do not wait; the exec() does.
    CountingClient::reset();
    $client->pipeline();
    $client->get('a');
    $client->get('b');
    $queued = CountingClient::$nanoseconds;
    $this->assertSame(0, $queued, 'Commands queued in a pipeline have not waited yet.');

    $client->exec();
    $this->assertGreaterThan(0, CountingClient::$nanoseconds, 'exec() is where a pipeline waits.');
  }

  /**
   * A failing command still counts its wait and its time.
   *
   * The finally block. Were it a plain return, a request that hit an error
   * would under-report everything after it.
   *
   * @covers ::__call
   */
  public function testFailingCommandStillCounts(): void {
    $inner = $this->createMock(ClientInterface::class);
    $inner->method('__call')->willThrowException(new \RuntimeException('boom'));
    $client = new CountingClient($inner);
    /** @var \Drupal\redis\ClientInterface $client */

    try {
      $client->get('k');
      $this->fail('The exception must propagate.');
    }
    catch (\RuntimeException) {
      // Expected.
    }

    $this->assertSame(1, CountingClient::$roundTrips, 'A command that failed still cost a wait.');
    $this->assertSame(1, CountingClient::$commands);
  }

  /**
   * Reset() clears every counter, so one request cannot bleed into the next.
   *
   * @covers ::reset
   */
  public function testResetClearsEveryCounter(): void {
    $client = $this->client();
    $client->get('k');
    $client->pipeline();
    $client->get('k');
    $client->exec();

    CountingClient::reset();

    $this->assertSame(0, CountingClient::$roundTrips);
    $this->assertSame(0, CountingClient::$commands);
    $this->assertSame(0, CountingClient::$nanoseconds);
    $this->assertSame([], CountingClient::$byCommand);
  }

  /**
   * The name says the client is instrumented.
   *
   * @covers ::getName
   */
  public function testTheNameSaysItIsInstrumented(): void {
    $inner = $this->createMock(ClientInterface::class);
    $inner->method('getName')->willReturn('PhpRedis');

    $this->assertSame('PhpRedis (instrumented)', (new CountingClient($inner))->getName());
  }

  /**
   * Builds a counting client over a stand-in that can be made to take time.
   *
   * @param int $delay_ns
   *   Nanoseconds to spend inside each inner call.
   *
   * @return \Drupal\redis\ClientInterface
   *   The client under test. Typed as the interface because every Redis command
   *   on it is a magic method, exactly as it is on the client this wraps.
   */
  protected function client(int $delay_ns = 0): ClientInterface {
    $inner = $this->createMock(ClientInterface::class);
    $inner->method('__call')->willReturnCallback(function () use ($delay_ns) {
      if ($delay_ns > 0) {
        $until = hrtime(TRUE) + $delay_ns;
        while (hrtime(TRUE) < $until) {
          // Busy wait: usleep() is not reliable at this resolution.
        }
      }
      return TRUE;
    });

    return new CountingClient($inner);
  }

}

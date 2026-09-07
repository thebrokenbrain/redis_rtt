<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis\ClientFactory;
use Drupal\redis_rtt\Redis\WriteBatch;
use Drupal\redis_rtt\StackMiddleware\RoundTripReportMiddleware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Checks the measurement header, and that measuring cannot break a response.
 *
 * Nothing covered this class at all: it could be gutted entirely and the suite
 * stayed green, which matters more than it sounds. It is the one place that
 * sends the batch while a response already exists, so it is also the one place
 * where choosing ::send() over ::sendQuietly() would turn a failed cache write
 * into a failed page - and swapping them there used to pass every test.
 *
 * @coversDefaultClass \Drupal\redis_rtt\StackMiddleware\RoundTripReportMiddleware
 * @group redis_rtt
 */
class RoundTripReportMiddlewareTest extends UnitTestCase {

  /**
   * Builds the middleware over a kernel that returns a plain response.
   *
   * @param \Drupal\redis_rtt\Redis\WriteBatch|null $batch
   *   (optional) The batch to send before reporting.
   *
   * @return \Drupal\redis_rtt\StackMiddleware\RoundTripReportMiddleware
   *   The middleware.
   */
  protected function middleware(?WriteBatch $batch = NULL): RoundTripReportMiddleware {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->method('handle')->willReturn(new Response('hola'));

    return new RoundTripReportMiddleware($kernel, $batch);
  }

  /**
   * Nothing is emitted unless the report is switched on.
   *
   * The header names round trips the request made. Served from a cache it would
   * describe the request that built the page instead, which is why this sits
   * outside the page cache and why it has to stay off by default.
   *
   * @covers ::handle
   */
  public function testTheHeaderIsAbsentUnlessAskedFor(): void {
    new Settings([]);

    $response = $this->middleware()->handle(Request::create('/'));

    $this->assertFalse($response->headers->has('X-Redis-RTT'));
  }

  /**
   * With the report on, the header names what the request cost.
   *
   * @covers ::handle
   * @covers ::report
   */
  public function testTheHeaderNamesWhatTheRequestCost(): void {
    new Settings(['redis_rtt_report' => TRUE]);

    $response = $this->middleware()->handle(Request::create('/'));

    $header = $response->headers->get('X-Redis-RTT');
    $this->assertIsString($header);
    foreach (['redis-trips=', 'redis-cmds=', 'redis-ms='] as $field) {
      $this->assertStringContainsString($field, $header);
    }
  }

  /**
   * A batch that cannot be sent does not take the response down with it.
   *
   * By the time this runs the response exists, and a diagnostic header is no
   * reason to turn a failed cache write into a failed page. ::sendQuietly() is
   * the whole difference; pointing this at ::send() used to pass every test.
   *
   * @covers ::handle
   */
  public function testFailedBatchDoesNotBreakTheResponse(): void {
    new Settings(['redis_rtt_report' => TRUE]);

    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willThrowException(new \RuntimeException('Redis is gone'));
    $batch = new WriteBatch($factory, new Settings(['redis_rtt_report' => TRUE]));
    $batch->add('cualquiera', ['cid' => 'x', 'created' => 1, 'data' => 'v'], NULL);

    $response = $this->middleware($batch)->handle(Request::create('/'));

    $this->assertSame('hola', $response->getContent(), 'The page must still be served.');
    $this->assertSame(0, $batch->getStats()['pending'], 'And the queue must have been attempted.');
  }

  /**
   * What the batch did is reported alongside the round trips.
   *
   * @covers ::report
   */
  public function testTheHeaderReportsWhatTheBatchDid(): void {
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn(new FakeRedisClient());
    $batch = new WriteBatch($factory, new Settings([]));
    $batch->add('una', ['cid' => 'x', 'created' => 1, 'data' => 'v'], NULL);
    // Last, because building a Settings object replaces the singleton the
    // middleware reads: doing it first would silently switch the report off.
    new Settings(['redis_rtt_report' => TRUE]);

    $header = $this->middleware($batch)->handle(Request::create('/'))->headers->get('X-Redis-RTT');

    $this->assertStringContainsString('batches=1', (string) $header);
    $this->assertStringContainsString('batched-writes=1', (string) $header);
  }

}

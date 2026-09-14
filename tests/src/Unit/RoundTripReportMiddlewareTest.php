<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis\ClientInterface;
use Drupal\redis_rtt\Client\CountingClient;
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
   * @return \Drupal\redis_rtt\StackMiddleware\RoundTripReportMiddleware
   *   The middleware.
   */
  protected function middleware(): RoundTripReportMiddleware {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->method('handle')->willReturn(new Response('hola'));

    return new RoundTripReportMiddleware($kernel);
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
   * Without a counted connection the Redis fields say off, not 0.
   *
   * A 0 there, next to db-queries carrying real figures, reads as a site that
   * never talks to Redis. That is what an operator saw after switching on
   * redis_rtt_report alone, which is what Troubleshooting told them to do.
   *
   * @covers ::report
   */
  public function testTheRedisFieldsSayOffWhenNothingIsCounted(): void {
    new Settings(['redis_rtt_report' => TRUE]);
    CountingClient::$instrumented = FALSE;

    $header = (string) $this->middleware()->handle(Request::create('/'))->headers->get('X-Redis-RTT');

    $this->assertStringContainsString('redis-trips=off; redis-cmds=off; redis-ms=off', $header);
  }

  /**
   * A connection wrapped before the request starts is still counted.
   *
   * The order is the real one: the page cache middleware takes its bin, and the
   * bin its client, when the stack is built - before this middleware resets the
   * counters. A reset that also switched the report off would call that live
   * counter off on every page.
   *
   * @covers ::handle
   * @covers ::report
   */
  public function testConnectionWrappedBeforeTheRequestIsCounted(): void {
    new Settings(['redis_rtt_report' => TRUE]);
    CountingClient::$instrumented = FALSE;
    new CountingClient($this->createMock(ClientInterface::class));

    $header = (string) $this->middleware()->handle(Request::create('/'))->headers->get('X-Redis-RTT');

    $this->assertStringContainsString('redis-trips=0; redis-cmds=0; redis-ms=0', $header);
    $this->assertStringNotContainsString('off', $header);
  }

}

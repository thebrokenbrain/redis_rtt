<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\StackMiddleware;

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\redis_rtt\Client\CountingClient;
use Drupal\redis_rtt\Redis\WriteBatch;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Reports per-request round-trip counts on a response header.
 *
 * This is the measurement half of the module. Latency work that is not measured
 * before and after is guesswork, and in a topology where the cache is a network
 * hop away the metric that predicts response time is the number of sequential
 * network waits, which no standard Drupal profiler reports.
 *
 * Emits, when enabled:
 *   X-Redis-RTT: redis-trips=41; redis-cmds=180; redis-ms=27.4; db-queries=23;
 *                db-ms=14.1; batches=7; batched-writes=643
 *
 * A middleware rather than a response subscriber, and deliberately so. As a
 * subscriber this ran inside the page cache, which meant the header was stored
 * along with the cached response and then served to every later request that
 * hit that cache entry. Two things went wrong with that:
 *
 *  - The numbers were a lie. A page served from the page cache reported the
 *    round trips of the request that *built* it - hundreds - when the request
 *    actually being served had made almost none.
 *  - The header outlived its own setting. Switching the report off left it
 *    being served from cache until that entry was invalidated, which is a
 *    diagnostic header leaking to real users.
 *
 * Sitting outside the page cache fixes both: the header describes the request
 * in front of it, and it disappears the moment the setting does.
 *
 * Enable with $settings['redis_rtt_report'] = TRUE, and count_commands on the
 * connection. Keep it off by default: the database query log holds every query
 * of the request in memory.
 */
class RoundTripReportMiddleware implements HttpKernelInterface {

  /**
   * The database log key.
   */
  protected const LOG_KEY = 'redis_rtt';

  /**
   * Constructs the middleware.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   The kernel to decorate.
   * @param \Drupal\redis_rtt\Redis\WriteBatch|null $batch
   *   (optional) The write batch, sent before the report is built. Batched
   *   writes normally leave at the end of the request, after the response has
   *   gone out; a report that stopped before them would under-count almost
   *   every round trip they cost, which is exactly the number this header
   *   exists to state.
   *
   *   Almost, and not all - and the gap is bigger and differently caused than
   *   this used to say. The header has to be set on a response that is about to
   *   be returned, so it is written before the response is sent and before
   *   kernel.terminate. Everything the request does with Redis after that point
   *   is missing from every field, not just from the batch counters.
   *
   *   Measured on a warm authenticated node view: the header reported 24 round
   *   trips against stock and 16 against this module, where the wire saw 35 and
   *   26. That is 11 and 10 hidden, and only ONE of them falls after the
   *   response - the other ten are BigPipe placeholders being rendered inside
   *   Response::send(), all of them reads. The one write from a
   *   needs_destruction service that this used to name as the whole of the gap
   *   is a tenth of it.
   *
   *   The consequence worth knowing is not the absolute numbers but what they do
   *   to a percentage: the hidden block is nearly constant and both
   *   configurations pay it, so subtracting it from numerator and denominator
   *   inflates the saving. That warm view reads as -33.3% from the header and is
   *   -25.7% on the wire. Cold pages barely move (-45.6% against -45.2%), and in
   *   at least one scenario the bias runs the other way. Treat the header as a
   *   floor, quote wire figures for percentages, and let MONITOR arbitrate.
   */
  public function __construct(
    protected HttpKernelInterface $httpKernel,
    protected ?WriteBatch $batch = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    if ($type !== self::MAIN_REQUEST || !Settings::get('redis_rtt_report', FALSE)) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    CountingClient::reset();
    try {
      Database::startLog(static::LOG_KEY);
    }
    catch (\Exception) {
      // No database connection yet, or logging already started.
    }

    $response = $this->httpKernel->handle($request, $type, $catch);

    // Send whatever is still queued, so the counts below include it. Quietly:
    // the response is already built, and a diagnostic header is no reason to
    // turn a failed cache write into a failed request.
    $this->batch?->sendQuietly();

    $response->headers->set('X-Redis-RTT', implode('; ', $this->report()));

    if (Settings::get('redis_rtt_report_top_commands', FALSE)) {
      $response->headers->set('X-Redis-RTT-Commands', implode('; ', $this->topCommands()));
    }

    return $response;
  }

  /**
   * Builds the main report.
   *
   * @return string[]
   *   The report fields.
   */
  protected function report(): array {
    $parts = [
      'redis-trips=' . CountingClient::$roundTrips,
      'redis-cmds=' . CountingClient::$commands,
      'redis-ms=' . round(CountingClient::$nanoseconds / 1e6, 1),
    ];

    try {
      $queries = Database::getLog(static::LOG_KEY) ?: [];
      $parts[] = 'db-queries=' . count($queries);
      $parts[] = 'db-ms=' . round(array_sum(array_column($queries, 'time')) * 1000, 1);
    }
    catch (\Exception) {
      // Logging was never started, or the connection is gone.
    }

    if ($this->batch) {
      $stats = $this->batch->getStats();
      $parts[] = 'batches=' . $stats['batches'];
      $parts[] = 'batched-writes=' . $stats['writes'];
    }

    return $parts;
  }

  /**
   * Builds the per-command breakdown.
   *
   * @return string[]
   *   The most-used commands with their counts.
   */
  protected function topCommands(): array {
    $by_command = CountingClient::$byCommand;
    arsort($by_command);
    $top = [];
    foreach (array_slice($by_command, 0, 8, TRUE) as $name => $count) {
      $top[] = $name . '=' . $count;
    }
    return $top;
  }

}

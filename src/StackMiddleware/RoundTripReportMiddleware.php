<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\StackMiddleware;

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\redis_rtt\Client\CountingClient;
use Drupal\redis_rtt\Redis\CommandBufferInterface;
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
 *                db-ms=14.1; buffer-pipelines=0; buffer-pending=31;
 *                buffer-deduped=12
 *
 * Note buffer-pipelines: the header is built here, on the way out of the
 * kernel, and the buffered writes are sent from a shutdown function after
 * that. A normal request therefore reports zero pipelines and a non-empty
 * buffer-pending; anything above zero means an intermediate flush was forced
 * by redis_rtt_max_pending_writes, on the critical path.
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

  public function __construct(
    protected HttpKernelInterface $httpKernel,
    protected CommandBufferInterface $buffer,
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

    $stats = $this->buffer->getStats();
    $parts[] = 'buffer-pipelines=' . $stats['pipelines'];
    $parts[] = 'buffer-pending=' . $stats['pending'];
    $parts[] = 'buffer-deduped=' . $stats['deduped'];

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

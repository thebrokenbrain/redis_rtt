<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\EventSubscriber;

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\redis_rtt\Client\CountingClient;
use Drupal\redis_rtt\Redis\CommandBufferInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reports per-request round-trip counts on a response header.
 *
 * This is the measurement half of the module. Latency work that is not measured
 * before and after is guesswork, and in a cross-AZ topology the metric that
 * predicts response time is the number of sequential network waits, which no
 * standard Drupal profiler reports.
 *
 * Emits, when enabled:
 *   X-Redis-RTT: redis-trips=41; redis-cmds=180; redis-ms=27.4; db-queries=23;
 *              db-ms=14.1; buffer-pipelines=1; buffer-deduped=12
 *
 * Enable with $settings['redis_rtt_report'] = TRUE. Keep it off by default: the
 * database query log holds every query of the request in memory.
 */
class RoundTripReportSubscriber implements EventSubscriberInterface {

  /**
   * The database log key.
   */
  protected const LOG_KEY = 'redis_rtt';

  public function __construct(protected CommandBufferInterface $buffer) {}

  /**
   * Starts the database query log.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || !$this->enabled()) {
      return;
    }
    Database::startLog(static::LOG_KEY);
  }

  /**
   * Attaches the report header.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !$this->enabled()) {
      return;
    }

    $parts = [];

    $parts[] = 'redis-trips=' . CountingClient::$roundTrips;
    $parts[] = 'redis-cmds=' . CountingClient::$commands;
    $parts[] = 'redis-ms=' . round(CountingClient::$nanoseconds / 1e6, 1);

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

    $event->getResponse()->headers->set('X-Redis-RTT', implode('; ', $parts));

    if (Settings::get('redis_rtt_report_top_commands', FALSE)) {
      $by_command = CountingClient::$byCommand;
      arsort($by_command);
      $top = [];
      foreach (array_slice($by_command, 0, 8, TRUE) as $name => $count) {
        $top[] = $name . '=' . $count;
      }
      $event->getResponse()->headers->set('X-Redis-RTT-Commands', implode('; ', $top));
    }
  }

  /**
   * Whether reporting is switched on.
   */
  protected function enabled(): bool {
    return (bool) Settings::get('redis_rtt_report', FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => [['onRequest', 1000]],
      // Late enough that page_cache and dynamic_page_cache have already run.
      KernelEvents::RESPONSE => [['onResponse', -1000]],
    ];
  }

}

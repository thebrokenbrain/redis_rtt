<?php

/**
 * @file
 * Captures the shutdown functions \Drupal\redis_rtt\Redis\WriteBatch registers.
 *
 * PHP resolves an unqualified function call inside a namespace against that
 * namespace first, so declaring these here makes WriteBatch register with the
 * test instead of with PHP. Without it there is no way to assert on the
 * end-of-request send at all: the real registration only runs when the process
 * is already dying, which is exactly why deleting it left the suite green while
 * a live site lost every batched write of a page.
 */

declare(strict_types=1);

namespace Drupal\redis_rtt\Redis;

// Shutdown callbacks registered so far, in order.
$GLOBALS['redis_rtt_test_shutdown'] = [];

// Which of the two was used for each registration, in order. The distinction is
// the whole point of one of the tests: registering with Drupal is right until
// its dispatcher has finished walking its own list, and wrong afterwards,
// because nobody reads that list again.
$GLOBALS['redis_rtt_test_shutdown_via'] = [];

/**
 * Stands in for the Drupal dispatcher.
 *
 * @param callable $callback
 *   The callback being registered.
 */
function drupal_register_shutdown_function(callable $callback): void {
  $GLOBALS['redis_rtt_test_shutdown'][] = $callback;
  $GLOBALS['redis_rtt_test_shutdown_via'][] = 'drupal';
}

/**
 * Stands in for PHP's own registration.
 *
 * @param callable $callback
 *   The callback being registered.
 */
function register_shutdown_function(callable $callback): void {
  $GLOBALS['redis_rtt_test_shutdown'][] = $callback;
  $GLOBALS['redis_rtt_test_shutdown_via'][] = 'php';
}

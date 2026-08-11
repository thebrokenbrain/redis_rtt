<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\redis_rtt\Cache\ShortcutStoreInterface;

/**
 * A shortcut store backed by a plain array, standing in for APCu.
 *
 * Shared between the service instances of successive simulated requests,
 * exactly as APCu is shared between requests handled by the same PHP-FPM
 * worker. That is what makes it possible to test the learning behaviour at all:
 * the whole point is what one request teaches the next.
 */
final class ArrayShortcutStore implements ShortcutStoreInterface {

  /**
   * The stored entries.
   *
   * @var array<string, array<mixed>>
   */
  public array $entries = [];

  /**
   * {@inheritdoc}
   */
  public function get(string $key): ?array {
    return $this->entries[$key] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, array $value): void {
    $this->entries[$key] = $value;
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): void {
    unset($this->entries[$key]);
  }

}

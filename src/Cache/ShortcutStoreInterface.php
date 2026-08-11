<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

/**
 * Stores small facts one request learns for the benefit of the next.
 *
 * Two things use it: the mapping from a render cache element's initial cache
 * contexts to its final ones, and the set of cache tags a request tends to look
 * at. Both are structural - derived from code and configuration rather than
 * from request data - so they can live in a node-local store with no
 * invalidation protocol at all. Anything that stops being true is detected when
 * it is used and dropped.
 *
 * Nothing stored here is authoritative. A wrong or missing entry costs a little
 * efficiency, never correctness.
 *
 * @see \Drupal\redis_rtt\Cache\RedirectShortcutVariationCache
 * @see \Drupal\redis_rtt\Cache\PreloadingRedisCacheTagsChecksum
 */
interface ShortcutStoreInterface {

  /**
   * Returns a learned value.
   *
   * @param string $key
   *   The mapping key.
   *
   * @return array<mixed>|null
   *   The stored value, or NULL when nothing is known.
   */
  public function get(string $key): ?array;

  /**
   * Records a value.
   *
   * @param string $key
   *   The mapping key.
   * @param array<mixed> $value
   *   The value to remember.
   */
  public function set(string $key, array $value): void;

  /**
   * Forgets a value that no longer describes reality.
   *
   * @param string $key
   *   The mapping key.
   */
  public function delete(string $key): void;

}

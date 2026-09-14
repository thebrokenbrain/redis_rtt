<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Cache\CacheTagsChecksumInterface;

/**
 * A checksum provider that records what it was asked, and in what order.
 *
 * The order is the point: tags offered for preloading after the first entry has
 * already been validated arrive too late to be batched, and the saving they
 * exist for is gone even though every call still happened.
 */
final class RecordingChecksumProvider implements CacheTagsChecksumInterface {

  /**
   * Every tag handed to ::registerCacheTagsForPreload(), in order.
   *
   * @var string[]
   */
  public array $preloaded = [];

  /**
   * Which method was called first: 'preload' or 'validate'.
   */
  public ?string $firstCall = NULL;

  /**
   * Collects tags a caller expects to need shortly.
   *
   * @param string[] $tags
   *   The cache tags.
   */
  public function registerCacheTagsForPreload(array $tags): void {
    $this->firstCall ??= 'preload';
    foreach ($tags as $tag) {
      if (!in_array($tag, $this->preloaded, TRUE)) {
        $this->preloaded[] = $tag;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isValid($checksum, array $tags): bool {
    $this->firstCall ??= 'validate';
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentChecksum(array $tags): string {
    return '0';
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {}

  /**
   * {@inheritdoc}
   *
   * @param string[] $tags
   *   The cache tags to invalidate.
   */
  public function invalidateTags(array $tags): void {}

}

<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\redis\ClientFactory;

/**
 * Builds one \Drupal\redis_rtt\Cache\BatchingRedisBackend per bin.
 *
 * Selected with, in settings.php:
 *
 * @code
 * $settings['cache']['default'] = 'cache.backend.redis_rtt';
 * @endcode
 */
class BatchingCacheBackendFactory implements CacheFactoryInterface {

  /**
   * Instantiated bins, keyed by bin name.
   *
   * Renderer and other callers fetch backends straight from the factory; reuse
   * the instances so per-bin metadata such as the last delete-all marker is
   * only resolved once per request.
   *
   * @var \Drupal\redis_rtt\Cache\BatchingRedisBackend[]
   */
  protected array $bins = [];

  public function __construct(
    protected ClientFactory $clientFactory,
    protected CacheTagsChecksumInterface $checksumProvider,
    protected SerializationInterface $serializer,
  ) {}

  /**
   * {@inheritdoc}
   *
   * @param string $bin
   *   The cache bin to build.
   *
   * @return \Drupal\redis_rtt\Cache\BatchingRedisBackend
   *   The backend for that bin.
   */
  public function get($bin): BatchingRedisBackend {
    if (!isset($this->bins[$bin])) {
      $this->bins[$bin] = new BatchingRedisBackend(
        $bin,
        $this->clientFactory->getClient(),
        $this->checksumProvider,
        $this->serializer,
      );
    }
    return $this->bins[$bin];
  }

}

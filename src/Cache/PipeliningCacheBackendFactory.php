<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\redis\ClientFactory;

/**
 * Builds one \Drupal\redis_rtt\Cache\PipeliningRedisBackend per bin.
 *
 * Selected with, in settings.php:
 *
 * @code
 * $settings['cache']['default'] = 'cache.backend.redis_rtt';
 * @endcode
 */
class PipeliningCacheBackendFactory implements CacheFactoryInterface {

  /**
   * Instantiated bins, keyed by bin name.
   *
   * Renderer and other callers fetch backends straight from the factory; reuse
   * the instances so per-bin metadata such as the last delete-all marker is
   * only resolved once per request.
   *
   * @var \Drupal\redis_rtt\Cache\PipeliningRedisBackend[]
   */
  protected array $bins = [];

  /**
   * Constructs the factory.
   *
   * @param \Drupal\redis\ClientFactory $clientFactory
   *   The Redis client factory.
   * @param \Drupal\Core\Cache\CacheTagsChecksumInterface $checksumProvider
   *   The cache tags checksum provider.
   * @param \Drupal\Component\Serialization\SerializationInterface $serializer
   *   The serializer.
   */
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
   * @return \Drupal\redis_rtt\Cache\PipeliningRedisBackend
   *   The backend for that bin.
   */
  public function get($bin): PipeliningRedisBackend {
    if (!isset($this->bins[$bin])) {
      $this->bins[$bin] = new PipeliningRedisBackend(
        $bin,
        $this->clientFactory->getClient(),
        $this->checksumProvider,
        $this->serializer,
      );
    }
    return $this->bins[$bin];
  }

}

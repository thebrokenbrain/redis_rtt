<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Component\Serialization\SerializationInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;
use Drupal\redis_rtt\Redis\CommandBufferInterface;
use Drupal\redis_rtt\Redis\NullCommandBuffer;
use Drupal\redis\ClientFactory;

/**
 * Builds DeferredRedisBackend cache bins.
 *
 * Drop-in replacement for cache.backend.redis. Enable in settings.php with:
 * @code
 * $settings['cache']['default'] = 'cache.backend.redis_rtt';
 * @endcode
 */
class DeferredCacheBackendFactory implements CacheFactoryInterface {

  /**
   * Instantiated bins, keyed by bin name.
   *
   * Renderer and other callers fetch backends straight from the factory; reuse
   * the instances so per-bin metadata such as the last delete-all marker is
   * only resolved once per request.
   *
   * @var \Drupal\redis_rtt\Cache\DeferredRedisBackend[]
   */
  protected array $bins = [];

  /**
   * Bins that must never buffer their writes.
   *
   * @var string[]
   */
  protected array $unbufferedBins;

  public function __construct(
    protected ClientFactory $clientFactory,
    protected CacheTagsChecksumInterface $checksumProvider,
    protected SerializationInterface $serializer,
    protected CommandBufferInterface $buffer,
    ?Settings $settings = NULL,
  ) {
    // The container bin holds the compiled service container. It is written
    // once per deployment by whichever request loses the rebuild race, and a
    // later request must be able to read it back, so it is never buffered.
    $this->unbufferedBins = (array) ($settings
      ? $settings->get('redis_rtt_unbuffered_bins', ['container'])
      : Settings::get('redis_rtt_unbuffered_bins', ['container']));
  }

  /**
   * {@inheritdoc}
   */
  public function get($bin): DeferredRedisBackend {
    if (!isset($this->bins[$bin])) {
      $buffer = in_array($bin, $this->unbufferedBins, TRUE)
        ? new NullCommandBuffer()
        : $this->buffer;

      $this->bins[$bin] = new DeferredRedisBackend(
        $bin,
        $this->clientFactory->getClient(),
        $this->checksumProvider,
        $this->serializer,
        $buffer,
      );
    }
    return $this->bins[$bin];
  }

}

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
    // Three bins are excluded by default, for two different reasons.
    //
    // 'container' holds the compiled service container. It is written once per
    // deployment by whichever request loses the rebuild race, and a later
    // request must be able to read it back, so it is never buffered.
    //
    // 'entity' and 'default' are excluded because a buffered write outliving
    // another process' delete does real damage there, and nothing corrects it.
    // Core removes an entity's cache entry with an explicit delete on every
    // save (ContentEntityStorageBase::doPostSave() -> resetCache()), and tags
    // it only with <type>_values and entity_field_info, which no content save
    // invalidates - so a page view whose buffered write straddles a save
    // recreates the pre-save entity, and it stays. Because the node edit form
    // is built from the entity, the next save from that form then writes the
    // stale values back into the database. 'default' is the same shape:
    // ExtensionList::reset() deletes core.extension.list.module and its
    // siblings, which carry no tag at all.
    //
    // This is a list of what is known to be dangerous, not a proof that the
    // rest is safe. Any process deleting a single key from a buffered bin can
    // have that delete undone; see CommandBuffer::flush().
    $this->unbufferedBins = (array) ($settings
      ? $settings->get('redis_rtt_unbuffered_bins', ['container', 'entity', 'default'])
      : Settings::get('redis_rtt_unbuffered_bins', ['container', 'entity', 'default']));
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

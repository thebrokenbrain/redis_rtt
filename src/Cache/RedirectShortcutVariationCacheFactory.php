<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Core\Cache\VariationCacheFactoryInterface;
use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds RedirectShortcutVariationCache bins.
 *
 * Drop-in replacement for the core variation_cache_factory service.
 */
class RedirectShortcutVariationCacheFactory implements VariationCacheFactoryInterface {

  /**
   * Instantiated variation cache bins.
   *
   * @var \Drupal\Core\Cache\VariationCacheInterface[]
   */
  protected array $bins = [];

  public function __construct(
    protected RequestStack $requestStack,
    protected CacheFactoryInterface $cacheFactory,
    protected CacheContextsManager $cacheContextsManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($bin) {
    if (!isset($this->bins[$bin])) {
      $this->bins[$bin] = new RedirectShortcutVariationCache(
        $this->requestStack,
        $this->cacheFactory->get($bin),
        $this->cacheContextsManager,
        $bin,
        new ApcuShortcutStore($bin),
      );
    }
    return $this->bins[$bin];
  }

}

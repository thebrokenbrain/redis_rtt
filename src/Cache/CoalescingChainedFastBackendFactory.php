<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Core\Cache\ChainedFastBackendFactory;

/**
 * Builds CoalescingChainedFastBackend bins.
 *
 * Drop-in replacement for cache.backend.chainedfast: it resolves the consistent
 * and fast backends exactly like core does and only swaps the class used to
 * chain them.
 */
class CoalescingChainedFastBackendFactory extends ChainedFastBackendFactory {

  /**
   * {@inheritdoc}
   */
  public function get($bin) {
    // Mirrors the parent's own guard: the property is left unset when APCu is
    // unavailable or during installation.
    // @phpstan-ignore isset.property
    if (isset($this->fastServiceName) && $this->fastServiceName !== $this->consistentServiceName) {
      return new CoalescingChainedFastBackend(
        $this->container->get($this->consistentServiceName)->get($bin),
        $this->container->get($this->fastServiceName)->get($bin),
        $bin,
      );
    }
    return $this->container->get($this->consistentServiceName)->get($bin);
  }

}

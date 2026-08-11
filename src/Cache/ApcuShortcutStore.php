<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Core\Site\Settings;

/**
 * Keeps learned redirect mappings in APCu, with a per-request read memo.
 *
 * Falls back to the request-local memo alone when APCu is unavailable, so the
 * behaviour is always correct - just less effective, since nothing survives
 * past the request. That fallback is what CLI processes get, where APCu is
 * usually disabled but a long-running drush or cron process still benefits
 * within its own run.
 */
class ApcuShortcutStore implements ShortcutStoreInterface {

  /**
   * Request-local memo, also the whole store when APCu is unavailable.
   *
   * @var array<string, array<mixed>>
   */
  protected array $memo = [];

  /**
   * Whether APCu can be used.
   */
  protected bool $apcu;

  /**
   * Key namespace, rotated by deployment via the APCu prefix.
   */
  protected string $prefix;

  /**
   * Entry lifetime in seconds.
   */
  protected int $ttl;

  public function __construct(string $bin) {
    $this->apcu = function_exists('apcu_fetch')
      && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)
      && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL));
    $this->prefix = Settings::getApcuPrefix('redis_rtt_vc', DRUPAL_ROOT) . ':' . $bin . ':';
    $this->ttl = (int) Settings::get('redis_rtt_redirect_shortcut_ttl', 86400);
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $key): ?array {
    if (array_key_exists($key, $this->memo)) {
      return $this->memo[$key] ?: NULL;
    }
    if (!$this->apcu) {
      return NULL;
    }
    $found = FALSE;
    $value = apcu_fetch($this->prefix . $key, $found);
    if (!$found || !is_array($value) || !$value) {
      return NULL;
    }
    $this->memo[$key] = $value;
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, array $value): void {
    if (!$value) {
      return;
    }
    $this->memo[$key] = $value;
    if ($this->apcu) {
      apcu_store($this->prefix . $key, $value, $this->ttl);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $key): void {
    unset($this->memo[$key]);
    if ($this->apcu) {
      apcu_delete($this->prefix . $key);
    }
  }

}

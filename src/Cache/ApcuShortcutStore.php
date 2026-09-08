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

  /**
   * Whether APCu has refused a write, so this request stops attempting them.
   */
  protected bool $full = FALSE;

  /**
   * How many mappings the in-process memo may hold.
   */
  protected int $memoLimit;

  public function __construct(string $bin) {
    $this->apcu = function_exists('apcu_fetch')
      && filter_var(ini_get('apc.enabled'), FILTER_VALIDATE_BOOL)
      && (PHP_SAPI !== 'cli' || filter_var(ini_get('apc.enable_cli'), FILTER_VALIDATE_BOOL));
    $this->prefix = Settings::getApcuPrefix('redis_rtt_vc', DRUPAL_ROOT) . ':' . $bin . ':';
    $this->ttl = (int) Settings::get('redis_rtt_redirect_shortcut_ttl', 86400);
    $this->memoLimit = max(1, (int) Settings::get('redis_rtt_shortcut_memo_limit', 1000));
  }

  /**
   * Remembers a mapping for this process, without growing without end.
   *
   * The memo used to be unbounded, and where it matters most it is not a memo
   * at all: with APCu off - which is the normal state of a CLI process, and
   * this class's docblock names exactly that case - it is the entire store. A
   * long drush run or a queue worker touching many distinct elements then grew
   * it one entry per element for the life of the process. Measured at roughly
   * 740 bytes each, straight line, no plateau: 60,000 elements cost 42 MB and
   * nothing ever gave it back.
   *
   * Its sibling, \Drupal\redis_rtt\Cache\RedirectShortcutVariationCache,
   * had bounded its own memo for this exact reason and named this exact
   * scenario in the comment. This one had not.
   *
   * Dropping the oldest costs one round trip if it is wanted again, which is
   * the right price for a memo.
   *
   * @param string $key
   *   The element's key.
   * @param array<mixed> $value
   *   The mapping to remember.
   */
  protected function memoise(string $key, array $value): void {
    if (!array_key_exists($key, $this->memo) && count($this->memo) >= $this->memoLimit) {
      array_shift($this->memo);
    }
    $this->memo[$key] = $value;
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
    $this->memoise($key, $value);
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function set(string $key, array $value): void {
    if (!$value) {
      return;
    }
    $this->memoise($key, $value);
    if (!$this->apcu || $this->full) {
      return;
    }

    // Core shares this segment: the class loader map and the APCu cache backend
    // live there too, and APCu answers a full segment with a complete expunge,
    // not an eviction of the least useful key. Filling it therefore costs every
    // worker its class map, repeatedly.
    //
    // The guard below is weaker than it looks and the comment used to overstate
    // it. With apc.ttl at its default of 0 a full segment is purged wholesale
    // and the write then succeeds, so ::$full never gets set and this class
    // never learns it filled anything. What actually bounds the APCu side is
    // the entry TTL - $settings['redis_rtt_redirect_shortcut_ttl'], a day by
    // default - and APCu's own expunge. The guard still helps on the builds
    // that do return FALSE, so it stays; it is just not the protection it was
    // written up as.
    if (apcu_store($this->prefix . $key, $value, $this->ttl) === FALSE) {
      $this->full = TRUE;
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

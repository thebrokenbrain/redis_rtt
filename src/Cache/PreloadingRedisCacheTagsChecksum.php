<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Core\Site\Settings;
use Drupal\redis\Cache\RedisCacheTagsChecksum;
use Drupal\redis\ClientFactory;

/**
 * Cache tag checksum provider that resolves a request's tags in one MGET.
 *
 * Two problems are being solved here, and the second is the expensive one.
 *
 * First, the redis 2.x backend calls ::registerCacheTagsForPreload() on the
 * checksum provider after a multi-get, but only when the provider implements
 * that method. Core only ships CacheTagsChecksumPreloadInterface from 11.1
 * onwards, so on Drupal 10.x the redis module's provider implements nothing but
 * an empty back-compat marker and the preload hook is dead code. Implementing
 * it lets the first MGET that has to happen anyway also fetch every other tag
 * seen in the same read.
 *
 * Second - and this only shows up when you actually watch the wire - the
 * provider issues a plain GET whenever a checksum is wanted for a single tag:
 *
 * @code
 *   if (count($tags) == 1) {
 *     return [$tag => (int) $this->client->get($this->getTagKey($tag))];
 *   }
 * @endcode
 *
 * That is the common case, not the rare one. Validating the config entities and
 * discovery caches behind one authenticated page request produces some thirty
 * of those single-tag lookups, each its own sequential round trip, and the
 * preload hook cannot help because nothing has registered those tags: they are
 * reached one at a time as the render tree is walked.
 *
 * The fix is the observation that the set of tags a request touches is almost
 * the same set every time. Tags like config:system.site, routes, entity_types,
 * library_info or local_task are touched by essentially every request on the
 * site. So: remember which tags a request looked at, and on the next request
 * fetch that whole set in the first MGET that happens. Thirty round trips
 * become one.
 *
 * This is a batching change only. Every checksum is still read fresh from Redis
 * on every request - nothing is cached across requests, no staleness is
 * introduced, and a tag that turns out not to be needed is simply discarded. A
 * tag missing from the learned set costs nothing either: it is fetched on
 * demand exactly as before, and joins the set for next time.
 */
class PreloadingRedisCacheTagsChecksum extends RedisCacheTagsChecksum {

  /**
   * Tags seen in a cache read but not yet resolved, as a set.
   *
   * @var array<string, true>
   */
  protected array $preloadTags = [];

  /**
   * Every tag whose checksum this request asked for, as a set.
   *
   * @var array<string, true>
   */
  protected array $seenTags = [];

  /**
   * Whether the learned set has already been folded into a lookup.
   */
  protected bool $warmSetUsed = FALSE;

  /**
   * Whether the end-of-request learning step has been registered.
   */
  protected bool $learningRegistered = FALSE;

  /**
   * Where the learned set lives.
   */
  protected ShortcutStoreInterface $store;

  /**
   * Key the learned set is stored under.
   */
  protected const WARM_SET_KEY = 'tagset';

  /**
   * Upper bound on how many tags are preloaded.
   *
   * One MGET of a few hundred keys is a single round trip and a few kilobytes
   * of reply; that is a good trade against thirty sequential round trips. The
   * bound exists so a site with unbounded tag churn cannot grow it without
   * limit.
   */
  protected int $limit;

  /**
   * How many requests a tag must appear in before it is worth preloading.
   *
   * The set is ranked by how often a tag is seen, not by how recently. That
   * distinction matters: tags like node:123 are seen by exactly the one request
   * that renders that node, so ranking by recency would fill the set with
   * per-content tags that will never be wanted again and push out the
   * config:*, routes and entity_types tags that every request does want.
   */
  protected int $minHits;

  public function __construct(ClientFactory $factory, ?ShortcutStoreInterface $store = NULL) {
    parent::__construct($factory);
    $this->store = $store ?? new ApcuShortcutStore('cachetags');
    $this->limit = (int) Settings::get('redis_rtt_tag_warmset_limit', 400);
    $this->minHits = (int) Settings::get('redis_rtt_tag_warmset_min_hits', 3);
  }

  /**
   * Returns the tags worth preloading, most frequently seen first.
   *
   * @return string[]
   *   The tag names.
   */
  protected function warmSet(): array {
    $stats = $this->store->get(static::WARM_SET_KEY) ?? [];
    if (!$stats) {
      return [];
    }
    arsort($stats);
    $warm = [];
    foreach ($stats as $tag => $hits) {
      // Sorted descending, so the first tag below the threshold ends it.
      if ($hits < $this->minHits || count($warm) >= $this->limit) {
        break;
      }
      $warm[] = $tag;
    }
    return $warm;
  }

  /**
   * Registers tags that are likely to be checked shortly.
   *
   * @param string[] $tags
   *   The cache tags found in a batch of cache entries.
   */
  public function registerCacheTagsForPreload(array $tags): void {
    foreach ($tags as $tag) {
      if (!isset($this->tagCache[$tag])) {
        $this->preloadTags[$tag] = TRUE;
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param string[] $tags
   *   The tags whose invalidation counts are wanted.
   *
   * @return int[]
   *   The counts, keyed by tag.
   */
  protected function getTagInvalidationCounts(array $tags) {
    $requested = array_values($tags);
    foreach ($requested as $tag) {
      $this->seenTags[$tag] = TRUE;
    }
    $this->registerLearning();

    $known = array_keys($this->tagCache);
    $extra = array_diff(array_keys($this->preloadTags), $requested, $known);
    $this->preloadTags = [];

    // The first lookup of the request carries the whole learned set along.
    // Later lookups do not, because by then most of it is in the static cache
    // and re-fetching would be waste.
    if (!$this->warmSetUsed) {
      $this->warmSetUsed = TRUE;
      if ($warm = $this->warmSet()) {
        $extra = array_merge($extra, array_diff($warm, $requested, $extra, $known));
      }
    }

    if (!$extra) {
      return parent::getTagInvalidationCounts($requested);
    }

    $all = array_merge($requested, array_values($extra));
    $values = $this->client->mget(array_map([$this, 'getTagKey'], $all));
    if (!$values) {
      return [];
    }

    $counts = array_map('intval', array_combine($all, $values));

    // Anything that was only fetched speculatively goes straight into the
    // static cache; the caller only gets what it asked for.
    $requested_keys = array_flip($requested);
    $this->tagCache += array_diff_key($counts, $requested_keys);

    return array_intersect_key($counts, $requested_keys);
  }

  /**
   * Folds this request's tags into the learned set, at end of request.
   *
   * Runs after fastcgi_finish_request(), so it is off the critical path. The
   * counters are approximate by design: several workers update the same APCu
   * entry and lost increments do not matter to a ranking.
   */
  public function learn(): void {
    if (!$this->seenTags) {
      return;
    }
    $stats = $this->store->get(static::WARM_SET_KEY) ?? [];
    foreach (array_keys($this->seenTags) as $tag) {
      $stats[$tag] = ($stats[$tag] ?? 0) + 1;
    }
    // Trim lazily, keeping the most frequently seen. Allowing the table to grow
    // past the preload limit before trimming leaves room for a tag to build up
    // a count before it is judged.
    if (count($stats) > $this->limit * 3) {
      arsort($stats);
      $stats = array_slice($stats, 0, $this->limit * 2, TRUE);
    }
    $this->store->set(static::WARM_SET_KEY, $stats);
    $this->seenTags = [];
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    parent::reset();
    $this->preloadTags = [];
    $this->warmSetUsed = FALSE;
  }

  /**
   * Arranges for ::learn() to run once, at the end of the request.
   */
  protected function registerLearning(): void {
    if ($this->learningRegistered) {
      return;
    }
    $this->learningRegistered = TRUE;
    if (function_exists('drupal_register_shutdown_function')) {
      drupal_register_shutdown_function([$this, 'learn']);
    }
    else {
      register_shutdown_function([$this, 'learn']);
    }
  }

}

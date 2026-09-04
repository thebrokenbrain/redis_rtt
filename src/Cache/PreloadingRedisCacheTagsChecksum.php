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
 * that method. Core only ships CacheTagsChecksumPreloadInterface, and the trait
 * code behind it, from 11.2 onwards; on 10.x and on 11.0/11.1 the redis
 * module's provider implements nothing but an empty back-compat marker and the
 * preload hook is dead code. Implementing it lets the first MGET that has to
 * happen anyway also fetch every other tag seen in the same read.
 *
 * From 11.2 core consumes the registered tags itself, in
 * CacheTagsChecksumTrait::calculateChecksum(), which merges them into the tag
 * list and empties $preloadTags before ::getTagInvalidationCounts() is ever
 * called. Nothing below branches on the core version for that. The property is
 * simply kept in the shape core expects - a plain list of tag names - so that
 * whichever of the two consumes it, the same tags end up in the same MGET, and
 * the half of this class that the version makes no difference to, the learned
 * set, is unaffected either way.
 *
 * One consequence of that hand-over is worth stating plainly, because it is a
 * gap and not a guarantee: on 11.2 core merges the registered tags into the
 * requested list *without* re-checking $delayedTags, so a tag registered by a
 * cache read and only then invalidated inside an open transaction reaches this
 * class as a requested tag, which it does not filter. The pre-invalidation
 * count is then pinned in the static cache for the rest of the process. Stock
 * \Drupal\redis\Cache\RedisCacheTagsChecksum has the same gap on 11.2, so it
 * is upstream rather than something this class introduces, and closing it here
 * would mean re-taking ownership of the mechanism core has just taken over.
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
   * Tags seen in a cache read but not yet resolved, in registration order.
   *
   * A list of tag names and not a set, because from Drupal 11.2 this property
   * belongs to core's CacheTagsChecksumTrait, which folds it into the tag list
   * with array_merge() and array_unique(). A set does not survive that merge as
   * a set: its boolean values land in the tag list beside the real tags, where
   * TRUE reads as the tag "1". That is a bogus <prefix>:cachetags:1 key in
   * every MGET from then on, and a permanent junk entry in the learned set
   * below.
   *
   * @var string[]
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
   * @param string[] $cache_tags
   *   The cache tags found in a batch of cache entries.
   */
  public function registerCacheTagsForPreload(array $cache_tags): void {
    if (!$cache_tags) {
      return;
    }
    // Don't preload delayed tags that are awaiting invalidation. Their counter
    // in Redis is still the pre-invalidation one - the INCR is held back until
    // the enclosing database transaction commits - so fetching one here would
    // pin the old count in the static tag cache, and nothing clears that cache
    // when the transaction ends. Every later check of that tag in this process
    // would then say the stale entry is still fresh, which for a drush, cron or
    // queue process means the rest of its life.
    //
    // Tags already in the static cache, and tags already queued, are dropped
    // here rather than left for the consumer to deduplicate. Core 11.2 drains
    // this list on every checksum calculation, so a duplicate would cost it
    // little; core 10.3 to 11.1 only drains it when a checksum actually has to
    // be calculated, which never happens while the static cache answers every
    // lookup - and then a list that only ever grows turns a long-running
    // process into a memory leak with a quadratic array_merge() attached.
    $preloadable_tags = array_diff(
      $cache_tags,
      $this->delayedTags,
      array_keys($this->tagCache),
      $this->preloadTags,
    );
    if ($preloadable_tags) {
      $this->preloadTags = array_merge($this->preloadTags, $preloadable_tags);
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
    // Delayed tags are excluded from every speculative fetch, for the reason
    // given in ::registerCacheTagsForPreload(). The filter is repeated here
    // rather than trusted from registration time, because a tag can be
    // registered by a cache read and only then invalidated inside the
    // transaction, and because the learned set below never went through
    // registration at all. On 11.2 the first of those two cases is core's to
    // catch and it does not, as the class docblock records; here the repeat
    // still covers the learned set on every version. Tags that were genuinely
    // asked for are left alone: dropping one would make the caller record a
    // zero for it instead.
    $extra = array_unique(array_diff($this->preloadTags, $requested, $known, $this->delayedTags));
    $this->preloadTags = [];

    // The first lookup of the request carries the whole learned set along.
    // Later lookups do not, because by then most of it is in the static cache
    // and re-fetching would be waste.
    if (!$this->warmSetUsed) {
      $this->warmSetUsed = TRUE;
      if ($warm = $this->warmSet()) {
        $extra = array_merge($extra, array_diff($warm, $requested, $extra, $known, $this->delayedTags));
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

<?php

declare(strict_types=1);

namespace Drupal\redis_rtt\Cache;

use Drupal\Core\Site\Settings;
use Drupal\redis\Cache\RedisCacheTagsChecksum;
use Drupal\redis\ClientFactory;

/**
 * Cache tag checksum provider that resolves a request's tags in one MGET.
 *
 * Two problems, and the second is the expensive one.
 *
 * First, the redis 2.x backend calls ::registerCacheTagsForPreload() after a
 * multi-get, but only when the provider implements it. Core ships the
 * interface behind that from 11.2 onwards; on 10.x and 11.0/11.1 the redis
 * module's provider implements nothing and the hook is dead code. Implementing
 * it lets the first MGET that has to happen anyway fetch every other tag seen
 * in the same read. From 11.2 core consumes the registered tags itself, so the
 * property below is simply kept in the shape core expects and neither path
 * branches on the version.
 *
 * Second, and this is what shows up on the wire: the provider issues a plain
 * GET whenever a checksum is wanted for a single tag, and that is the common
 * case. Validating the config entities and discovery caches behind one
 * authenticated page produces some thirty of those, each its own sequential
 * round trip, and the preload hook cannot help because nothing has registered
 * them - they are reached one at a time as the render tree is walked.
 *
 * The fix rests on an observation: the set of tags a request touches is almost
 * the same every time. config:system.site, routes, entity_types, library_info
 * are touched by essentially every request. So remember which tags a request
 * looked at, and on the next one fetch that whole set in the first MGET that
 * happens.
 *
 * This is a batching change, with one exception that is documented on
 * ::calculateChecksum(): a count fetched before anyone asked for it may answer
 * for its tag for $settings['redis_rtt_tag_warmset_ttl'] seconds. Everything
 * else is still read fresh from Redis on every request.
 *
 * One upstream gap worth knowing: on 11.2 core merges registered tags into the
 * requested list without re-checking $delayedTags, so a tag registered by a
 * cache read and only then invalidated inside an open transaction reaches this
 * class as a requested tag. Stock RedisCacheTagsChecksum has the same gap
 * there, so it is not introduced here.
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
   * The tags this class is going to preload, kept apart from ::$preloadTags.
   *
   * Core 11 grew a preload of its own on the same property name, and the two
   * cannot share it. Its ::calculateChecksum() drains ::$preloadTags and hands
   * the drained tags to ::getTagInvalidationCounts() mixed in with the ones the
   * caller actually asked for, then puts everything that comes back into the
   * static tag cache. A count fetched on spec would become authoritative for
   * the rest of the process that way, which is exactly what the window below
   * exists to prevent: measured on core 11.4.6, a tag another process had
   * invalidated went on answering with the old count and
   * $settings['redis_rtt_tag_warmset_ttl'] bounded nothing, 0 included.
   *
   * So this class keeps its own list here and leaves ::$preloadTags empty,
   * which switches core's preload off - it is guarded by `if
   * ($this->preloadTags)` - and leaves the speculation entirely to this class,
   * on every supported core. The inherited property stays declared because the
   * trait declares it on 11 and does not on 10, and this class has to be
   * loadable on both.
   *
   * @var string[]
   */
  protected array $pendingPreload = [];

  /**
   * Every tag whose checksum this request asked for, as a set.
   *
   * @var array<string, true>
   */
  protected array $seenTags = [];

  /**
   * Counts fetched before anyone asked for them, with the moment they were.
   *
   * Not the static tag cache: a count nobody requested must not answer for its
   * tag indefinitely. ::getTagInvalidationCounts() promotes one from here only
   * when a caller genuinely asks for that tag and the count is younger than
   * ::$speculativeTtl.
   *
   * @var array<string, array{count: int, at: float}>
   */
  protected array $speculative = [];

  /**
   * Tags the current checksum calculation answered from ::$speculative.
   *
   * Emptied and refilled around every ::calculateChecksum(), which removes them
   * from the static tag cache afterwards. See that method for why.
   *
   * @var array<int, string>
   */
  protected array $answeredSpeculatively = [];

  /**
   * How long a speculatively fetched count may answer for its tag, in seconds.
   *
   * Sized for a web request, which is the case the batching exists for. A
   * longer-lived process re-reads instead, which is the whole point: that is
   * where an invalidation from another process has time to happen.
   */
  protected float $speculativeTtl;

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
   * Upper bound on how many tags the *learned set* carries.
   *
   * It bounds ::warmSet() and nothing else. It does **not** bound the MGET:
   * that also carries ::$preloadTags, which is one tag per entry the read
   * pipeline returned and is limited only by how much the page reads. Measured
   * on a warm authenticated node page, the learned set arrived as the 401 keys
   * this setting allows and the registered tags as 2,736 more, for 320 KB in
   * one request. The docblock here used to describe the bound as covering all
   * of it, which is the number an operator would size a network budget with.
   *
   * The trade is still the one the class is for - 2,736 keys in one round trip
   * against 2,736 round trips - and within a request the count is bounded by
   * the page, so nothing grows without end. But it is bounded by the page, not
   * by this.
   */
  protected int $limit;

  /**
   * How many requests a tag must appear in before it is worth preloading.
   *
   * The set is ranked by how often a tag is seen, not by how recently, on the
   * reasoning that a tag like node:123 is seen by the one request that renders
   * that node and would otherwise crowd out the config:*, routes and
   * entity_types tags every request wants.
   *
   * That reasoning does not survive measurement, and this docblock used to
   * claim the outcome rather than the intent. On a content site node tags are
   * not seen once: the listing, the node page and the blocks around it all
   * carry them, so they clear this threshold easily. Counted on an ordinary
   * traffic mix in round 13, the set held 374 content tags out of 400 after
   * twelve passes and 393 out of 400 by the end of the session.
   *
   * What that costs is bounded, and the bound is the thing to understand rather
   * than the sample. A speculatively fetched count answers for its tag for
   * ::$speculativeTtl seconds, so an invalidation by another process inside
   * that window is not seen - a window the stock backend does not have. It is
   * not theoretical: two processes and one entry reproduce it deterministically
   * inside the window, and reliably miss it outside. What a sample of 1,480 web
   * requests after a save showed was that none of them landed inside it, which
   * says the window is short against a web request and says nothing about a
   * cron run, a queue worker or a migration, where the gap between the
   * speculative read and its use is exactly as long as the TTL allows.
   *
   * Sites that will not accept the window can set
   * $settings['redis_rtt_tag_warmset_ttl'] to 0. That turns the speculation off
   * and, with it, the part of the batching this class is named for: a
   * speculatively read count is never used, so every tag costs its own round
   * trip again, the same number the stock backend spends. What survives is the
   * grouping of tags that already arrived in one call. Any positive value,
   * 0.001 included, keeps the rest.
   */
  protected int $minHits;

  public function __construct(ClientFactory $factory, ?ShortcutStoreInterface $store = NULL) {
    parent::__construct($factory);
    $this->store = $store ?? new ApcuShortcutStore('cachetags');
    $this->limit = (int) Settings::get('redis_rtt_tag_warmset_limit', 400);
    $this->minHits = (int) Settings::get('redis_rtt_tag_warmset_min_hits', 3);
    $this->speculativeTtl = (float) Settings::get('redis_rtt_tag_warmset_ttl', 1.0);
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
      $this->pendingPreload,
    );
    if ($preloadable_tags) {
      $this->pendingPreload = array_merge($this->pendingPreload, $preloadable_tags);
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

    // A speculatively fetched count answers for its tag only while it is still
    // young. Promoting one straight into the static cache, as this class used
    // to, made a count nobody had asked for authoritative until the process
    // ended: fine in a web request that lasts milliseconds, wrong in a cron
    // run, a queue worker or a migration, where an editor's save elsewhere
    // would go unseen for the rest of the run.
    $fresh = [];
    if ($this->speculative) {
      $cutoff = microtime(TRUE) - $this->speculativeTtl;
      foreach ($requested as $tag) {
        if (isset($this->speculative[$tag])) {
          if ($this->speculative[$tag]['at'] >= $cutoff) {
            $fresh[$tag] = $this->speculative[$tag]['count'];
            // Recorded so ::calculateChecksum() can keep it out of the static
            // tag cache once this calculation is done.
            $this->answeredSpeculatively[] = $tag;
          }
          else {
            // Discarded only once expired. Dropping it on first use instead
            // would make ::$speculativeTtl bound nothing: the second lookup of
            // the same tag would go to Redis whatever the window said.
            unset($this->speculative[$tag]);
          }
        }
      }
    }

    $outstanding = array_values(array_diff($requested, array_keys($fresh)));
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
    $extra = array_unique(array_diff($this->pendingPreload, $requested, $known, $this->delayedTags));
    $this->pendingPreload = [];

    // The first lookup of the request carries the whole learned set along.
    // Later lookups do not, because by then most of it is in the static cache
    // and re-fetching would be waste.
    if (!$this->warmSetUsed) {
      $this->warmSetUsed = TRUE;
      if ($warm = $this->warmSet()) {
        $extra = array_merge($extra, array_diff($warm, $requested, $extra, $known, $this->delayedTags));
      }
    }

    // Everything the caller asked for was still fresh from an earlier
    // speculative read, so there is nothing to go to Redis for. Speculating
    // further would mean a round trip this request had otherwise avoided.
    if (!$outstanding) {
      return $fresh;
    }

    if (!$extra) {
      return $fresh + parent::getTagInvalidationCounts($outstanding);
    }

    $all = array_merge($outstanding, array_values($extra));
    $values = $this->client->mget(array_map([$this, 'getTagKey'], $all));
    if (!$values) {
      return [];
    }

    $counts = array_map('intval', array_combine($all, $values));

    // What was only fetched speculatively is held here, with the moment it was
    // read, rather than in the static cache: it becomes authoritative when a
    // caller actually asks for it, and only if it is still young enough.
    $now = microtime(TRUE);
    $requested_keys = array_flip($outstanding);
    foreach (array_diff_key($counts, $requested_keys) as $tag => $count) {
      $this->speculative[$tag] = ['count' => $count, 'at' => $now];
    }

    return $fresh + array_intersect_key($counts, $requested_keys);
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
   *
   * A tag this process invalidates makes every speculative count for it wrong
   * at once, and the freshness window is no protection: the count was read
   * before the increment, so it can still be young and still be stale. Worse
   * than serving one stale entry, promoting it would stamp every cache entry
   * written afterwards with the pre-invalidation checksum, and since the
   * counters only ever rise, those entries become a permanent miss for every
   * other process on the site.
   *
   * Core's trait drops the tag from its own static cache here; this drops it
   * from the speculative map for the same reason.
   *
   * @param string[] $tags
   *   The tags being invalidated.
   *
   * @return void
   *   Typed in the docblock rather than the signature: the trait this overrides
   *   declares no return type, and adding one here would fix a contract the
   *   redis module may not want fixed.
   */
  public function invalidateTags(array $tags) {
    foreach ($tags as $tag) {
      unset($this->speculative[$tag]);
    }
    parent::invalidateTags($tags);
  }

  /**
   * {@inheritdoc}
   *
   * Keeps a speculative count out of core's static tag cache, so it cannot
   * outlive the window it was granted.
   *
   * ::$speculativeTtl does not do this on its own. It bounds when a count read
   * ahead of time may be *used*; it does not bound how long the consequence
   * lasts, because core's CacheTagsChecksumTrait::calculateChecksum() folds
   * whatever ::getTagInvalidationCounts() returns into $this->tagCache and
   * nothing empties that until ::reset() or the end of the process. Without
   * this override, a count that was a millisecond too young when it was
   * consulted stays authoritative for the rest of the run - milliseconds in a
   * web request, the whole job in a cron run or a queue worker.
   *
   * What it does not close is the window itself: inside it the count served is
   * the one read before another process invalidated the tag, so a calculation
   * can validate an entry that is already stale, and an entry written then is
   * stamped with a superseded count - a permanent miss rather than wrong
   * content, since the counters only rise. The stock provider does neither,
   * because it never holds a count for a tag nobody asked for.
   *
   * $settings['redis_rtt_tag_warmset_ttl'] = 0 removes the exposure entirely,
   * at the cost of re-reading every speculative count. README.md has what that
   * costs in round trips.
   *
   * @param string[] $tags
   *   The tags to checksum.
   *
   * @return int
   *   The checksum.
   */
  protected function calculateChecksum(array $tags) {
    $this->answeredSpeculatively = [];
    // Core 11's own preload is switched off here rather than left to chance.
    // It is guarded by `if ($this->preloadTags)`, and anything it drained would
    // reach ::getTagInvalidationCounts() indistinguishable from a tag the
    // caller asked for - which is to say, straight into the static tag cache,
    // past the window. Nothing fills this property on any version; emptying it
    // is what makes that true rather than assumed.
    // @see ::$pendingPreload
    $this->preloadTags = [];
    $checksum = parent::calculateChecksum($tags);

    // Taken into a local and cleared in one step: the property is filled from
    // inside the parent call, by ::getTagInvalidationCounts(), and a checksum
    // calculation can nest when a cache read happens during one.
    $answered = $this->answeredSpeculatively;
    $this->answeredSpeculatively = [];
    foreach ($answered as $tag) {
      unset($this->tagCache[$tag]);
    }

    return $checksum;
  }

  /**
   * {@inheritdoc}
   */
  public function reset(): void {
    parent::reset();
    $this->preloadTags = [];
    $this->pendingPreload = [];
    $this->speculative = [];
    $this->answeredSpeculatively = [];
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

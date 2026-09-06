# Redis RTT optimizer

Cuts the number of Redis round trips a Drupal request makes, for deployments
where the cache is a network hop away: AWS ElastiCache across availability
zones, a managed Redis outside the cluster, or any topology where a cache read
costs closer to a millisecond than to nothing.

On a single host a `GET` to Redis costs about 0.05 ms and nobody notices how
many of them Drupal makes. Across an availability zone it costs about 1 ms, and
an authenticated page request makes hundreds of them, strictly one after
another: each answer decides what to ask next.

This module makes the same work wait for the network far less often. Reads that
Drupal issues one at a time are gathered into single pipelines; the writes of
four bins travel in batches instead of one round trip each.

What that changes is the waiting, not the work. Across the seven scenarios
measured below the command count moves by between 0.5% and 23%, while the number
of network waits halves. The clearest of them is a warm edit form: 0.5% fewer
commands, 47% fewer waits. On a heavy authenticated page built from cold, 815
waits become 436.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/redis_rtt).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/redis_rtt).


## Table of contents

- Requirements
- Recommended modules
- Installation
- Configuration
- How it works
  - Batched writes
- Measured results
- Troubleshooting
- FAQ
- Maintainers


## Requirements

This module requires the following outside of Drupal core:

- [Redis](https://www.drupal.org/project/redis) 2.x, configured with the
  PhpRedis client.
- The [phpredis](https://github.com/phpredis/phpredis) PHP extension, 5.3 or
  newer.

It is strongly recommended to also have the APCu PHP extension enabled. Without
it the render cache redirect shortcut and the cache tag warm set still behave
correctly, but nothing they learn survives the request, so they save nothing.
The status report says so if APCu is missing.

Size `apc.shm_size` for the site. What this module learns is one small entry per
cacheable element per view mode, and it shares the segment with Drupal's own
class loader map. APCu answers a full segment with a complete expunge rather
than by evicting the least useful key, so a segment too small for the site costs
every worker its class map, over and over. The default 32M holds on the order of
78,000 of these entries; a large site wants more. The module stops writing to
APCu for the rest of the request as soon as a store is refused, so it yields the
space rather than competing for it.


## Recommended modules

None. This module changes how Drupal talks to Redis; it does not need anything
else to do it.


## Installation

Install as you would normally install a contributed Drupal module. See
[Installing Modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for further information.

Enabling the module on its own changes nothing. Every optimisation is switched
on from `settings.php`, because the cache backend has to be selectable before
the service container exists. See Configuration. That includes the connection:
this module's client factory is registered below the redis module's own, so an
install that names no `interface` keeps the client, the timeouts and the
connection pool it had.


## Configuration

All configuration lives in `settings.php`; there is no administration UI and no
configuration entity. After changing anything here, rebuild caches.

The status report at Administration > Reports > Status report shows which parts
are active, so a half-finished configuration is visible rather than silent.

### Minimum configuration

```php
// Adjust the path if the module is not in modules/contrib.
$class_loader->addPsr4(
  'Drupal\\redis_rtt\\',
  __DIR__ . '/../../modules/contrib/redis_rtt/src',
);

// The redis module's own services, which everything below depends on. Without
// this line a site that has not enabled the redis module yet - an initial
// install above all - dies before the first install task with
// "The service ... has a dependency on a non-existent service redis.factory".
$settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';

$settings['redis.connection']['interface'] = 'FastPhpRedis';
$settings['redis.connection']['persistent'] = TRUE;
$settings['cache']['default'] = 'cache.backend.redis_rtt';
$settings['container_yamls'][] = 'modules/contrib/redis_rtt/redis_rtt.services.yml';
$settings['container_yamls'][] = 'modules/contrib/redis_rtt/redis_rtt.services.example.yml';
```

The `addPsr4()` call is needed because the classes named below are loaded
before Drupal registers module namespaces. `$class_loader` is in scope inside
`settings.php`.

`redis.services.yml` has to come first, and it is the line most easily missed.
Every service this module defines takes `@redis.factory` as an argument, and
that service is defined by the redis module - so it has to be in the container
before anything here is read, whether or not the redis module has been enabled
yet. The redis module's own `settings.redis.example.php` adds the same line for
the same reason ("Allow the services to work before the Redis module itself is
enabled"). If you already configure Redis from that file, put the block below
inside its `if (!InstallerKernel::installationAttempted() ...)` guard and the
line is already there.

Both of this module's services files are listed because `settings.php` is read
whether or not the module is installed. The overrides in the example file refer
to services this module defines, so without its own services file alongside
them, uninstalling the module fails with a `ServiceNotFoundException`.

### Service overrides

`redis_rtt.services.example.yml` contains the individual service overrides:
the batched cache tag checksums, the single-round-trip locks and the render
cache redirect shortcut. Each is independent and can be left commented out, so
the module can be rolled out in stages.

### Bootstrap container

If the site reads its compiled service container from Redis - which it should,
in this kind of topology - the bootstrap container's client factory should be
this module's, so that connection is established with timeouts configured too.

`bootstrap_container_definition` **replaces** core's default definition rather
than merging into it, so it has to be given in full. Assigning only the one key
leaves a bootstrap container with nothing but `redis.factory` in it, and every
request returns a 500 that neither the UI nor drush can undo - only editing
`settings.php` recovers the site.

This is the redis module's own block with one class swapped, and it belongs
*after* the `$settings['redis.connection']` lines above:

```php
$settings['bootstrap_container_definition'] = [
  'parameters' => [],
  'services' => [
    'redis.factory' => [
      // The only line that differs from the redis module's own example.
      'class' => 'Drupal\redis_rtt\ClientFactory',
    ],
    'cache.backend.redis' => [
      'class' => 'Drupal\redis\Cache\CacheBackendFactory',
      'arguments' => ['@redis.factory', '@cache_tags_provider.container', '@serialization.phpserialize'],
    ],
    'cache.container' => [
      'class' => '\Drupal\redis\Cache\PhpRedis',
      'factory' => ['@cache.backend.redis', 'get'],
      'arguments' => ['container'],
    ],
    'cache_tags_provider.container' => [
      'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
      'arguments' => ['@redis.factory'],
    ],
    'serialization.phpserialize' => [
      'class' => 'Drupal\Component\Serialization\PhpSerialize',
    ],
  ],
];
```

### Available settings

| Setting | Default | Effect |
|---|---|---|
| `redis_rtt_redirect_shortcut` | `TRUE` | Skip the render cache redirect hop on a hit. |
| `redis_rtt_redirect_shortcut_ttl` | `86400` | Lifetime of a learned mapping, in seconds. |
| `redis_rtt_chain_memo_limit` | `1000` | Maximum render cache chains memoised in one request. |
| `redis_rtt_tag_warmset_limit` | `400` | Maximum cache tags preloaded in one `MGET`. |
| `redis_rtt_tag_warmset_min_hits` | `3` | Requests a tag must appear in before it is preloaded. |
| `redis_rtt_tag_warmset_ttl` | `1.0` | Seconds a preloaded checksum may answer for its tag. |
| `redis_rtt_batch_writes` | `TRUE` | Send the writes of tag-invalidated bins in batches. |
| `redis_rtt_max_batched_writes` | `100` | Writes that force a batch out early. |
| `redis_rtt_batched_bins` | see below | The only bins whose writes are batched. |
| `redis_rtt_log_errors` | `FALSE` | Warn when a batch fails to reach Redis. |
| `redis_rtt_report` | `FALSE` | Emit the `X-Redis-RTT` measurement header. |
| `redis_rtt_report_top_commands` | `FALSE` | Add `X-Redis-RTT-Commands` with the per-command breakdown. |

`redis_rtt_batched_bins` defaults to `['render', 'data', 'menu',
'dynamic_page_cache']`. It is a list of bins that have been checked against the
three conditions in **Batched writes** below, not a list of bins that have been
ruled out: every other bin, including any a contributed module declares, writes
immediately. Remove a bin from the list if a module on your site deletes its
keys one at a time. Setting `redis_rtt_batch_writes` to `FALSE` restores the
stock write path exactly, and is the first thing to try if a cache entry ever
looks wrong.

A Redis write that fails - a failover, a replica that has gone read-only, a full
instance - raises the same `RedisException` from the same place as the stock
backend, and the request fails with it. There is no setting to change that,
because there is no difference from stock to change.

That includes a batch that fails while the request is still running - when 100
writes have accumulated and the batch goes out mid-request. A full instance under
a noeviction policy, or a replica promoted to read-only, answers reads and
refuses writes; stock returns a 500 for that, and so does this.

The one place a failure is reported rather than raised is the batch sent at the
end of the request. By then the response has gone out - in PHP-FPM the client
already has every byte of it - so there is no request left to fail, and raising
would only put a fatal in the log after the fact. Set `redis_rtt_log_errors` to
see those.

Either way the entries in a failed batch are dropped rather than retried. They
are cache entries, so the cost is that something recomputes them; resending them
later would risk writing back an entry that a delete has since made wrong, which
is the failure this whole design exists to avoid.

The connection accepts these on top of the redis module's own: `tls`, `timeout`,
`read_timeout`, `retry_interval`, `persistent_id`, `user`, `verify_peer`.

`read_timeout` defaults to 1 second where stock phpredis waits forever, which is
the point: an unbounded read turns a failover into an outage. But it is a limit
on *every* reply, including ones you are deliberately waiting for.
`RedisQueue::claimItem()` blocks on `brpoplpush` and does not catch the
exception, so a site using the redis module's queue backend must raise
`read_timeout` above that queue's own blocking timeout. That timeout is
`$settings['redis_queue_<name>']['reserve_timeout']`, set per queue and `NULL`
by default - and with `NULL` the queue uses a non-blocking `rpoplpush`, so a
site that has never set it is not exposed at all. Do not read the `30` in
`claimItem($lease_time = 30)` as the figure to beat: that is how long a claimed
item stays leased, not how long the call blocks. Selecting `FastPhpRedis` is
therefore an explicit choice: installing this module does not make it for you.

`redis_rtt_report` only emits the header; the `redis-trips`, `redis-cmds` and
`redis-ms` fields are filled in by the counting client, which is a separate
switch under a different prefix: `$settings['redis.connection']['count_commands']`.
They are separate because they cost different things, but neither is free.
`redis_rtt_report` calls `Database::startLog()`, which makes core run a full
`debug_backtrace()` for every SQL statement and hold the query, its arguments and
its caller until the header is built - measured at roughly 2.4x the time over
2000 queries, and about 400 bytes retained per query. The counting client is
more expensive still. Both belong on a canary instance, not fleet-wide.

### Rolling out

Deploy in stages and measure between them. Set `redis_rtt_report` and
`$settings['redis.connection']['count_commands']` on one canary instance and
compare the `X-Redis-RTT` header on your heaviest authenticated routes against
an instance without the module.

1. Connection settings only: `FastPhpRedis`, `persistent`, timeouts.
1. Cache tag checksums and locks.
1. The cache backend, with `redis_rtt_batch_writes = FALSE`.
1. The render cache redirect shortcut.
1. Batched writes, by removing that line again.

Each stage is reverted by removing or restoring one line. Cache entries have the same format
as the stock backend's, so nothing persists in an incompatible state.


## How it works

Seven independent changes, all aimed at the same thing:

- **One round trip per bin read.** The stock backend spends an extra `GET` per
  bin per request fetching the "last delete all" marker the first time an entry
  of that bin is expanded. It rides in the same pipeline as the read that needed
  it instead.
- **No redirect hop on render cache hits.** `VariationCache` reads a cache ID,
  gets a `CacheRedirect` naming the real cache contexts, then reads again, and
  each hop waits for the one before it. That chain is structural, so its shape
  is memoised in APCu and the whole chain is then fetched in a single `MGET`
  instead of one sequential read per hop. The memoised shape is verified against
  what came back before its answer is used - every hop has to still be the
  redirect that was learned - so a stale mapping degrades to a miss, never to
  wrong data.
- **Batched cache tag checksums.** The redis backend issues a plain `GET` per
  single-tag checksum, and a page request makes about thirty of them. The set of
  tags a request touches is nearly constant, so it is learned and fetched in one
  `MGET`. Checksums are still read fresh every request; only the batching
  changes.
- **One round trip per lock.** `release()` and lock renewal are
  compare-and-swap, done upstream with `WATCH`/`GET`/`MULTI`/`EXEC`. A Lua
  script does the same atomically in one round trip, and without leaving a
  dangling `WATCH` on a persistent connection if the process dies.
- **One round trip per invalidation batch.** `invalidateMultiple()` costs a
  sequential `HGET` plus `HSET` per cache ID upstream.
- **Batched writes, in the bins where that is safe.** Building a page cold
  writes hundreds of render cache entries one round trip at a time. They travel
  a hundred at a time instead. This is the one change that does not preserve
  *when* data lands, so it is the one with a section of its own below.
- **Connection hygiene.** Connect timeout, read timeout, retry interval, TCP
  keepalive and TLS. The PHP default read timeout is *unlimited*, so a
  connection dropped by a failover blocks the worker until the FPM request
  timeout - which is how a brief failover becomes an outage.

Nothing is cached across requests except facts that are structural and
self-verifying. Apart from the batched bins named below, every write, delete and
invalidation reaches Redis exactly when the stock backend sends it.


### Batched writes

Every other change in this module is free: the same data reaches Redis at the
same moment, in fewer waits. This one is not, and is worth understanding before
turning it on in anger.

A batched write is held in memory for the rest of the request. During that
window another process can delete the key, and the flush then recreates it -
Redis cannot tell "deleted" from "never existed". An earlier version of this
module did that for *every* bin, and in `cache.entity` it made a saved node
revert; the next save from the edit form then wrote the stale values back into
the database. That version was removed rather than defaulted off.

What is batched now is only the bins where a revived entry is harmless, which
takes all three of:

1. **Nothing deletes their keys one at a time.** Drupal 10.6 core has no caller
   of `VariationCache::delete()`, and `RenderCache` exposes no delete method at
   all. `MONITOR` over a node save, an edit through the form, three tag
   invalidations and a full cron recorded zero `DEL`/`UNLINK`/`HDEL` against the
   render bin, out of 15,806 commands.
2. **They are invalidated through cache tags.** A revived entry carries the tag
   counter from before the invalidation, so it is discarded on read. Verified
   end to end: a node's four render entries were dumped, the node was edited,
   the entries were restored byte for byte, and the page kept serving the new
   title.
3. **They are not chained-fast bins**, so there is no "last write" marker that
   has to reach Redis behind the data it announces.

`cache.entity`, `cache.default` and the chained-fast bins each fail one of
those and write immediately. They lose almost nothing by it: `cache.entity`
already writes 3,109 entries in 91 round trips, because Drupal saves entities in
bulk. The bin with the most to gain is also the safest one, which is what makes
this worth doing at all.

So does every bin not on the list. That direction matters: an exclusion list
would batch `cache.page`, `cache.toolbar` and whatever bin a contributed module
declares tomorrow, none of which anyone has checked. Handing untested bins the
benefit of the doubt is the shape of reasoning that produced the data loss, so
the setting names what may be batched rather than what may not.

Three further rules, each of them the memory of a bug:

- A delete drops whatever this request had queued for that key, before the
  delete goes out - inside a database transaction too, where the stock backend
  defers the delete itself to the commit.
- A read of a queued key is served from the queue, so a request never misses on
  something it has just written.
- Each batched write carries the creation stamp it had when `::set()` was
  called, and is applied only if Redis does not already hold something newer.

**The residual risk, stated plainly:** a contributed module that deletes a
render key by hand is not covered, and no measurement can rule that out - only
bound it. A site doing that should take the bin out of `redis_rtt_batched_bins`,
or set `redis_rtt_batch_writes` to `FALSE`.

### Does your site do this?

The batching is safe for the four bins above because nothing deletes or
invalidates their keys one at a time. Core does not: `MONITOR` over four
independent sweeps - 131,000 commands of node, term, user, alias, menu and
config saves, tag invalidation, cron, config import and export, and installing
and uninstalling modules - recorded not one per-key delete against a batched
bin. The only caller in core is the Views options UI
(`GroupwiseMax::submitOptionsForm()`).

A contributed module can still do it, and the module has no way to notice. If
any code on your site does one of these against `render`, `data`, `menu` or
`dynamic_page_cache`, that bin must come off the list:

```php
\Drupal::cache('render')->delete($cid);
\Drupal::cache('data')->deleteMultiple($cids);
\Drupal::cache('menu')->invalidate($cid);
```

Deleting the whole bin, invalidating by cache tag, and writing are all fine -
those are handled. It is the per-key `delete()` and `invalidate()` from *another*
request that can be undone, and only during the window in which the write is
still queued.

To check a site, the cheapest test is to grep for it:

```bash
grep -rn "cache('\(render\|data\|menu\|dynamic_page_cache\)')" web/modules/contrib \
  | grep -E "->(delete|deleteMultiple|invalidate|invalidateMultiple)\("
```

Then take any bin that turns up out of the list:

```php
$settings['redis_rtt_batched_bins'] = ['render', 'menu', 'dynamic_page_cache'];
```


## Measured results

Drupal 10.6 on PHP 8.3 with OPcache and APCu, one node type with 51 fields, a
3,000-term vocabulary, and every node rendering 150 referenced nodes and 400
referenced terms as full entities - so a cold page is a few thousand render
cache operations rather than a few dozen. 1 ms of latency injected per round
trip. Traffic is authenticated: a reverse proxy in front of Drupal means the
anonymous requests that would be cheapest never reach PHP at all.

Three configurations, differing only in `settings.php`: the stock `redis`
backend, this module with `redis_rtt_batch_writes` off, and this module as it
ships.

### How these were counted

A round trip is a *wait*: one per command sent on its own, one per pipeline.
That is what this module exists to reduce and what a network hop actually
charges for.

Counting packets on the wire does not measure it, and gets it wrong in the
direction that flatters the stock backend: a pipeline of 3,000 commands is about
125 KB, which the kernel splits into dozens of segments sent back to back
without waiting for anything. One wait, dozens of packets. The figures here come
from the module's own counter (`redis_rtt_report`), which counts one wait per
un-pipelined command and one per `exec()`.

Because that counter is not free, round trips and wall time were measured in
separate runs - never the same one. Each figure is the median of three runs;
the round trip counts were identical across all three.

### Round trips

| scenario, authenticated | stock | module, no batching | module |
|---|---|---|---|
| view a node, first request after a flush | 818 | 694 | **440** |
| view a node, cold | 815 | 690 | **436** |
| content listing, cold | 515 | 447 | **297** |
| edit form, cold | 322 | 304 | **255** |
| view a node, warm | 24 | 16 | **14** |
| edit form, warm | 61 | 32 | **32** |
| content listing, warm | 166 | 86 | **86** |

Commands, over the same seven scenarios, move very little: 10,437 to 10,065 on
the cold node view, 3,166 to 3,149 on the warm edit form, 221 to 202 on the warm
listing. Always slightly fewer, never more - most of what this module does
*replaces* commands rather than adding them, an `EVAL` standing in for
`HMSET` + `EXPIRE`, an `MGET` for thirty `GET`s. That is the point of the two
columns being different numbers: the work is the same, the waiting is not.

Batching accounts for the whole difference between the last two columns, and
only on cold pages: a warm page writes nothing, so there is nothing to batch.
What it does on a cold page is visible directly - of about 3,400 cache entries
written, 250 are in batched bins, and those 250 travel in 3 round trips instead
of 250. The other 3,100 are `cache.entity`, which Drupal already writes in bulk:
3,109 entries in 91 round trips.

### Wall time

Same site, same latency, instrumentation off:

| scenario, authenticated | stock | module, no batching | module |
|---|---|---|---|
| content listing, cold | 1319 ms | 1164 ms | **922 ms** |
| view a node, cold | 6385 ms | 6300 ms | **5996 ms** |
| edit form, cold | 2377 ms | 2323 ms | **2268 ms** |
| warm pages | — | — | no measurable change |

The saving in time tracks the saving in waits, which is the point: 254 fewer
waits at 1 ms each is about 300 ms off the cold node view.

It is also worth reading the first row against the second. The listing gains
21%; the node view gains 5% for a larger absolute saving, because that page
spends six seconds and most of them are PHP rendering 550 entities. This module
can only give back time that was spent waiting for Redis. Where that is not
where the time goes, it has little to offer, and no amount of round trip
reduction changes that.

**With no injected latency at all, all three configurations are within noise of
each other.** The saving *is* the cost of the latency and disappears with it. If
the cache is on localhost, this module is not for you.

## Troubleshooting

**The status report says parts are inactive.** The module needs
`settings.php` configuration to do anything; see Configuration. The status
report names exactly which pieces are not wired up.

**Fatal error: class `Drupal\redis_rtt\ClientFactory` not found during
bootstrap.** The `$class_loader->addPsr4()` call is missing from
`settings.php`, or its path does not match where the module actually lives.

**`Invalid interface FastPhpRedis`.** The bootstrap container is using the redis
module's own `ClientFactory`, which does not know about this module's client.
Point it at `Drupal\redis_rtt\ClientFactory`; see Configuration.

**Nothing seems faster.** Check `X-Redis-RTT` with `redis_rtt_report` enabled.
If round trips dropped but wall time did not, the network is not your
bottleneck and this module has nothing to offer you.

**A cache entry looks stale or comes back after being deleted.** Set
`redis_rtt_batch_writes = FALSE` and rebuild caches. That restores the stock
write path exactly. If the symptom goes away, the bin involved is one the
batching should not have taken: name the remaining bins in
`redis_rtt_batched_bins` and please open an issue saying which one it was.

**`batches` is high relative to `batched-writes` in the header.** The limit is
set far below 100. It costs round trips rather than correctness. Note that
`batches=1` for a handful of writes is the normal reading of a warm page, not a
symptom.


## FAQ

**Q: Does this module change when my cache writes reach Redis?**

**A:** For four bins, yes; for everything else, no.

Writes to `cache.render`, `cache.data`, `cache.menu` and
`cache.dynamic_page_cache` are held in memory and sent in batches - when 100
have accumulated, and again at the end of the request. Everything else, and
every `delete()` and invalidation, goes to Redis at exactly the point the stock
backend sends it.

The consequence to understand is that an entry in a batched bin, written on one
web node, is not readable from another until that batch goes out. Within the
request that wrote it, a read of a queued key is served from the queue, so a
request never misses on something it has just written. See **Batched writes**
for which bins those are, why they were chosen, and what the residual risk is.
`redis_rtt_batch_writes = FALSE` restores the stock behaviour for all of them.

**Q: Can the render cache shortcut serve the wrong variation?**

**A:** No, but only because it verifies the whole chain rather than its
destination. Landing on data is not evidence that the data is the right data:
core rewrites a chain's redirects in place when an element's cache contexts
change, and it never deletes the entry that hung off the old path, so an address
the chain no longer leads to can stay populated with an entry cached for a
different set of contexts. So the memoised mapping records the contexts of every
redirect in the chain, and the shortcut is accepted only when each of them is
still exactly what was learned and the entry at the end is real data. Anything
else falls back to the full chain walk. The verification is free in round trips
- the whole chain arrives in one `MGET` - and so is a wrong guess, because the
fallback reuses the replies already in hand.

**Q: Does this remove the per-request `AUTH`?**

**A:** No, and neither does anything else available from PHP. A persistent
socket survives between requests but PHP's static state does not, so the client
is rebuilt and `pconnect()` called again on every request, and phpredis
reauthenticates even when it reuses the socket. Passing credentials in the
connect context does not change this. Measured, not assumed.

**Q: Does it help anonymous traffic?**

**A:** Only the requests that reach PHP, which behind a reverse proxy is few of
them. A request served by Varnish never gets here, and a request served by
Drupal's own page cache makes almost no Redis traffic to save. The figures under
Measured results are all authenticated for that reason: it is where the round
trips are.


## Maintainers

- thebrokenbrain - [thebrokenbrain](https://www.drupal.org/u/thebrokenbrain)

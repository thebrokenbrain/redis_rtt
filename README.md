# Redis RTT optimizer

Cuts the number of Redis round trips a Drupal request makes, for deployments
where the cache is a network hop away: AWS ElastiCache across availability
zones, a managed Redis outside the cluster, or any topology where a cache read
costs closer to a millisecond than to nothing.

On a single host a `GET` to Redis costs about 0.05 ms and nobody notices how
many of them Drupal makes. Across an availability zone it costs about 1 ms, and
an authenticated page request makes hundreds of them, strictly one after
another: each answer decides what to ask next.

This module makes the same work wait for the network far less often. What
changes is the waiting, not the work: reads Drupal issues one at a time are
gathered into single pipelines, compare-and-act protocols are moved into Lua,
and a chain that had to be walked one hop at a time is fetched in one go once
its shape is known. The clearest scenario is a warm content listing: 166 waits
become 85, and 359 ms become 230. On a heavy authenticated page built from
cold, 817 become 690.

Writes are not deferred. They go to Redis at the point the stock backend sends
them, which is a deliberate reversal: an earlier version of this module held
them and sent them in batches, and that was worth a further 245 waits on a cold
page and nothing at all on a warm one. It was also the only part of the module
whose correctness had to be argued rather than read. See **Writes** below.

For a full description of the module, visit the
[project page](https://www.drupal.org/project/redis_rtt).

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/redis_rtt).


## Table of contents

- Requirements
- Recommended modules
- Installation
- Configuration
- Recovery
- How it works
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
// Only needed if the module was NOT installed with Composer - see below.
// Adjust the path if it is not in modules/contrib.
$class_loader->addPsr4(
  'Drupal\\redis_rtt\\',
  __DIR__ . '/../../modules/contrib/redis_rtt/src',
);

// The redis module's own services, which everything below depends on. Without
// this line a site that has not enabled the redis module yet - an initial
// install above all - dies before the first install task with
// "The service ... has a dependency on a non-existent service redis.factory".
$settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';

// Where Redis is. CHANGE THIS. It defaults to 127.0.0.1, which is almost never
// what a site this module is for actually has - the whole premise here is a
// Redis that is a network hop away - and the installer will not tell you: core
// swaps the cache backends for in-memory ones while it runs, so `drush si`
// finishes with "Installation complete" and the first real request is a 500.
$settings['redis.connection']['host'] = 'redis.example.com';
$settings['redis.connection']['port'] = 6379;

$settings['redis.connection']['interface'] = 'PhpRedisRtt';
$settings['redis.connection']['persistent'] = TRUE;
$settings['cache']['default'] = 'cache.backend.redis_rtt';
$settings['container_yamls'][] = 'modules/contrib/redis_rtt/redis_rtt.services.yml';
$settings['container_yamls'][] = 'modules/contrib/redis_rtt/redis_rtt.services.example.yml';
```

The `addPsr4()` call is only for installations that did not come through
Composer. Some of the classes named below are loaded before Drupal registers
module namespaces, so something has to know where they live; `composer.json`
declares `autoload.psr-4`, which covers it for a Composer install. If the module
was unpacked by hand, keep the call.

What it prevents is worth being exact about, because it is not "the site breaks
without it" in every case. Measured on a clean site, with the module unpacked by
hand:

| `addPsr4()` | module installed | result |
|---|---|---|
| present | yes | 200, every override active |
| present | no | 200, `drush` boots |
| absent | yes | 200, every override active - nothing changes |
| absent | no | **500 on every page**, `drush` will not boot |

With the module installed Drupal registers its namespace itself when it builds
the container, so the line is doing nothing. What it covers is the other case:
the `container_yamls` lines above are read whether or not the module is
installed, so a site that uninstalls it - or that has not installed it yet - is
still wired to services whose classes nobody can find. And with the
`bootstrap_container_definition` block further down in use, the classes it names
are needed before any of that, so the site is a 500 with or without the module
installed. Keeping the line costs nothing and covers all of it. `$class_loader`
is in scope inside `settings.php`.

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
the cache tag checksums in one `MGET`, the single-round-trip locks and the render
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
| `redis_rtt_shortcut_memo_limit` | `1000` | Maximum learned mappings held in one process. Matters most with APCu off, where that memo is the whole store. |
| `redis_rtt_tag_warmset_limit` | `400` | Maximum cache tags carried by the *learned* set. It does not bound the `MGET` as a whole, which also carries one tag per entry the page read. |
| `redis_rtt_tag_warmset_min_hits` | `3` | Requests a tag must appear in before it is preloaded. |
| `redis_rtt_tag_warmset_ttl` | `1.0` | Seconds a preloaded checksum may answer for its tag. Inside that window this module can serve an entry another process just invalidated; `0` removes the window. See Troubleshooting. |
| `redis_rtt_report` | `FALSE` | Emit the `X-Redis-RTT` measurement header. |
| `redis_rtt_report_top_commands` | `FALSE` | Add `X-Redis-RTT-Commands` with the per-command breakdown. |

A Redis write that fails - a failover, a replica that has gone read-only, a full
instance - raises the same `RedisException` from the same place as the stock
backend, and the request fails with it. There is no setting to change that,
because there is no difference from stock to change.

A full instance under a noeviction policy, or a replica promoted to read-only,
answers reads and refuses writes; stock returns a 500 for that, and so does
this.

A pipeline that fails partway is a different matter, because phpredis is then
holding a socket with replies still queued on it and the next command would read
the previous one's reply - on a persistent connection, in the next request. All
three places this module opens a pipeline - `getMultiple()`,
`invalidateMultiple()` and `LuaRedisLock::releaseAll()` - close the connection
on failure for that reason; phpredis reconnects on the next command.

The connection accepts these on top of the redis module's own: `tls`, `timeout`,
`read_timeout`, `retry_interval`, `persistent_id`, `user`, `verify_peer`.

`read_timeout` defaults to 5 seconds, where the stock factory configures none
and leaves phpredis on php.ini's `default_socket_timeout` - 60 seconds out of
the box. That is the point: with Redis unreachable rather than slow, a worker
held for a minute per request empties the pool. It is worth being concrete
about what the trade buys and costs, because it is the one setting here that
changes what a visitor sees.

A stall shorter than the timeout costs nothing either way. A stall longer than
it fails requests fast here and holds a worker there, and which of those is
better depends on whether you would rather shed a request or queue behind a
stalled Redis until PHP gives up.

The default was 1 second until 2026-09-09. Measured with Redis alive but not
answering for four seconds and six PHP-FPM workers, a one-second limit failed
12 of 60 requests where stock served all 60, slowly; at five seconds none of
the 60 failed, and none failed at a two-second stall either. A second turned
out to be aggressive for a cache read across a network hop, since a garbage
collection pause or a failover fits inside it. With Redis unreachable and
packets dropped rather than refused, this module gave up after 2.18 s and stock
after 120.18 s.

**Setting it to 0 or less asks for the stock behaviour**, which is
`default_socket_timeout` and not "no limit": that is what the stock factory
leaves in force, and it is what you get here. **A value that is not a number is
refused** and the default stands, with the status report showing it as a
problem - a decimal comma, an empty string, and a `getenv()` for a variable
that is not set all used to pass through a cast as 0 and silently remove the
bound.

It is a limit on *every* reply, including ones you are deliberately waiting
for.
`RedisQueue::claimItem()` blocks on `brpoplpush` and does not catch the
exception, so a site using the redis module's queue backend must keep that
queue's own blocking timeout below `read_timeout` (5 seconds by default), or
raise `read_timeout` above it. That timeout is
`$settings['redis_queue_<name>']['reserve_timeout']`, set per queue and `NULL`
by default - and with `NULL` the queue uses a non-blocking `rpoplpush`, so a
site that has never set it is not exposed at all. Do not read the `30` in
`claimItem($lease_time = 30)` as the figure to beat: that is how long a claimed
item stays leased, not how long the call blocks. Selecting `PhpRedisRtt` is
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

1. Connection settings only: `PhpRedisRtt`, `persistent`, timeouts.
1. Cache tag checksums and locks.
1. The cache backend.
1. The render cache redirect shortcut.

Each stage is reverted by removing or restoring one line. Cache entries have the same format
as the stock backend's, so nothing persists in an incompatible state.

**Check each stage with an HTTP request, not with `drush`'s exit code.** `drush
cr` prints `[success] Cache rebuild complete` and exits 0 on a site that is
serving 500 to every visitor - it rebuilt what it could reach, which is not the
same as the site being able to boot. One `curl -o /dev/null -w '%{http_code}'`
against a real route, authenticated and anonymous, is what tells you a stage
landed.

## Recovery

Every state below is reachable by getting one line wrong, and every one has a
way out. None of them needs the database.

**The site returns 500 everywhere and `drush` will not boot either.** The
classes named in `settings.php` cannot be found. Either the module is not where
the `addPsr4()` call says it is, or it was unpacked by hand and that call is
missing entirely. Fix the path, or add the call; nothing else is required,
because nothing has been written anywhere yet.

**Uninstalling the module fails, and afterwards the site still answers 200 but
`drush cr` does not work.** `settings.php` lists
`redis_rtt.services.example.yml` without `redis_rtt.services.yml` next to it.
The overrides in the example file point at services the module defines, so the
container can no longer be built - the running site is serving from the copy it
already had, and the next `flushall` or Redis restart turns every page into a
500. Put both lines back, `drush cr`, and uninstall again.

**The site breaks right after upgrading the module.** The compiled service
container is cached, and if `$settings['bootstrap_container_definition']` is in
use it is cached *in Redis* - so the thing that has to be rebuilt is being read
from the place the broken configuration points at. `drush cr` still recovers
this; if it cannot, comment out `bootstrap_container_definition`, clear caches,
and put it back.

**Nothing works and you want out now.** Set
`$settings['cache']['default'] = 'cache.backend.redis'`, comment out the two
`redis_rtt` `container_yamls` lines, and **put the redis module's own
`example.services.yml` back in their place** if it is not already listed:

```php
$settings['cache']['default'] = 'cache.backend.redis';
// $settings['container_yamls'][] = 'modules/contrib/redis_rtt/redis_rtt.services.yml';
// $settings['container_yamls'][] = 'modules/contrib/redis_rtt/redis_rtt.services.example.yml';
$settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';
```

That last line is the one that is easy to miss and the one that decides whether
this is free. `redis_rtt.services.example.yml` is where the cache tag checksum
service is replaced; commenting it out without putting something back does not
return the redis module's checksum provider, it returns **core's, on the
database**. The tag counters stay in Redis, the `cachetags` table is empty, and
every entry carrying a cache tag - which in Drupal is very nearly everything -
reads as invalid. The entries are still in Redis; nobody answers with them.
That is a full cold start, at the exact moment you were trying to avoid one.

With that line in place there is no migration and no cold start: the stock
backend reads the entries this module wrote and vice versa, because the formats
are identical.


## How it works

Six independent changes, all aimed at the same thing:

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
- **Cache tag checksums in one `MGET`.** The redis backend issues a plain `GET` per
  single-tag checksum, and a page request makes about thirty of them. The set of
  tags a request touches is nearly constant, so it is learned and fetched in one
  `MGET`. Checksums are still read fresh every request; only the grouping
  changes.
- **One round trip per lock.** `release()` and lock renewal are
  compare-and-swap, done upstream with `WATCH`/`GET`/`MULTI`/`EXEC` - five
  round trips, because phpredis sends each command inside a MULTI block and
  reads back `+QUEUED`, rather than holding them the way a pipeline does. A Lua
  script does the same atomically in one round trip, and without leaving a
  dangling `WATCH` on a persistent connection if the process dies.
- **One round trip per invalidation.** `invalidateMultiple()` costs a
  sequential `HGET` plus `HSET` per cache ID upstream.
- **Connection hygiene.** Connect timeout, read timeout, retry interval, TCP
  keepalive and TLS. The PHP default read timeout is *unlimited*, so a
  connection dropped by a failover blocks the worker until the FPM request
  timeout - which is how a brief failover becomes an outage.

Nothing is cached across requests except facts that are structural and
self-verifying, and every write, delete and invalidation reaches Redis exactly
when the stock backend sends it.


### Writes

Writes go to Redis at the point the stock backend sends them. There is no queue,
no end-of-request flush and no window in which a write is held.

That is a change from earlier versions, which batched the writes of `render`,
`menu` and `dynamic_page_cache` into one pipeline. It was the single largest
saving the module made - about two thirds of the total on a cold page - and it
was also the only mechanism here that could be wrong: a queued write that
another process deleted in the meantime was recreated by the flush, because
Redis cannot tell "deleted" from "never existed". It was bounded to three bins
by four conditions, it never reproduced outside a deliberately misconfigured
one, and the reproductions that proved so are still in the project's audit
directory.

It was removed anyway, and the reason is the shape of the trade rather than any
failure. Measured, batching bought nothing at all on a warm page - a warm edit
form is 31 waits with or without it, a warm content listing 85 either way,
because a warm page writes almost nothing. Everything it bought was on cold
pages, which are the minority of real traffic. Set against that: it was the one
part of the module that had to be reasoned about before a deployment, the one
with a documented residual risk, and the one whose safety depended on a list of
bins nobody could keep current for a site's contributed modules.

The rest of the module is unaffected. What remains is entirely made of
operations that either group reads that were going to happen anyway, replace a
multi-command protocol with one Lua call, or avoid a lookup by remembering a
structural fact - none of which defers anything.

## Measured results

Drupal 10.6 on PHP 8.3 with OPcache and APCu, one node type with 51 fields, a
3,000-term vocabulary, and every node rendering 150 referenced nodes and 400
referenced terms as full entities - so a cold page is a few thousand render
cache operations rather than a few dozen. 1 ms of latency injected per round
trip. Traffic is authenticated: a reverse proxy in front of Drupal means the
anonymous requests that would be cheapest never reach PHP at all.

Two configurations, differing only in `settings.php`: the stock `redis` backend
and this module as it ships.

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
separate runs - never the same one. Each figure is the median of three runs.

On the cold scenarios the runs returned the same count every time. The warm ones
vary by a trip or two - a warm node view came back as 13 and 15 - because what a
warm page reads depends on what the previous request left in APCu. Those rows
are given as ranges below for that reason.

### Round trips

| scenario, authenticated | stock | module |
|---|---|---|
| view a node, cold | 817 | **690-692** (-15%) |
| content listing, cold | 520 | **447** (-14%) |
| edit form, cold | 325 | **304** (-6%) |
| view a node, warm | 24 | **13-15** (-42%) |
| edit form, warm | 61 | **31** (-49%) |
| content listing, warm | 166 | **85** (-49%) |

The shape of that table is the argument for dropping batched writes: the warm
rows, which are the bulk of real traffic, are where this module does most of its
work, and batching contributed nothing to them.

It is also the bar this module holds itself to: **at least 40% fewer waits on a
warm authenticated page.** The cold rows are reported because they are true, not
because they are the case for installing it - a cold page is mostly writing, and
grouping reads saves waits where there are reads to group.

**"Cold" above means this module's caches are cold, not everything.** The bench
protocol leaves core's chained-fast front for `cache.config` valid, so the
measured request does not re-read configuration. That is one legitimate cold
state and not the only one: the first request a worker serves after a cache
rebuild finds that front stale and reads config back from Redis one key at a
time - measured, 279 extra `HGETALL`s. The same page then costs:

| scenario, authenticated | stock | module |
|---|---|---|
| view a node, everything cold including core's config front | 1,165 | **1,001** |

Both sides pay about 350 waits more, so the saving reads as 14% here against 15%
above while being slightly larger in absolute waits, 164 against 127. That block
is `Drupal\Core\Cache\ChainedFastBackend`, a core backend this module does not
replace: `bootstrap`, `config` and `discovery` keep their APCu front and their
own consistency protocol.

The distinction is worth stating because it is easy to reproduce the wrong one.
Measuring straight after `drush cr` gives a figure in between - 685 waits with
this module on the same page - and none of the three is wrong; they are three
different starting states.

Commands are a different story, and worth stating carefully because the header
does not measure what it looks like it measures.

The `redis-cmds` field counts *calls this module makes to the client*, which is
what its docblock says it counts. It is not what Redis executes: an `EVAL`
counts as one call however much its script does.

Measured at the server with `CONFIG RESETSTAT` + `INFO commandstats`, on the
cold node view, the two configurations are now within a third of a percent of
each other - 11,221 commands for stock against 11,258 for this module. The
module asks Redis for essentially the same work; what changed is how many times
PHP stops to wait for it.

That was not true of the version that batched writes. There, each queued write
travelled as an `EVAL` whose script ran `HGET` + `HMSET` + `EXPIRE`, so the
module executed about 3.3% *more* commands at the server on a cold node view
and 18.7% more on a cold listing. The trade was explicit - more commands for
Redis, far fewer waits for PHP - and dropping the batching removed that side of
it too.

### Wall time

Same site, same latency, instrumentation off. All three columns of a row are
measured in one sitting, which matters more than it sounds: the same code on the
same machine gave 6300 ms one night and 7089 ms the next, so a table assembled
from separate sittings compares the weather rather than the code.

| scenario, authenticated | stock | module |
|---|---|---|
| content listing, warm | 359 ms | **230 ms** (-36%) |
| content listing, cold | 1429 ms | **1213 ms** (-15%) |
| view a node, cold | 7263 ms | **7089 ms** (-2%) |
| edit form, cold | 2999 ms | **2951 ms** (-2%) |
| view a node, warm | — | no measurable change |

The warm content listing is the clearest row and deliberately first: 81 fewer
waits, 129 ms less, medians of three runs alternating which configuration went
first. A trip on this bench costs about 1.8 ms - 1 ms injected on top of a
0.76 ms baseline - so the saving and the waits agree in direction and order of
magnitude, which is as far as that arithmetic should be pushed.

A warm node view really is unchanged: it only spends 24 waits to begin with, so
there is nothing there to give back.

It is also worth reading the cold listing against the cold node view. The
listing gains 15%; the node view gains 2% for a comparable absolute saving, because that page
spends seven seconds and most of them are PHP rendering 550 entities. This module
can only give back time that was spent waiting for Redis. Where that is not where
the time goes, it has little to offer, and no amount of round trip reduction
changes that.

**With no injected latency at all, both configurations are within noise of each
other.** The saving *is* the cost of the latency and disappears with it. If
the cache is on localhost, this module is not for you.

## Troubleshooting

**A queue worker dies with `read error on connection`.** The bounded read
timeout applies to every command on the connection, including the blocking
`brpoplpush` that `Drupal\redis\Queue\RedisQueue::claimItem()` uses when
`$settings['redis_queue_<name>']['reserve_timeout']` is set - per queue, and
named after the queue, not under `redis.connection`. If the queue waits longer
than the read timeout, phpredis raises, and the message says nothing about
which setting caused it. Keep that queue's `reserve_timeout` below
`read_timeout` (5 seconds by default), or leave it unset so the queue polls
with a non-blocking `rpoplpush` instead of blocking.

**The status report says parts are inactive.** The module needs
`settings.php` configuration to do anything; see Configuration. The status
report names exactly which pieces are not wired up.

**Fatal error: class `Drupal\redis_rtt\ClientFactory` not found during
bootstrap.** The `$class_loader->addPsr4()` call is missing from
`settings.php`, or its path does not match where the module actually lives.

**`Invalid interface PhpRedisRtt`.** The bootstrap container is using the redis
module's own `ClientFactory`, which does not know about this module's client.
Point it at `Drupal\redis_rtt\ClientFactory`; see Configuration.

**Nothing seems faster.** Check `X-Redis-RTT` with `redis_rtt_report` enabled.
If round trips dropped but wall time did not, the network is not your
bottleneck and this module has nothing to offer you.

**A cache entry looks stale.** One mechanism in this module can produce that
symptom, and it has its own switch:

1. Set `redis_rtt_tag_warmset_ttl = 0` and rebuild caches. That stops a cache
   tag counter read ahead of time from answering for its tag at all. Within that
   window - one second by default - this module can serve an entry another
   process has just invalidated, and can stamp an entry it writes with a counter
   that is already superseded; the stock backend does neither, because it never
   holds a counter for a tag nobody asked for. The cost of turning it off is
   round trips: measured at 18 of the 30 this module saves on a warm edit form.
If that changes nothing, the cause is not in this module: writes are not
deferred, so nothing here can resurrect an entry another process deleted.


## FAQ

**Q: Does this module change when my cache writes reach Redis?**

**A:** No. Every write, delete and invalidation goes to Redis at exactly the
point the stock backend sends it, on every bin.

Earlier versions did, for three bins, and that is worth knowing if you are
coming from one of them: writes to `cache.render`, `cache.menu` and
`cache.dynamic_page_cache` were held in memory and sent in one pipeline. The
consequence was that an entry written on one web node was not readable from
another until that batch went out. That is gone; see **Writes** for what it
bought and why it was dropped anyway.

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

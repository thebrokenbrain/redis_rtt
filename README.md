# Redis RTT optimizer

Cuts the number of Redis round trips a Drupal request makes, for deployments
where the cache is a network hop away: AWS ElastiCache across availability
zones, a managed Redis outside the cluster, or any topology where a cache read
costs closer to a millisecond than to nothing.

On a single host a `GET` to Redis costs about 0.05 ms and nobody notices how
many of them Drupal makes. Across an availability zone it costs about 0.6 ms,
and an authenticated page request makes over a hundred of them, strictly one
after another. This module batches, memoises and reorders that traffic so the
same work waits for the network less than half as often. It sends slightly
*more* Redis commands than stock Drupal and waits far less.

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
| `redis_rtt_report` | `FALSE` | Emit the `X-Redis-RTT` measurement header. |
| `redis_rtt_report_top_commands` | `FALSE` | Add `X-Redis-RTT-Commands` with the per-command breakdown. |
| `redis_rtt_log_errors` | `FALSE` | Warn through the PHP log when a Redis write fails, instead of swallowing it. Floods the log if Redis is down. |

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
1. The cache backend.
1. The render cache redirect shortcut.

Each stage is reverted by removing one line. Cache entries have the same format
as the stock backend's, so nothing persists in an incompatible state.


## How it works

Five independent changes, all aimed at the same thing:

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
- **Connection hygiene.** Connect timeout, read timeout, retry interval, TCP
  keepalive and TLS. The PHP default read timeout is *unlimited*, so a
  connection dropped by a failover blocks the worker until the FPM request
  timeout - which is how a brief failover becomes an outage.

Nothing is cached across requests except facts that are structural and
self-verifying. Every write, delete and invalidation reaches Redis exactly when
the stock backend sends it: this module changes how many network waits that
costs, never when the data lands.


## Measured results

Drupal 10.6, 400 nodes, 62 users, 26 blocks, 4 views, authenticated traffic with
Dynamic Page Cache missing (the common case once a site has more than a handful
of users), 0.5 ms of injected latency per hop:

| | TTFB p50 | round trips | time in Redis |
|---|---|---|---|
| stock | 213.4 ms | 111.7 | 115.6 ms |
| with this module | 116.1 ms **-46%** | 47.4 **-58%** | 43.0 ms **-63%** |

With Dynamic Page Cache hitting, round trips drop from 26 to 14 and TTFB by
15-19%. With no network latency at all, ±0%: the saving *is* the cost of the
latency, and it disappears with it. That is the honest summary - if your cache
is on localhost, this module is not for you.


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


## FAQ

**Q: Does this module change when my cache writes reach Redis?**

**A:** No. Every `set()`, `delete()` and invalidation goes to Redis at the same
point in the request as with the stock backend, and in the same order. What the
module changes is how many network waits a request spends on *reads*, on cache
tag checksums and on invalidations - not when data lands. An entry written on
one web node is readable from another exactly as soon as it would have been
without this module.

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

**A:** Only the requests that reach PHP. A request served by a reverse proxy
never gets here. For requests that do reach Drupal, the per-request floor drops
from 26 round trips to 14, anonymous or not.


## Maintainers

- thebrokenbrain - [thebrokenbrain](https://www.drupal.org/u/thebrokenbrain)

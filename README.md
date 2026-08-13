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


## Recommended modules

None. This module changes how Drupal talks to Redis; it does not need anything
else to do it.


## Installation

Install as you would normally install a contributed Drupal module. See
[Installing Modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for further information.

Enabling the module on its own changes nothing. Every optimisation is switched
on from `settings.php`, because the cache backend has to be selectable before
the service container exists. See Configuration.


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

$settings['redis.connection']['interface'] = 'FastPhpRedis';
$settings['redis.connection']['persistent'] = TRUE;
$settings['cache']['default'] = 'cache.backend.redis_rtt';
$settings['container_yamls'][] = 'modules/contrib/redis_rtt/redis_rtt.services.example.yml';
```

The `addPsr4()` call is needed because the classes named below are loaded
before Drupal registers module namespaces. `$class_loader` is in scope inside
`settings.php`.

### Service overrides

`redis_rtt.services.example.yml` contains the individual service overrides:
the batched cache tag checksums, the single-round-trip locks and the render
cache redirect shortcut. Each is independent and can be left commented out, so
the module can be rolled out in stages.

### Bootstrap container

If the site reads its compiled service container from Redis - which it should,
in this kind of topology - point the bootstrap container's client factory at
this module so the connection is established with timeouts configured:

```php
$settings['bootstrap_container_definition']['services']['redis.factory']['class']
  = 'Drupal\redis_rtt\ClientFactory';
```

### Available settings

| Setting | Default | Effect |
|---|---|---|
| `redis_rtt_defer_writes` | `TRUE` | Buffer cache writes into one pipeline per request. |
| `redis_rtt_max_pending_writes` | `512` | Force an intermediate flush at this many pending keys. |
| `redis_rtt_unbuffered_bins` | `['container']` | Bins always written synchronously. |
| `redis_rtt_redirect_shortcut` | `TRUE` | Skip the render cache redirect hop on a hit. |
| `redis_rtt_redirect_shortcut_ttl` | `86400` | Lifetime of a learned mapping, in seconds. |
| `redis_rtt_tag_warmset_limit` | `400` | Maximum cache tags preloaded in one `MGET`. |
| `redis_rtt_tag_warmset_min_hits` | `3` | Requests a tag must appear in before it is preloaded. |
| `redis_rtt_report` | `FALSE` | Emit the `X-Redis-RTT` measurement header. |
| `redis_rtt_report_top_commands` | `FALSE` | Add `X-Redis-RTT-Commands` with the per-command breakdown. |
| `redis_rtt_log_errors` | `FALSE` | Warn through the PHP log when a buffered flush fails, instead of swallowing it. Floods the log if Redis is down. |

The connection accepts these on top of the redis module's own: `tls`, `timeout`,
`read_timeout`, `retry_interval`, `persistent_id`, `user`, `verify_peer`.

`redis_rtt_report` only emits the header; the `redis-trips`, `redis-cmds` and
`redis-ms` fields are filled in by the counting client, which is a separate
switch under a different prefix: `$settings['redis.connection']['count_commands']`.
They are separate because the header is cheap and the counter is not.

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

Six independent changes, all aimed at the same thing:

- **Batched writes.** All cache writes of a request, across every bin, go out
  as one pipeline at the end of the request - after `fastcgi_finish_request()`,
  so they leave the critical path entirely. Repeated writes to a key are
  deduplicated, and reads are answered from the buffer.
- **No redirect hop on render cache hits.** `VariationCache` reads a cache ID,
  gets a `CacheRedirect` naming the real cache contexts, then reads again. That
  mapping is structural, so it is memoised in APCu and the second read goes
  straight to the answer. It is verified on use: a stale mapping degrades to a
  miss, never to wrong data.
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
self-verifying. Cache tag invalidations are never deferred: they are the source
of truth for consistency and always go out immediately.


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

**Q: Is it safe to buffer cache writes? What if the process dies?**

**A:** Buffered entries are cache data only, so an unsent write means the value
is recomputed on the next request. Deletes, invalidations and cache tag
invalidations are never buffered. Each entry's tag checksum is computed when
`set()` is called rather than when the write is sent, so an invalidation that
happens in between still wins.

**Q: Can the render cache shortcut serve the wrong variation?**

**A:** No. The shortcut is only accepted when the entry it lands on exists and
is not itself a `CacheRedirect`; anything else falls back to the full chain
walk. Because the fallback reuses the reply the shortcut already fetched, a
wrong guess costs no extra round trip - it just does not save one.

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

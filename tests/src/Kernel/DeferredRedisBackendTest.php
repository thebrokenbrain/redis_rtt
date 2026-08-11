<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Kernel;

use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\Cache;
use Drupal\KernelTests\KernelTestBase;
use Drupal\redis_rtt\Cache\DeferredRedisBackend;
use Drupal\redis_rtt\Cache\PreloadingRedisCacheTagsChecksum;
use Drupal\redis_rtt\Redis\CommandBuffer;
use Drupal\redis\Cache\RedisBackend;
use Drupal\redis\ClientFactory;

/**
 * Checks the deferred backend against a real Redis server.
 *
 * The unit tests prove the buffer's bookkeeping with a stand-in client. This
 * proves the thing that actually matters: that after a given sequence of
 * operations, Redis ends up holding exactly what the stock backend would have
 * left there. Anything else - a lost write, a resurrected delete, a checksum
 * computed at the wrong moment - would show up as a difference here.
 *
 * Requires a reachable Redis; skipped otherwise, so it is safe in CI without
 * one. Point it at a server with:
 *   REDIS_HOST=redis-primary REDIS_PASSWORD=... phpunit ...
 *
 * @group redis_rtt
 * @requires extension redis
 */
class DeferredRedisBackendTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['redis', 'redis_rtt'];

  /**
   * The Redis client factory.
   */
  protected ClientFactory $factory;

  /**
   * A prefix unique to this test run, so parallel runs cannot collide.
   */
  protected string $prefix;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $host = getenv('REDIS_HOST') ?: 'redis-primary';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);
    $password = getenv('REDIS_PASSWORD') ?: NULL;

    $probe = new \Redis();
    try {
      if (!@$probe->connect($host, $port, 0.5)) {
        $this->markTestSkipped("No Redis at $host:$port.");
      }
      if ($password !== NULL) {
        $probe->auth($password);
      }
      $probe->ping();
      $probe->close();
    }
    catch (\Exception $e) {
      $this->markTestSkipped('Redis is not usable: ' . $e->getMessage());
    }

    $settings = ['host' => $host, 'port' => $port];
    if ($password !== NULL) {
      $settings['password'] = $password;
    }
    $this->setSetting('redis.connection', $settings);

    $this->prefix = 'redisrtttest' . bin2hex(random_bytes(6));
    $this->factory = $this->container->get('redis.factory');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->factory)) {
      $client = $this->factory->getClient();
      $keys = iterator_to_array($client->scan($this->prefix . '*'));
      if ($keys) {
        $client->del(...$keys);
      }
    }
    parent::tearDown();
  }

  /**
   * Builds a backend of the given flavour.
   *
   * @param string $bin
   *   The bin name.
   * @param bool $deferred
   *   TRUE for the deferred backend, FALSE for the stock one.
   * @param \Drupal\redis_rtt\Redis\CommandBuffer|null $buffer
   *   (optional) The buffer to share; created if omitted.
   *
   * @return \Drupal\redis\Cache\RedisBackend
   *   The backend, prefixed uniquely to this test.
   */
  protected function backend(string $bin, bool $deferred, ?CommandBuffer &$buffer = NULL): RedisBackend {
    $client = $this->factory->getClient();
    $checksum = new PreloadingRedisCacheTagsChecksum($this->factory);
    $checksum->setPrefix($this->prefix);
    $serializer = new PhpSerialize();

    if ($deferred) {
      $buffer ??= new CommandBuffer($this->factory);
      $backend = new DeferredRedisBackend($bin, $client, $checksum, $serializer, $buffer);
    }
    else {
      $backend = new RedisBackend($bin, $client, $checksum, $serializer);
    }
    $backend->setPrefix($this->prefix . ':' . ($deferred ? 'new' : 'old'));

    return $backend;
  }

  /**
   * Returns everything the given backend has stored, normalised.
   *
   * @param \Drupal\redis\Cache\RedisBackend $backend
   *   The backend whose keyspace to dump.
   *
   * @return array<string, mixed>
   *   Hashes keyed by cache ID, with volatile fields removed.
   */
  protected function dump(RedisBackend $backend): array {
    $client = $this->factory->getClient();
    $dump = [];
    foreach ($client->scan($backend->getKey('*')) as $key) {
      $hash = $client->hgetall($key);
      if (!is_array($hash) || !$hash) {
        continue;
      }
      // 'created' is a timestamp, so it can never match between two runs.
      unset($hash['created']);
      ksort($hash);
      $dump[$hash['cid'] ?? $key] = $hash;
    }
    ksort($dump);
    return $dump;
  }

  /**
   * Both backends must leave Redis in the same state.
   *
   * The sequence deliberately mixes the cases the buffer has to reason about:
   * repeated writes to one key, a delete of something still queued, an
   * invalidation of something still queued, and reads in between.
   */
  public function testTheDeferredBackendIsIndistinguishableFromTheStockOne(): void {
    $exercise = function (RedisBackend $backend): void {
      $backend->set('a', 'first', Cache::PERMANENT, ['node:1']);
      $backend->set('a', 'second', Cache::PERMANENT, ['node:1']);
      $backend->set('b', ['structured' => TRUE], Cache::PERMANENT, ['node:2', 'config:system.site']);
      $backend->set('c', 'doomed');
      $backend->set('d', 'invalidated', Cache::PERMANENT, ['node:3']);

      $cids = ['a', 'b'];
      $backend->getMultiple($cids);

      $backend->delete('c');
      $backend->invalidate('d');
      $backend->set('e', str_repeat('compress me ', 200));
    };

    $old = $this->backend('render', FALSE);
    $exercise($old);

    $buffer = NULL;
    $new = $this->backend('render', TRUE, $buffer);
    $exercise($new);
    $buffer->flush();

    $this->assertSame($this->dump($old), $this->dump($new));
  }

  /**
   * A buffered write is visible to the request that made it.
   */
  public function testReadYourOwnWrites(): void {
    $buffer = NULL;
    $backend = $this->backend('render', TRUE, $buffer);

    $backend->set('pending', ['some' => 'data'], Cache::PERMANENT, ['node:1']);
    $item = $backend->get('pending');

    $this->assertNotFalse($item, 'A queued write must be readable before it is sent.');
    $this->assertSame(['some' => 'data'], $item->data);

    // And still readable once it has actually landed.
    $buffer->flush();
    $fresh = $this->backend('render', TRUE)->get('pending');
    $this->assertSame(['some' => 'data'], $fresh->data);
  }

  /**
   * Cache tag invalidation still invalidates buffered writes.
   *
   * The checksum is computed when ::set() is called rather than when the write
   * is sent, so an invalidation between the two still wins. Getting this wrong
   * would serve stale data indefinitely.
   */
  public function testTagInvalidationBeatsPendingWrite(): void {
    $buffer = NULL;
    $backend = $this->backend('render', TRUE, $buffer);
    $checksum = new PreloadingRedisCacheTagsChecksum($this->factory);
    $checksum->setPrefix($this->prefix);

    $backend->set('tagged', 'data', Cache::PERMANENT, ['node:42']);
    $checksum->invalidateTags(['node:42']);
    $buffer->flush();

    $fresh = $this->backend('render', TRUE)->get('tagged');
    $this->assertFalse($fresh, 'An entry invalidated after being queued must not come back valid.');
  }

  /**
   * Deleting a queued entry must not be undone when the buffer flushes.
   */
  public function testDeleteBeatsPendingWrite(): void {
    $buffer = NULL;
    $backend = $this->backend('render', TRUE, $buffer);

    $backend->set('doomed', 'data');
    $backend->delete('doomed');
    $buffer->flush();

    $this->assertFalse($this->backend('render', TRUE)->get('doomed'));
  }

  /**
   * Invalidating many entries costs one round trip, and actually invalidates.
   */
  public function testBulkInvalidation(): void {
    $buffer = NULL;
    $backend = $this->backend('render', TRUE, $buffer);

    $cids = [];
    foreach (range(1, 10) as $i) {
      $cids[] = "item:$i";
      $backend->set("item:$i", "data $i");
    }
    $buffer->flush();

    $backend->invalidateMultiple($cids);

    $fresh = $this->backend('render', TRUE);
    foreach ($cids as $cid) {
      $this->assertFalse($fresh->get($cid), "$cid must be invalid.");
      $this->assertNotFalse($fresh->get($cid, TRUE), "$cid must still exist.");
    }
  }

}

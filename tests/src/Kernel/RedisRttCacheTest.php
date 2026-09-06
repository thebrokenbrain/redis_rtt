<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\Core\Cache\GenericCacheBackendUnitTestBase;
use Drupal\redis_rtt\Cache\BatchingRedisBackend;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Runs core's cache backend contract against this module's backend.
 *
 * Everything else in tests/src/Unit talks to a stand-in, which is right for
 * asserting on round trips - the whole point of the module is a number a real
 * Redis will not tell you - and wrong for asserting that the thing is a correct
 * cache backend. A stand-in agrees with whatever the code does.
 *
 * That gap was not theoretical. A review found four mutations of the write path
 * that left the unit suite green: emptying the end-of-request send, inverting
 * the invalidation script, and dropping the TTL twice over. Each is caught by a
 * unit test now, but the reason they were all missed at once is that nothing
 * ran the backend against Redis.
 *
 * Skips itself when no Redis is reachable, so the suite still runs anywhere.
 * A skip is a pass that proves nothing, which is why the CI job for this module
 * brings a Redis with it.
 *
 * @group redis_rtt
 */
class RedisRttCacheTest extends GenericCacheBackendUnitTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'redis', 'redis_rtt'];

  /**
   * Bins this run adds to the batched list, on top of the module's defaults.
   *
   * Empty here: the contract is run first against the write path as any bin
   * outside the batched list gets it, which is the stock path.
   *
   * @var string[]
   */
  protected array $extraBatchedBins = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $this->applyRedisSettings();
    parent::register($container);

    // The redis module owns this service; swapping it for the module's own is
    // what makes the comparison meaningful, since core's database provider
    // would resolve cache tags in SQL.
    $container->register('cache_tags.invalidator.checksum', 'Drupal\redis_rtt\Cache\PreloadingRedisCacheTagsChecksum')
      ->addArgument(new Reference('redis.factory'))
      ->addTag('cache_tags_invalidator');
  }

  /**
   * Points the redis module at the Redis this test can reach, and skips if none.
   */
  protected function applyRedisSettings(): void {
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = (int) (getenv('REDIS_PORT') ?: 6379);

    $socket = @fsockopen($host, $port, $errno, $error, 1);
    if ($socket === FALSE) {
      $this->markTestSkipped("No Redis reachable at $host:$port. Set REDIS_HOST and REDIS_PORT to run this.");
    }
    fclose($socket);

    $settings = Settings::getAll();
    $settings['redis.connection']['interface'] = getenv('REDIS_INTERFACE') ?: 'PhpRedis';
    $settings['redis.connection']['host'] = $host;
    $settings['redis.connection']['port'] = $port;
    // A prefix per test run, so two runs against the same Redis cannot see each
    // other's keys and a failure is never someone else's leftovers.
    $settings['cache_prefix'] = 'rtt_test_' . getmypid() . '_' . substr(hash('sha256', static::class . microtime()), 0, 8);
    if ($this->extraBatchedBins) {
      $settings['redis_rtt_batched_bins'] = array_merge(
        ['render', 'data', 'menu', 'dynamic_page_cache'],
        $this->extraBatchedBins,
      );
    }

    new Settings($settings);
  }

  /**
   * {@inheritdoc}
   */
  protected function createCacheBackend($bin) {
    return $this->backendFor($bin);
  }

  /**
   * Returns the module's backend for a bin, typed.
   *
   * ::getCacheBackend() is declared as returning the interface, which does not
   * carry ::getKey() - and a test that has to know a key must ask the backend
   * for it rather than rebuild a prefix that is derived, not configured.
   *
   * @param string $bin
   *   The cache bin.
   *
   * @return \Drupal\redis_rtt\Cache\BatchingRedisBackend
   *   The backend for that bin.
   */
  protected function backendFor(string $bin): BatchingRedisBackend {
    return \Drupal::service('cache.backend.redis_rtt')->get($bin);
  }

  /**
   * {@inheritdoc}
   *
   * Copied from the redis module's own subclass, for the same reason: the
   * default redis_invalidate_all_as_delete optimisation turns invalidateAll()
   * into deleteAll(), which the inherited assertions do not expect. The
   * optimisation itself is covered by ::testInvalidateAllOptimized().
   *
   * @group legacy
   */
  public function testInvalidateAll(): void {
    $this->setSetting('redis_invalidate_all_as_delete', FALSE);

    $backend_a = $this->getCacheBackend();
    $backend_b = $this->getCacheBackend('bootstrap');

    $backend_a->set('test1', 1, Cache::PERMANENT);
    $backend_a->set('test2', 3, time() + 1000);
    $backend_b->set('test3', 4, Cache::PERMANENT);

    @$backend_a->invalidateAll();

    $this->assertFalse($backend_a->get('test1'), 'First key has been invalidated.');
    $this->assertFalse($backend_a->get('test2'), 'Second key has been invalidated.');
    $this->assertNotEmpty($backend_b->get('test3'), 'Item in other bin is preserved.');
    $this->assertNotEmpty($backend_a->get('test1', TRUE), 'First key has not been deleted.');
    $this->assertNotEmpty($backend_a->get('test2', TRUE), 'Second key has not been deleted.');
  }

  /**
   * Invalidating a whole bin removes it, which is the module's default.
   */
  public function testInvalidateAllOptimized(): void {
    $this->setSetting('redis_invalidate_all_as_delete', TRUE);

    $backend_a = $this->getCacheBackend();
    $backend_b = $this->getCacheBackend('bootstrap');

    $backend_a->set('test1', 1, Cache::PERMANENT);
    $backend_a->set('test2', 3, time() + 1000);
    $backend_b->set('test3', 4, Cache::PERMANENT);

    @$backend_a->invalidateAll();

    $this->assertFalse($backend_a->get('test1'));
    $this->assertFalse($backend_a->get('test2'));
    $this->assertNotEmpty($backend_b->get('test3'), 'Item in other bin is preserved.');
    $this->assertEmpty($backend_a->get('test1', TRUE), 'First key has been deleted.');
    $this->assertEmpty($backend_a->get('test2', TRUE), 'Second key has been deleted.');
  }

  /**
   * A permanent entry gets a lifetime, an expiring one gets its own plus offset.
   *
   * The unit suite can assert the module hands a TTL to Redis; only this can
   * assert Redis received it. Both mutations a review found - never sending the
   * TTL, and sending an expiry already in the past - are visible here.
   */
  public function testEntriesReachRedisWithTheirLifetime(): void {
    $backend = $this->getCacheBackend();
    $backend->set('permanent', 'value');
    $backend->set('expiring', 'value', \Drupal::time()->getRequestTime() + 600);
    $this->flushBatch();

    $client = \Drupal::service('redis.factory')->getClient();
    // The key comes from the backend rather than being rebuilt here: the prefix
    // is derived, not just read from settings, and a test that guesses it wrong
    // fails on a key that never existed instead of on the lifetime it means to
    // check.
    $keys = $this->backendFor($this->getTestBin());
    $permanent = $client->ttl($keys->getKey('permanent'));
    $expiring = $client->ttl($keys->getKey('expiring'));

    $this->assertGreaterThan(0, $permanent, 'A permanent entry still carries a lifetime, so an abandoned bin cannot grow without bound.');
    $this->assertGreaterThan(600, $expiring, 'An expiring entry outlives its own expiry by the configured offset, so allow_invalid still works.');
    $this->assertLessThan($permanent, $expiring, 'And it expires well before a permanent one.');
  }

  /**
   * An entry invalidated by cache tag is gone, and its neighbour is not.
   *
   * Inverting one line of the invalidation script used to leave the unit suite
   * green, because the stand-in reimplemented the branch rather than reading it.
   */
  public function testTagInvalidationReachesRedis(): void {
    $backend = $this->getCacheBackend();
    $backend->set('etiquetado', 'value', Cache::PERMANENT, ['prueba:1']);
    $backend->set('sin_etiqueta', 'value');
    $this->flushBatch();

    Cache::invalidateTags(['prueba:1']);

    $this->assertFalse($backend->get('etiquetado'), 'The tagged entry is invalid.');
    $this->assertNotEmpty($backend->get('sin_etiqueta'), 'Its neighbour is untouched.');
  }


  /**
   * A bin flushed by another process stops serving entries written before it.
   *
   * The contract inherited from core cannot reach this: within one process,
   * ::deleteAll() sets the marker on the backend instance that called it, so
   * every later read short-circuits before the branch that matters. Replacing
   * the marker read with 0.0 - ignoring every flush anyone else performs, a
   * "drush cr" on another node for instance - passes the whole inherited suite
   * and this module's unit suite alike.
   *
   * So the flush is issued the way another process would issue it: straight
   * into Redis, against a backend that has not resolved its marker yet.
   */
  public function testAFlushByAnotherProcessIsHonoured(): void {
    // A bin nothing else has touched, so this backend starts with its marker
    // unresolved, exactly as a fresh request would.
    $backend = $this->backendFor('marcador');
    $backend->set('anterior', 'value');
    $this->flushBatch();

    // What deleteAll() does on the wire, done by somebody else. The pauses are
    // the ones the stock ::deleteAll() takes for the same reason: the marker is
    // in milliseconds, so without them an entry written in the same millisecond
    // would be indistinguishable from one written after the flush.
    usleep(2000);
    $client = \Drupal::service('redis.factory')->getClient();
    $client->set($backend->getKey('_redis_last_delete_all'), round(microtime(TRUE), 3));
    usleep(2000);

    $this->assertFalse($backend->get('anterior'), 'An entry older than another process flush must not be served.');
    $this->assertFalse($backend->get('anterior', TRUE), 'Not even when invalid entries are allowed.');

    // And the bin still works afterwards.
    $backend->set('posterior', 'value');
    $this->flushBatch();
    $this->assertNotEmpty($backend->get('posterior'), 'An entry written after the flush is served normally.');
  }

  /**
   * Sends anything the batch is still holding, so Redis can be inspected.
   */
  protected function flushBatch(): void {
    \Drupal::service('redis_rtt.write_batch')->send();
  }

}

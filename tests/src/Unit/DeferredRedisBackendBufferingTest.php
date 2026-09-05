<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\DeferredRedisBackend;
use Drupal\redis_rtt\Redis\CommandBuffer;
use Drupal\redis\ClientFactory;

/**
 * Checks which writes may be deferred and which may not.
 *
 * Buffering a write opens a window in which another process can delete the key,
 * and the flush then recreates it: an absent key is not a newer key, so
 * CommandBuffer's write guard lets it through. For most entries that is a
 * bounded nuisance - the entry expires, or a cache tag invalidation kills it,
 * and the next request recomputes it. The stock backend can lose the same race
 * the other way round and Drupal copes.
 *
 * For an entry with neither an expiry nor a cache tag there is no next step.
 * Nothing in Drupal will ever look at it again to decide it is wrong.
 * cache_config is exactly that shape - permanent whenever the bin is in
 * redis_permanent_bins, which is the default, and written by CachedStorage with
 * no tags - so the resurrected entry is deleted configuration served as live
 * configuration, on every web node, until a human runs a cache rebuild.
 *
 * Those entries are therefore written synchronously, where the stock backend
 * writes them. The rest stay buffered.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Cache\DeferredRedisBackend
 * @group redis_rtt
 */
class DeferredRedisBackendBufferingTest extends UnitTestCase {

  /**
   * The key prefix every backend in this test shares.
   */
  protected const PREFIX = 'p';

  /**
   * The key the one cache entry in these tests lives under.
   */
  protected const ENTRY = 'p:config:system.site';

  /**
   * The fake Redis both the backend and the buffer talk to.
   */
  protected FakeRedisClient $client;

  /**
   * The shared write buffer, flushed by hand instead of at shutdown.
   */
  protected CommandBuffer $buffer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings(['redis_rtt_defer_writes' => TRUE]);
    $this->client = new FakeRedisClient();
    $factory = $this->createMock(ClientFactory::class);
    $factory->method('getClient')->willReturn($this->client);
    $this->buffer = new CommandBuffer($factory);
  }

  /**
   * Builds the consistent backend for a permanent bin.
   *
   * 'config' is in the redis_permanent_bins default, so
   * \Drupal\redis\Cache\RedisBackend::getExpiration() returns NULL for a
   * permanent entry and the key is written with no TTL at all.
   *
   * @return \Drupal\redis_rtt\Cache\DeferredRedisBackend
   *   The config bin.
   */
  protected function configBackend(): DeferredRedisBackend {
    return $this->backend('config');
  }

  /**
   * Builds the consistent backend for any bin.
   *
   * @param string $bin
   *   The bin name. Whether it is in the redis_permanent_bins default is the
   *   whole subject here, so it is a parameter rather than a constant.
   *
   * @return \Drupal\redis_rtt\Cache\DeferredRedisBackend
   *   The bin.
   */
  protected function backend(string $bin): DeferredRedisBackend {
    $checksum = $this->createMock(CacheTagsChecksumInterface::class);
    // Without this every read is discarded as tag-invalid and a test that
    // reads back what it wrote would fail for the wrong reason.
    $checksum->method('isValid')->willReturn(TRUE);

    $backend = new DeferredRedisBackend(
      $bin,
      $this->client,
      $checksum,
      new PhpSerialize(),
      $this->buffer,
    );
    $backend->setPrefix(self::PREFIX);

    return $backend;
  }

  /**
   * An entry with no expiry and no tag is written straight away.
   *
   * @covers ::setMultiple
   */
  public function testEntriesThatCannotHealThemselvesAreWrittenSynchronously(): void {
    $this->configBackend()->set('system.site', ['x' => 'ORIGINAL']);

    $this->assertArrayHasKey(self::ENTRY, $this->client->data, 'A permanent, untagged entry must not sit in the buffer waiting to be undone.');
    $this->assertSame(0, $this->buffer->getStats()['pending'], 'Nothing should be left pending.');
  }

  /**
   * A tag is enough to make deferral safe, so a tagged entry is buffered.
   *
   * @covers ::setMultiple
   */
  public function testTaggedEntriesAreStillBuffered(): void {
    $this->configBackend()->set('system.site', ['x' => 'ORIGINAL'], Cache::PERMANENT, ['config:system.site']);

    $this->assertArrayNotHasKey(self::ENTRY, $this->client->data, 'A tagged entry can be corrected by invalidating that tag, so it may still be deferred.');
    $this->assertSame(1, $this->buffer->getStats()['pending']);
  }

  /**
   * So is an expiry, which bounds how long a wrong entry can be wrong.
   *
   * The same permanent, untagged write to a bin that is *not* in
   * redis_permanent_bins gets the one-year default TTL instead of none, and is
   * buffered - which is the point: the rule is about the entry that results,
   * not about the name of the bin.
   *
   * @covers ::setMultiple
   */
  public function testEntriesWithAnExpiryAreStillBuffered(): void {
    $this->backend('render')->set('some-cid', ['x' => 'ORIGINAL']);

    $this->assertArrayNotHasKey('p:render:some-cid', $this->client->data);
    $this->assertSame(1, $this->buffer->getStats()['pending']);
  }

  /**
   * The write lands even though it never went through the buffer.
   *
   * Writing synchronously must not mean writing differently: the entry has to
   * be the same entry, readable by ::get() and by the stock backend alike.
   *
   * @covers ::setMultiple
   */
  public function testTheSynchronousWriteStoresTheSameEntry(): void {
    $backend = $this->configBackend();
    $backend->set('system.site', ['x' => 'ORIGINAL']);

    $item = $backend->get('system.site');
    $this->assertNotFalse($item, 'The entry has to be readable.');
    $this->assertSame(['x' => 'ORIGINAL'], $item->data);
    $this->assertSame('', $this->client->data[self::ENTRY]['tags']);
  }

  /**
   * A delete by another process is not undone by this one's flush.
   *
   * The reproduction, reduced to one process: node A writes config on a cold
   * cache, node B deletes that config object, node A's request ends. The delete
   * is simulated by removing the key behind the backend's back, which is what
   * another process' DEL looks like from here.
   *
   * @covers ::setMultiple
   */
  public function testDeletesByAnotherProcessSurviveThisProcessFlush(): void {
    $this->configBackend()->set('system.site', ['x' => 'ORIGINAL']);
    $this->assertArrayHasKey(self::ENTRY, $this->client->data);

    // Another web node deletes the configuration object.
    unset($this->client->data[self::ENTRY]);

    // This process reaches the end of its request.
    $this->buffer->flush();

    $this->assertArrayNotHasKey(
      self::ENTRY,
      $this->client->data,
      'Deleted configuration must stay deleted: nothing would ever correct it if it came back.',
    );
  }

  /**
   * The buffer is what made that possible, so hold the contrast in place.
   *
   * A tagged entry written by this process IS resurrected by the flush, and
   * that is the documented, deliberate limit of the write guard: a tombstone on
   * every delete would be needed to close it, and such an entry corrects itself
   * the next time its tag is invalidated. This test exists so that the day
   * someone closes that gap, they are told that this expectation changed rather
   * than discovering it in production.
   *
   * @covers ::setMultiple
   * @see \Drupal\redis_rtt\Redis\CommandBuffer::flush()
   */
  public function testTaggedEntriesAreStillResurrectedByTheFlush(): void {
    $this->configBackend()->set('system.site', ['x' => 'ORIGINAL'], Cache::PERMANENT, ['config:system.site']);

    // Another web node deletes it. There was nothing to delete in Redis yet,
    // so this is the harshest version of the race.
    unset($this->client->data[self::ENTRY]);

    $this->buffer->flush();

    $this->assertArrayHasKey(
      self::ENTRY,
      $this->client->data,
      'Still resurrected - bounded by the tag, and documented as such.',
    );
  }

}

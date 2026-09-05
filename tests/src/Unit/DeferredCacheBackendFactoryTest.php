<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis_rtt\Cache\DeferredCacheBackendFactory;
use Drupal\redis_rtt\Redis\CommandBuffer;
use Drupal\redis\ClientFactory;

/**
 * Checks which bins are handed a buffer and which are not.
 *
 * A buffered write that outlives another process' delete is replayed - an
 * absent key is not a newer key, so CommandBuffer's write guard recreates it.
 * Where the entry expires soon or carries a tag something invalidates, that
 * corrects itself. Where it does not, it is served as live data indefinitely,
 * and in cache.entity it is worse than that: the node edit form is built from
 * the entity, so the next save from that form writes the resurrected values
 * back into the database and destroys the editor's change.
 *
 * These tests pin the bins excluded because of that. They do not claim the rest
 * are safe - the exclusion list is a list of known damage, not a proof.
 *
 * @coversDefaultClass \Drupal\redis_rtt\Cache\DeferredCacheBackendFactory
 * @group redis_rtt
 */
class DeferredCacheBackendFactoryTest extends UnitTestCase {

  /**
   * The fake Redis every backend in this test talks to.
   */
  protected FakeRedisClient $client;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->client = new FakeRedisClient();
  }

  /**
   * Builds the factory the container would build.
   *
   * @param array<string, mixed> $settings
   *   Settings overrides for this factory.
   *
   * @return \Drupal\redis_rtt\Cache\DeferredCacheBackendFactory
   *   The factory.
   */
  protected function factory(array $settings = []): DeferredCacheBackendFactory {
    $settings += ['redis_rtt_defer_writes' => TRUE];
    $settings_object = new Settings($settings);

    $client_factory = $this->createMock(ClientFactory::class);
    $client_factory->method('getClient')->willReturn($this->client);

    return new DeferredCacheBackendFactory(
      $client_factory,
      $this->createMock(CacheTagsChecksumInterface::class),
      new PhpSerialize(),
      new CommandBuffer($client_factory, $settings_object),
      $settings_object,
    );
  }

  /**
   * Writes a permanent, tagged entry and says whether it reached Redis.
   *
   * Tagged and permanent on purpose: that is the shape of a cache.entity entry,
   * which the ttl-based guard in DeferredRedisBackend::setMultiple() lets
   * through. If it lands synchronously, this bin is not buffering.
   *
   * @param string $bin
   *   The bin to write to.
   *
   * @return bool
   *   TRUE if the write reached Redis before any flush.
   */
  protected function writeLandsImmediately(string $bin): bool {
    $backend = $this->factory()->get($bin);
    $backend->setPrefix('p');
    $backend->set('probe', 'value', Cache::PERMANENT, ['probe_values']);

    return array_key_exists('p:' . $bin . ':probe', $this->client->data);
  }

  /**
   * The bins whose resurrection damage cannot be undone do not buffer.
   *
   * cache.entity resurrects a pre-save entity and the next form save writes it
   * back to the database; cache.default resurrects deleted extension lists,
   * which carry no tag and a one-year TTL. Both were measured against a stock
   * redis twin: 31 stale of 39 versus 0 of 39, and 4 of 5 versus 0 of 5.
   *
   * @covers ::__construct
   * @covers ::get
   */
  public function testTheBinsThatCannotSurviveResurrectionDoNotBuffer(): void {
    $this->assertTrue($this->writeLandsImmediately('entity'), 'cache.entity must not buffer: a resurrected entity reaches the database through the edit form.');
    $this->assertTrue($this->writeLandsImmediately('default'), 'cache.default must not buffer: its extension lists carry no tag and a one-year TTL.');
    $this->assertTrue($this->writeLandsImmediately('container'), 'cache.container must not buffer: a later request has to read the compiled container back.');
  }

  /**
   * Every other bin still buffers, which is what the module is for.
   *
   * @covers ::get
   */
  public function testEveryOtherBinStillBuffers(): void {
    foreach (['render', 'data', 'menu', 'dynamic_page_cache'] as $bin) {
      $this->assertFalse($this->writeLandsImmediately($bin), sprintf('cache.%s should still batch its writes.', $bin));
    }
  }

  /**
   * A site can still name the list itself, and replaces the default wholesale.
   *
   * @covers ::__construct
   */
  public function testTheSiteCanOverrideTheList(): void {
    $factory = $this->factory(['redis_rtt_unbuffered_bins' => ['render']]);

    $render = $factory->get('render');
    $render->setPrefix('p');
    $render->set('probe', 'value', Cache::PERMANENT, ['probe_values']);
    $this->assertArrayHasKey('p:render:probe', $this->client->data, 'The named bin must be unbuffered.');

    $entity = $factory->get('entity');
    $entity->setPrefix('p');
    $entity->set('probe', 'value', Cache::PERMANENT, ['probe_values']);
    $this->assertArrayNotHasKey('p:entity:probe', $this->client->data, 'An explicit list replaces the default rather than adding to it.');
  }

  /**
   * The factory hands back one instance per bin.
   *
   * Per-bin state - the last delete-all marker above all - is resolved once per
   * request only while callers share the instance.
   *
   * @covers ::get
   */
  public function testEachBinIsBuiltOnce(): void {
    $factory = $this->factory();

    $this->assertSame($factory->get('render'), $factory->get('render'));
    $this->assertNotSame($factory->get('render'), $factory->get('data'));
  }

}

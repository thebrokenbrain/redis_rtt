<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\Cache\CacheFactoryInterface;
use Drupal\Core\Cache\CacheTagsChecksumInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\Component\Serialization\PhpSerialize;
use Drupal\Tests\UnitTestCase;
use Drupal\redis\ClientFactory as RedisClientFactory;
use Drupal\redis\ClientInterface;
use Drupal\redis_rtt\Cache\ApcuShortcutStore;
use Drupal\redis_rtt\Cache\PipeliningCacheBackendFactory;
use Drupal\redis_rtt\Cache\RedirectShortcutVariationCache;
use Drupal\redis_rtt\Cache\RedirectShortcutVariationCacheFactory;
use Drupal\redis_rtt\Client\CountingClient;
use Drupal\redis_rtt\Client\CountingPhpRedisFactory;
use Drupal\redis_rtt\Client\PhpRedisRttFactory;
use Drupal\redis_rtt\ClientFactory;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Exercises the methods no other test reaches.
 *
 * Round 12 mapped which methods of src/ the suite actually executes, by
 * instrumenting every one and then confirming with a throw. Fifteen were never
 * reached, so any of them could have been emptied out without a single test
 * noticing - including the one line that decides which store the render cache
 * shortcut uses.
 *
 * These assert on what each does, not merely that it can be called: a test
 * that only calls a method turns a coverage hole into a coverage lie.
 *
 * @group redis_rtt
 */
class ReachabilityTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  /**
   * The clock the doubles need.
   */
  protected TimeInterface $time;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    new Settings([]);

    // MemoryBackend asks the container for the request time.
    $this->time = $this->createMock(TimeInterface::class);
    $this->time->method('getRequestTime')->willReturn(1000000);
    $time = $this->time;
    $container = new ContainerBuilder();
    $container->set('datetime.time', $time);
    // MemoryBackend asks for it by class name, not by service id.
    $container->set(TimeInterface::class, $time);
    \Drupal::setContainer($container);
  }

  /**
   * The counting client passes ::scan(), ::info() and the ignore list through.
   *
   * @covers \Drupal\redis_rtt\Client\CountingClient::scan
   * @covers \Drupal\redis_rtt\Client\CountingClient::info
   * @covers \Drupal\redis_rtt\Client\CountingClient::addIgnorePattern
   */
  public function testCountingClientDelegatesTheInterfaceMethods(): void {
    $inner = $this->createMock(ClientInterface::class);
    // ::scan() answers with a generator, not an array: asserting on an array
    // passes against a mock and is statically impossible against the contract.
    $inner->expects($this->once())->method('scan')->with('p:*', 50)
      ->willReturnCallback(static fn (): \Generator => yield from ['una']);
    $inner->expects($this->once())->method('info')->willReturn(['redis_version' => '7.0']);
    $inner->expects($this->once())->method('addIgnorePattern')->with('p:ruido');
    $client = new CountingClient($inner);

    $this->assertSame(['una'], iterator_to_array($client->scan('p:*', 50)));
    $this->assertSame(['redis_version' => '7.0'], $client->info());
    $client->addIgnorePattern('p:ruido');
  }

  /**
   * Deleting a mapping makes it unreadable again.
   *
   * @covers \Drupal\redis_rtt\Cache\ApcuShortcutStore::delete
   */
  public function testTheShortcutStoreForgetsWhatItDeletes(): void {
    $store = new ApcuShortcutStore('render');
    $store->set('clave', ['cid' => 'c1']);
    $this->assertSame(['cid' => 'c1'], $store->get('clave'));

    $store->delete('clave');

    $this->assertNull($store->get('clave'), 'Deleted means gone, not stale.');
  }

  /**
   * The bin factory hands back the same backend for the same bin.
   *
   * Half of what this class saves is not building - and not reconnecting for -
   * a backend per call. Nothing checked it.
   *
   * @covers \Drupal\redis_rtt\Cache\PipeliningCacheBackendFactory::get
   */
  public function testTheBinFactoryMemoisesPerBin(): void {
    $clients = $this->createMock(RedisClientFactory::class);
    $clients->expects($this->once())->method('getClient')->willReturn(new FakeRedisClient());
    $factory = new PipeliningCacheBackendFactory(
      $clients,
      $this->createMock(CacheTagsChecksumInterface::class),
      new PhpSerialize()
    );

    $first = $factory->get('render');
    $second = $factory->get('render');

    $this->assertSame($first, $second, 'One backend per bin, not one per call.');
  }

  /**
   * The variation cache factory is what wires the APCu store in.
   *
   * It is the only line that chooses ApcuShortcutStore; every test of that
   * cache builds it by hand with a double, so this line answered to nobody.
   *
   * @covers \Drupal\redis_rtt\Cache\RedirectShortcutVariationCacheFactory::__construct
   * @covers \Drupal\redis_rtt\Cache\RedirectShortcutVariationCacheFactory::get
   */
  public function testTheVariationCacheFactoryBuildsAndMemoises(): void {
    $backends = $this->createMock(CacheFactoryInterface::class);
    $backends->method('get')->willReturn(new MemoryBackend($this->time));
    $factory = new RedirectShortcutVariationCacheFactory(
      new RequestStack(),
      $backends,
      $this->createMock(CacheContextsManager::class)
    );

    $cache = $factory->get('render');

    $this->assertInstanceOf(RedirectShortcutVariationCache::class, $cache);
    $this->assertSame($cache, $factory->get('render'), 'One per bin.');
    $this->assertNotSame($cache, $factory->get('dynamic_page_cache'), 'And one per bin.');
  }

  /**
   * The instrumented factory answers to its own name and wraps its client.
   *
   * @covers \Drupal\redis_rtt\Client\CountingPhpRedisFactory::getName
   */
  public function testTheCountingFactoryIsNamedApart(): void {
    $factory = new CountingPhpRedisFactory();

    $this->assertSame('CountingPhpRedis', $factory->getName());
    $this->assertNotSame(
      (new PhpRedisRttFactory())->getName(),
      $factory->getName(),
      'Two factories that answered to the same name could not both be chosen.'
    );
  }

  /**
   * The bootstrap factory knows every documented interface name.
   *
   * Its whole reason for existing is that the stock factory falls back to a
   * hardcoded list during bootstrap, which does not include this module's
   * client - so naming it in settings.php threw.
   *
   * @covers \Drupal\redis_rtt\ClientFactory::__construct
   */
  public function testTheBootstrapFactoryRegistersEveryClient(): void {
    $factory = new ClientFactory();
    $property = (new \ReflectionObject($factory))->getProperty('factories');
    $property->setAccessible(TRUE);
    $names = array_keys($property->getValue($factory));

    foreach (['PhpRedisRtt', 'PhpRedis', 'Predis', 'Relay', 'CountingPhpRedis'] as $expected) {
      $this->assertContains($expected, $names, "$expected must resolve during bootstrap.");
    }
    $this->assertSame('PhpRedisRtt', $names[0], 'And this module wins when none is named.');
  }

  /**
   * Whether a stream context is accepted is decided by the extension version.
   *
   * @covers \Drupal\redis_rtt\Client\PhpRedisRttFactory::supportsConnectContext
   */
  public function testConnectContextFollowsTheExtensionVersion(): void {
    $method = (new \ReflectionClass(PhpRedisRttFactory::class))->getMethod('supportsConnectContext');
    $method->setAccessible(TRUE);

    $expected = version_compare((string) phpversion('redis'), '5.3.0', '>=');

    $this->assertSame($expected, $method->invoke(NULL));
  }

  /**
   * Each bin gets a store of its own, keyed under its own APCu prefix.
   *
   * The factory test above pins that a cache is built and memoised per bin, but
   * not what is inside it. The one line that chooses the store could be made to
   * hand every bin the 'render' one, and then four bins share a key space in a
   * segment they also share with the class loader: what one bin learned another
   * would read back as its own. Nothing failed.
   *
   * @covers \Drupal\redis_rtt\Cache\RedirectShortcutVariationCacheFactory::get
   */
  public function testEachBinGetsItsOwnStore(): void {
    $backends = $this->createMock(CacheFactoryInterface::class);
    $backends->method('get')->willReturn(new MemoryBackend($this->time));
    $factory = new RedirectShortcutVariationCacheFactory(
      new RequestStack(),
      $backends,
      $this->createMock(CacheContextsManager::class)
    );

    $prefijo = static function (object $cache): string {
      $store = (new \ReflectionObject($cache))->getProperty('store');
      $store->setAccessible(TRUE);
      $inner = $store->getValue($cache);
      $prefix = (new \ReflectionObject($inner))->getProperty('prefix');
      $prefix->setAccessible(TRUE);

      return (string) $prefix->getValue($inner);
    };

    $render = $prefijo($factory->get('render'));
    $dynamic = $prefijo($factory->get('dynamic_page_cache'));

    $this->assertNotSame($render, $dynamic, 'Two bins must not share a key space.');
    $this->assertStringContainsString('render', $render, 'And each one is named after its bin.');
    $this->assertStringContainsString('dynamic_page_cache', $dynamic);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\redis\ClientFactory;

/**
 * The status report has to describe the connection, not the intention.
 *
 * An operator reads the connection check to find out one thing: whether the
 * bounded read timeout that keeps a failover from exhausting the worker pool is
 * in force. It used to answer from $settings['redis.connection']['interface']
 * alone, which is not where that answer lives. With no interface named the
 * client is chosen by tagged service priority instead - a documented, supported
 * configuration - so a site running PhpRedisRtt exactly as intended was told
 * it was not, and a check that cries wolf about a correct configuration gets
 * configurations "fixed" until it stops.
 *
 * The other direction matters as much: when no connection has been made yet
 * there is nothing to ask, and the honest report is that it is not known rather
 * than a claim of protection that may not exist.
 *
 * @group redis_rtt
 */
class ConnectionRequirementsTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The hook lives in a .install file, which nothing autoloads, and reports
    // severities with the constants from core's install.inc, which a unit test
    // does not bootstrap either.
    require_once $this->root . '/core/includes/install.inc';
    require_once __DIR__ . '/../../../redis_rtt.install';
  }

  /**
   * Runs the runtime requirements against a given connection state.
   *
   * @param array<string, mixed> $connection
   *   The $settings['redis.connection'] array.
   * @param string|null $client
   *   The name of the client the redis factory has already built, or NULL when
   *   nothing has needed a connection yet this request.
   *
   * @return array<string, mixed>
   *   The connection requirement.
   */
  protected function connectionRequirement(array $connection, ?string $client): array {
    new Settings([
      'cache' => ['default' => 'cache.backend.redis_rtt'],
      'redis.connection' => $connection,
    ]);

    $factory = $this->createMock(ClientFactory::class);
    $factory->method('hasClient')->willReturn($client !== NULL);
    $factory->method('getClientName')->willReturn($client);

    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('redis.factory', $factory);
    \Drupal::setContainer($container);

    return redis_rtt_requirements('runtime')['redis_rtt_connection'];
  }

  /**
   * The client that exists answers, not the setting that asked for one.
   *
   * No interface is named here, which is the documented way to let the
   * container choose: this module's factory is tagged at the highest priority,
   * so it is the one that gets used.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testTheConnectionInUseIsWhatGetsReported(): void {
    $requirement = $this->connectionRequirement(['persistent' => TRUE], 'PhpRedisRtt');

    $this->assertSame(REQUIREMENT_OK, $requirement['severity']);
    $this->assertStringContainsString('PhpRedisRtt', (string) $requirement['value']);
  }

  /**
   * Instrumentation renames the client without changing what it is.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testAnInstrumentedConnectionStillCounts(): void {
    $requirement = $this->connectionRequirement(
      ['persistent' => TRUE, 'count_commands' => TRUE],
      'PhpRedisRtt (instrumented)',
    );

    $this->assertSame(REQUIREMENT_OK, $requirement['severity']);
  }

  /**
   * When the setting and the connection disagree, the connection wins.
   *
   * It is the thing that either has a read timeout or does not.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testTheSettingDoesNotOverrideWhatWasActuallyBuilt(): void {
    $requirement = $this->connectionRequirement(
      ['persistent' => TRUE, 'interface' => 'PhpRedisRtt'],
      'PhpRedis',
    );

    $this->assertSame(REQUIREMENT_WARNING, $requirement['severity']);
    $this->assertStringContainsString(
      'PhpRedis',
      (string) $requirement['description']['#items'][0],
      'The report has to name the client that is actually in use.',
    );
  }

  /**
   * What cannot be known is said, not assumed either way.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testAnUnbuiltConnectionIsReportedAsUnknown(): void {
    $requirement = $this->connectionRequirement(['persistent' => TRUE], NULL);

    $this->assertSame('Unknown', (string) $requirement['value']);
    $this->assertSame(REQUIREMENT_WARNING, $requirement['severity']);
  }

  /**
   * Naming the interface settles it before anything has connected.
   *
   * \Drupal\redis\ClientFactory throws on an interface it does not know, so a
   * site that is serving the status report at all is using the one it names.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testTheNamedInterfaceAnswersWhenNothingHasConnected(): void {
    $requirement = $this->connectionRequirement(
      ['persistent' => TRUE, 'interface' => 'PhpRedisRtt'],
      NULL,
    );

    $this->assertSame(REQUIREMENT_OK, $requirement['severity']);
  }

  /**
   * The settings-only checks are still made, and still listed.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testPersistenceIsReportedAlongsideTheClient(): void {
    $requirement = $this->connectionRequirement(['persistent' => FALSE], 'PhpRedisRtt');

    $this->assertSame(REQUIREMENT_WARNING, $requirement['severity']);
    $this->assertStringContainsString(
      'Persistent connections are off',
      (string) $requirement['description']['#items'][0],
    );
    $this->assertStringContainsString('PhpRedisRtt', (string) $requirement['value']);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleExtensionList;
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

  /**
   * A read timeout that is not a number turns the row amber, not green.
   *
   * The point of routing that message into the notes rather than the infos is
   * the severity: a mistyped value silently removes the one protection this
   * client adds, and a row that stays green tells the operator it was on
   * purpose. Moving the message to the other list leaves the text identical and
   * the row OK, and nothing was checking which list it went into.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testInvalidReadTimeoutMakesTheRowAmber(): void {
    foreach (['0,5', 'abc', '', FALSE] as $configured) {
      // Persistent on, so the only thing that can turn the row amber is the
      // read timeout: without that, the note about non-persistent connections
      // does it and the test passes whichever list the message went into.
      $row = $this->connectionRequirement(
        ['read_timeout' => $configured, 'persistent' => TRUE],
        'PhpRedisRtt'
      );

      $this->assertSame(
        REQUIREMENT_WARNING,
        $row['severity'],
        'A value that is not a number is a problem, and the row has to say so.'
      );
      $this->assertStringContainsString(
        'not a number',
        $this->text($row),
        'And say which value, and that it was refused.'
      );
    }
  }

  /**
   * A read timeout that is fine leaves the row alone.
   *
   * The control: without this, a row wired to warn about everything would pass
   * the test above.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testUsableReadTimeoutKeepsTheRowGreen(): void {
    foreach ([['read_timeout' => 2.5], ['read_timeout' => 0], []] as $connection) {
      $row = $this->connectionRequirement($connection + ['persistent' => TRUE], 'PhpRedisRtt');

      $this->assertSame(
        REQUIREMENT_OK,
        $row['severity'],
        'A usable setting is not a problem.'
      );
    }
  }

  /**
   * The advice about service overrides names a path that exists.
   *
   * It used to be a hardcoded modules/contrib/..., which is wrong wherever the
   * module actually is; Drupal ignores a container_yamls line pointing at a
   * file that is not there without a word, so the advice did nothing, drush cr
   * still exited 0, and the row said "inactive" for ever.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testTheOverridesAdviceNamesTheRealPath(): void {
    // Where Drupal says the module is, which is the only authority on it: with
    // the module symlinked into the docroot - the default for a Composer path
    // repository - deriving it from the file's own location resolves the link
    // and lands outside the docroot. Asserting against the same derivation the
    // subject uses would be a tautology and would pass either way.
    $list = $this->createMock(ModuleExtensionList::class);
    $list->method('getPath')->with('redis_rtt')->willReturn('modules/dev/redis_rtt');

    new Settings(['cache' => ['default' => 'cache.backend.redis_rtt']]);
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    $container->set('extension.list.module', $list);
    \Drupal::setContainer($container);

    $rows = redis_rtt_requirements('runtime');
    $this->assertArrayHasKey('redis_rtt_services', $rows, 'The row exists at all.');
    $texto = $this->text($rows['redis_rtt_services']);

    $this->assertStringContainsString(
      'modules/dev/redis_rtt/redis_rtt.services.example.yml',
      $texto,
      'The advice has to name where Drupal found the module.'
    );
    $this->assertStringNotContainsString(
      'modules/contrib/redis_rtt',
      $texto,
      'And not a guess about where it usually lives.'
    );
  }

  /**
   * Without the extension list, the advice still names a usable path.
   *
   * The hook also runs where that service is not there, and the
   * fallback has to answer something the operator can paste.
   *
   * @covers ::redis_rtt_requirements
   */
  public function testTheOverridesAdviceFallsBackWithoutTheExtensionList(): void {
    new Settings(['cache' => ['default' => 'cache.backend.redis_rtt']]);
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);

    $texto = $this->text(redis_rtt_requirements('runtime')['redis_rtt_services']);

    // The strong form: whatever path comes out, the file it names has to be
    // there. Asserting on the shape of the string would pass for the hardcoded
    // guess as happily as for the real thing - they look identical - and it is
    // precisely the guess that names a file which does not exist.
    $this->assertSame(
      1,
      preg_match('#([A-Za-z0-9_./-]+/redis_rtt\\.services\\.example\\.yml)#', $texto, $m),
      'The advice names exactly one path to the file.'
    );
    $this->assertFileExists(
      DRUPAL_ROOT . '/' . $m[1],
      'The path the operator is told to paste has to name a file that is there.'
    );
  }

  /**
   * Flattens a requirement description to plain text.
   *
   * @param array<string, mixed> $row
   *   The requirement.
   *
   * @return string
   *   Everything the operator would read.
   */
  protected function text(array $row): string {
    $description = $row['description'] ?? '';
    if (is_array($description)) {
      return implode(' ', array_map('strval', $description['#items'] ?? []));
    }

    return (string) $description;
  }

}

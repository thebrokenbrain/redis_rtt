<?php

declare(strict_types=1);

namespace Drupal\Tests\redis_rtt\Unit;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The connection factory must not be selected without being asked for.
 *
 * \Drupal\redis\ClientFactory takes the highest-priority registered factory
 * when $settings['redis.connection']['interface'] names none. The redis module
 * tags its own at 20, so anything above that here would mean that merely
 * installing this module swapped the client on every site - and with it the
 * connection pool and a bounded read timeout that is right for a cache read and
 * wrong for a blocking queue pop. README.md tells operators in writing that
 * enabling the module changes nothing; this keeps that true.
 *
 * @group redis_rtt
 */
class ClientFactoryPriorityTest extends UnitTestCase {

  /**
   * The redis module's own factories are tagged at this priority.
   */
  private const REDIS_MODULE_PRIORITY = 20;

  /**
   * Every client factory this module registers stays below the redis module's.
   */
  public function testTheClientFactoryIsNotPreferredOverTheRedisModules(): void {
    $services = Yaml::parseFile(__DIR__ . '/../../../redis_rtt.services.yml')['services'];

    $tagged = [];
    foreach ($services as $id => $definition) {
      foreach ($definition['tags'] ?? [] as $tag) {
        if (($tag['name'] ?? NULL) === 'redis_client_factory') {
          $tagged[$id] = $tag['priority'] ?? 0;
        }
      }
    }

    $this->assertNotEmpty($tagged, 'The module registers at least one client factory.');
    foreach ($tagged as $id => $priority) {
      $this->assertLessThan(
        self::REDIS_MODULE_PRIORITY,
        $priority,
        "$id must not outrank the redis module's own factories, or installing this module would change the connection on its own.",
      );
    }
  }

}

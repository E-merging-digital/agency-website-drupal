<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the public Agency health routing contract.
 *
 * @group agency_project_tests
 */
final class AgencyHealthRoutingTest extends TestCase {

  /**
   * Health routes remain public, GET-only and explicitly uncacheable.
   */
  public function testHealthRoutesMatchSharedContract(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/web/modules/custom/agency_health/agency_health.routing.yml';
    self::assertFileExists($path);

    $routing = DrupalYaml::decode((string) file_get_contents($path));
    self::assertIsArray($routing);

    self::assertSame('/health/live', $routing['agency_health.liveness']['path']);
    self::assertSame('/health/ready', $routing['agency_health.readiness']['path']);

    foreach (['agency_health.liveness', 'agency_health.readiness'] as $route) {
      self::assertSame('TRUE', $routing[$route]['requirements']['_access']);
      self::assertSame(['GET'], $routing[$route]['methods']);
      self::assertTrue($routing[$route]['options']['no_cache']);
    }

    self::assertTrue($routing['agency_health.liveness']['options']['_maintenance_access']);
    self::assertArrayNotHasKey(
      '_maintenance_access',
      $routing['agency_health.readiness']['options'],
    );
  }

}

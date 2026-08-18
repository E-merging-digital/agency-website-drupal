<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the Canvas library aligned with the approved Agency SDC catalog.
 *
 * @group agency_project_tests
 * @group governed_canvas
 */
final class GovernedCanvasCatalogCiTest extends TestCase {

  /**
   * Only Agency-approved components may be enabled for Canvas composition.
   */
  public function testCanvasEnabledComponentsMatchApprovedCatalog(): void {
    $projectRoot = dirname(DRUPAL_ROOT);
    $catalog = Yaml::parseFile(
      $projectRoot . '/docs/design-system/component-catalog.yml',
    );

    $expected = [];
    foreach ($catalog['components'] ?? [] as $componentId => $definition) {
      if (
        ($definition['status'] ?? NULL) === 'approved'
        && ($definition['approved_for_ai_composition'] ?? FALSE) === TRUE
      ) {
        $expected[] = 'sdc.' . str_replace(':', '.', (string) $componentId);
      }
    }
    sort($expected);

    self::assertNotEmpty($expected);

    $enabled = [];
    $paths = glob($projectRoot . '/config/sync/canvas.component.*.yml');
    self::assertNotFalse($paths);
    self::assertNotEmpty($paths);

    foreach ($paths as $path) {
      $component = Yaml::parseFile($path);
      if (($component['status'] ?? FALSE) === TRUE) {
        $enabled[] = (string) ($component['id'] ?? '');
      }
    }
    sort($enabled);

    self::assertSame(
      $expected,
      $enabled,
      'Canvas must enable exactly the governed Agency SDC allowlist.',
    );
  }

  /**
   * Canvas and its required media surface must be repository-owned config.
   */
  public function testCanvasRuntimeIsMaterializedInCanonicalConfig(): void {
    $projectRoot = dirname(DRUPAL_ROOT);
    $extensions = Yaml::parseFile($projectRoot . '/config/sync/core.extension.yml');

    foreach (['canvas', 'media', 'media_library'] as $module) {
      self::assertArrayHasKey($module, $extensions['module'] ?? []);
    }
    self::assertArrayHasKey('canvas_stark', $extensions['theme'] ?? []);

    $lock = json_decode(
      (string) file_get_contents($projectRoot . '/composer.lock'),
      TRUE,
      flags: JSON_THROW_ON_ERROR,
    );
    $packages = array_column($lock['packages'] ?? [], NULL, 'name');

    self::assertArrayHasKey('drupal/canvas', $packages);
    self::assertSame('1.10.1', $packages['drupal/canvas']['version'] ?? NULL);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #781 recovery route after the router failure.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageCanvasMigration781RecoveryWorkflowTest extends TestCase {

  /**
   * Recovery preserves the exact hashed migration contract.
   */
  public function testRecoveryIsBoundedToTrustedPlan(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'governed-configuration-language-canvas-migration-781-recovery.yml';
    self::assertFileExists($path);
    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      'github.event.issue.number == 781',
      "'/agency-config-language-canvas-migration resume'",
      "test \"$GITHUB_ACTOR\" = 'E-merging-digital'",
      'persist-credentials: true',
      '8627ce9ec73a45f5501a410e15c4e56fcbc45239ee2c1c166e5d5445337e1a24',
      'CANVAS_CANONICAL_MIGRATION_DRY_RUN_READY',
      'CANVAS_CANONICAL_MIGRATION_PATCH_PREPARED',
      'THIRTY_CANVAS_CANONICAL_PROMOTIONS_VERIFIED',
      'feature/781-apply-canvas-canonical-migration',
      '.counts.base_files_modified == 30',
      '.counts.fr_overrides_created == 15',
      '"en":496',
      '"fr":39',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }
  }

  /**
   * The second proof reinstalls Drupal without restarting the DDEV router.
   */
  public function testRecoveryAvoidsSecondDdevStart(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'governed-configuration-language-canvas-migration-781-recovery.yml';
    $workflow = (string) file_get_contents($path);

    self::assertSame(1, substr_count($workflow, '          ddev start -y'));
    self::assertSame(
      2,
      substr_count($workflow, 'ddev drush site:install --existing-config'),
    );
    self::assertStringContainsString(
      'Reinstall fresh Drupal in same isolated DDEV and verify target',
      $workflow,
    );
    self::assertStringContainsString(
      'without restarting the already healthy isolated DDEV/Traefik project',
      $workflow,
    );
  }

  /**
   * Recovery does not broaden product or migration authority.
   */
  public function testRecoveryRetainsSafetyBoundaries(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'governed-configuration-language-canvas-migration-781-recovery.yml';
    $workflow = (string) file_get_contents($path);

    foreach ([
      'drush cex',
      'generateComponents(',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
      'workflow_dispatch:',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }

    self::assertStringContainsString(
      'Configuration Language Lock must remain disabled after #781 recovery.',
      $workflow,
    );
    self::assertStringContainsString(
      'test -z "$(git diff --name-only -- config/sync/core.extension.yml config/sync/system.site.yml)"',
      $workflow,
    );
  }

}

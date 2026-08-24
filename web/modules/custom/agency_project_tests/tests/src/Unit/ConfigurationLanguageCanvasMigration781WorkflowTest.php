<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the governed #781 exact Canvas canonical migration.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageCanvasMigration781WorkflowTest extends TestCase {

  /**
   * Trusted #779 plan identity is durable and bounded.
   */
  public function testTrustedPlanManifestIsExact(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/docs/evidence/configuration-language-canvas-migration-plan-779.yml';
    self::assertFileExists($path);
    $manifest = DrupalYaml::decode((string) file_get_contents($path));
    self::assertIsArray($manifest);

    self::assertSame(779, $manifest['issue'] ?? NULL);
    self::assertSame(781, $manifest['application_issue'] ?? NULL);
    self::assertSame(
      '8627ce9ec73a45f5501a410e15c4e56fcbc45239ee2c1c166e5d5445337e1a24',
      $manifest['source']['plan_sha256'] ?? NULL,
    );
    self::assertSame(30, $manifest['cohort']['total'] ?? NULL);
    self::assertSame(26, $manifest['cohort']['block'] ?? NULL);
    self::assertSame(4, $manifest['cohort']['sdc'] ?? NULL);
    self::assertCount(30, $manifest['cohort']['names'] ?? []);
    self::assertCount(15, $manifest['block_label_targets'] ?? []);
    self::assertCount(4, $manifest['sdc_names'] ?? []);
    self::assertSame(
      ['__none__' => 59, 'en' => 496, 'fr' => 39, 'und' => 1],
      $manifest['target']['distribution'] ?? NULL,
    );
    self::assertFalse($manifest['constraints']['config_language_lock_enabled'] ?? TRUE);
    self::assertFalse($manifest['constraints']['historical_versions_rewritten'] ?? TRUE);
    self::assertFalse($manifest['constraints']['sdc_values_rewritten'] ?? TRUE);
  }

  /**
   * Writer must reproduce the exact dry-run SHA before any bounded write.
   */
  public function testWriterReplaysExactHashedPlanAndLimitsPaths(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/apply-configuration-language-canvas-migration-781.php';
    self::assertFileExists($path);
    $script = (string) file_get_contents($path);

    foreach ([
      'configuration-language-canvas-migration-dry-run-779.php',
      'configuration-language-canvas-migration-plan-779.yml',
      '8627ce9ec73a45f5501a410e15c4e56fcbc45239ee2c1c166e5d5445337e1a24',
      'CANVAS_CANONICAL_MIGRATION_DRY_RUN_READY',
      'CANVAS_CANONICAL_MIGRATION_PATCH_PREPARED',
      "'langcode'",
      "'label'",
      "'versioned_properties.active.settings.default_settings.label'",
      "'base_files_modified' => count(\$writtenBases)",
      "'fr_overrides_created' => count(\$writtenOverrides)",
      "'historical_versions_rewritten' => FALSE",
      "'sdc_values_rewritten' => FALSE",
      "'config_language_lock_activation_allowed' => FALSE",
    ] as $expected) {
      self::assertStringContainsString($expected, $script);
    }

    foreach ([
      'drush cex',
      'generateComponents(',
      'similar_text(',
      'levenshtein(',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * Verifier protects target semantic hashes and language boundaries.
   */
  public function testVerifierRequiresExactTargetState(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/configuration-language-canvas-migration-781-verify.php';
    self::assertFileExists($path);
    $script = (string) file_get_contents($path);

    foreach ([
      'THIRTY_CANVAS_CANONICAL_PROMOTIONS_VERIFIED',
      "'verified' => \$verified",
      "'fr_overrides_verified' => \$verifiedOverrides",
      "'remaining_fr_review_required' => 39",
      "'target_semantic_hashes_verified' => TRUE",
      "'historical_versions_preserved_by_target_hash' => TRUE",
      "'sdc_values_preserved_by_target_hash' => TRUE",
      "'config_language_lock_enabled_canonically' => FALSE",
      "'system_site_default_langcode' => 'fr'",
      'a3beaff1a3ddb2a0e777f1f3ab48deed909ebfbbc138f9ea801fe9079f51ec52',
      'b9ee199468bfef78a6e500fdc83f2daa0dfdf41b97ec8132fc2a76a319964bde',
    ] as $expected) {
      self::assertStringContainsString($expected, $script);
    }
  }

  /**
   * Governed route is fixed to #781 and can only push the generated branch.
   */
  public function testGovernedWorkflowIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'governed-configuration-language-canvas-migration-781.yml';
    self::assertFileExists($path);
    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      'github.event.issue.number == 781',
      "'/agency-config-language-canvas-migration apply'",
      'test "$GITHUB_ACTOR" = \'E-merging-digital\'',
      'test "$EVENT_DEFAULT_SHA" = "$main_sha"',
      'persist-credentials: true',
      'contents: write',
      'configuration-language-canvas-migration-dry-run-779.php',
      'apply-configuration-language-canvas-migration-781.php',
      'configuration-language-canvas-migration-781-verify.php',
      'CANVAS_CANONICAL_MIGRATION_DRY_RUN_READY',
      'CANVAS_CANONICAL_MIGRATION_PATCH_PREPARED',
      'THIRTY_CANVAS_CANONICAL_PROMOTIONS_VERIFIED',
      'feature/781-apply-canvas-canonical-migration',
      'test "${#paths[@]}" -eq 45',
      'ddev drush site:install --existing-config',
      'ddev drush cim -y',
      "grep -Fq 'No differences'",
      'git push origin "HEAD:refs/heads/$branch"',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }

    foreach ([
      'workflow_dispatch:',
      'drush cex',
      'generateComponents(',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
      'git push origin main',
      'git push --force',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #779 Canvas migration dry-run.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageCanvasMigrationDryRun779Test extends TestCase {

  /**
   * The dry-run is fixed to the exact #766 cohort and admitted proofs.
   */
  public function testDryRunReusesExactCohortAndProofs(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/configuration-language-canvas-migration-dry-run-779.php';
    self::assertFileExists($path);
    $script = (string) file_get_contents($path);

    foreach ([
      'configuration-language-canvas-runtime-source-api-cohort-766.yml',
      'configuration-language-sdc-native-metadata-provenance-772.php',
      'configuration-language-canvas-block-native-label-provenance-774.php',
      'configuration-language-argumented-block-plain-text-provenance-776.php',
      'b2ad9dcff4b65e56e2a76efefc55b508cf4012b6aaa18cba0c4879cf2f3dec23',
      "'total' => 30",
      "'block' => 26",
      "'sdc' => 4",
      "'block_label_files_changed' => \$labelChangedFiles",
      "'fr_override_files_planned' => count(\$overridePlans)",
      'CANVAS_CANONICAL_MIGRATION_DRY_RUN_READY',
    ] as $expected) {
      self::assertStringContainsString($expected, $script);
    }
  }

  /**
   * The plan remains in-memory and preserves unproven values/history.
   */
  public function testDryRunIsReadOnlyAndFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/configuration-language-canvas-migration-dry-run-779.php';
    self::assertFileExists($path);
    $script = (string) file_get_contents($path);

    foreach ([
      "'read_only' => TRUE",
      "'in_memory_simulation_only' => TRUE",
      "'repository_config_mutated' => FALSE",
      "'historical_versions_rewritten' => FALSE",
      "'sdc_values_rewritten' => FALSE",
      "'sdc_native_value_presence_used_as_preservation_guard_only' => TRUE",
      "'block_labels_from_native_provenance_only' => TRUE",
      "'generic_normalization_used' => FALSE",
      "'natural_language_heuristic_used' => FALSE",
      "'automatic_arbitrary_text_translation_used' => FALSE",
      "'fuzzy_matching_used' => FALSE",
      "'migration_allowed_by_this_dry_run' => FALSE",
      "'en' => 496",
      "'fr' => 39",
    ] as $expected) {
      self::assertStringContainsString($expected, $script);
    }

    foreach ([
      '->generateComponents(',
      '->build(',
      '->save(',
      '->write(',
      '->delete(',
      'file_put_contents(',
      'Yaml::dump(',
      'drush cex',
      'html_entity_decode(',
      'str_replace(',
      'preg_replace(',
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
   * Trusted execution is fixed to #779 and requires READY evidence.
   */
  public function testTrustedWorkflowIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-canvas-migration-dry-run-779.yml';
    self::assertFileExists($path);
    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      'github.event.issue.number == 779',
      "'/agency-config-language-canvas-migration dry-run'",
      'test "$GITHUB_ACTOR" = \'E-merging-digital\'',
      'test "$EVENT_DEFAULT_SHA" = "$main_sha"',
      'persist-credentials: false',
      'ddev drush site:install --existing-config',
      'ddev drush cim -y',
      "grep -Fq 'No differences'",
      'CANVAS_CANONICAL_MIGRATION_DRY_RUN_READY',
      '.counts.base_files_planned == 30',
      '.counts.block_label_files_changed == 15',
      '.counts.fr_override_files_planned == 15',
      '.counts.review_required == 0',
      '"en":496',
      '"fr":39',
      '.constraints.repository_config_mutated == false',
      '.constraints.migration_allowed_by_this_dry_run == false',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'persist-credentials: true',
      'drush cex',
      'generateComponents(',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

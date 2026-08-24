<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #781 post-merge proof route.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageCanvasMigration781PostMergeWorkflowTest extends TestCase {

  /**
   * Post-merge proof is fixed to #781 and the exact #784 config authority.
   */
  public function testPostMergeProofIsBoundedToMergedMigrationAuthority(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-canvas-migration-781-post-merge-proof.yml';
    self::assertFileExists($path);
    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      'github.event.issue.number == 781',
      "'/agency-config-language-canvas-migration post-merge-prove'",
      'test "$GITHUB_ACTOR" = \'E-merging-digital\'',
      '53d049f50bc8c3e29d040cb97bb2fd4b896e5769',
      'git merge-base --is-ancestor',
      'git diff --name-only "$MIGRATION_MERGE_SHA" "$EXPECTED_MAIN_SHA" -- config/sync',
      'configuration-language-canvas-migration-781-verify.php',
      'THIRTY_CANVAS_CANONICAL_PROMOTIONS_VERIFIED',
      '8627ce9ec73a45f5501a410e15c4e56fcbc45239ee2c1c166e5d5445337e1a24',
      '.verified == 30 and .fr_overrides_verified == 15',
      '.remaining_fr_review_required == 39',
      '"en":496',
      '"fr":39',
      'persist-credentials: false',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }
  }

  /**
   * Proof validates target semantics and remains repository read-only.
   */
  public function testPostMergeProofRetainsReadOnlySafetyBoundaries(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-canvas-migration-781-post-merge-proof.yml';
    $workflow = (string) file_get_contents($path);

    foreach ([
      '.constraints.target_semantic_hashes_verified == true',
      '.constraints.historical_versions_preserved_by_target_hash == true',
      '.constraints.sdc_values_preserved_by_target_hash == true',
      '.constraints.config_language_lock_enabled_canonically == false',
      '.constraints.system_site_default_langcode == "fr"',
      'No differences',
      'test -z "$(git status --porcelain --untracked-files=all -- config/sync)"',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }

    foreach ([
      'drush cex',
      'generateComponents(',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
      'persist-credentials: true',
      'contents: write',
      'workflow_dispatch:',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

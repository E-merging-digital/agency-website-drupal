<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #776 argumented Block label provenance proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageArgumentedBlockLabel776Test extends TestCase {

  /**
   * The probe is fixed to exactly three argument-bearing Block labels.
   */
  public function testProbeIsFixedToExactThreeComponents(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-argumented-block-label-provenance-776.php';
    self::assertFileExists($path);
    $script = (string) file_get_contents($path);

    foreach ([
      'canvas.component.block.language_block.language_content',
      'canvas.component.block.language_block.language_interface',
      'canvas.component.block.views_block.content_recent-block_1',
      'ad40d7521d300bd8b77978997d3a290440e4aaa775179ec4009491e328a78f14',
    ] as $expected) {
      self::assertStringContainsString($expected, $script);
    }
    self::assertStringContainsString("'candidate_total' => 3", $script);
    self::assertStringContainsString(
      'ARGUMENTED_BLOCK_LABEL_PROVENANCE_ANALYZED',
      $script,
    );
  }

  /**
   * Argument provenance uses structured Drupal sources only.
   */
  public function testProbeUsesStructuredArgumentSourcesOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-argumented-block-label-provenance-776.php';
    self::assertFileExists($path);
    $script = (string) file_get_contents($path);

    self::assertStringContainsString(
      '$languageManager->getDefinedLanguageTypesInfo()',
      $script,
    );
    self::assertStringContainsString(
      "'views.view.'",
      $script,
    );
    self::assertStringContainsString(
      '$viewStorage->load($viewId)',
      $script,
    );
    self::assertStringContainsString(
      'new FormattableMarkup(',
      $script,
    );
    self::assertStringContainsString(
      "'language_rendering_only_via_drupal_translation_api' => TRUE",
      $script,
    );
    self::assertStringContainsString(
      "'natural_language_heuristic_used' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'automatic_arbitrary_text_translation_used' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'migration_allowed_by_this_proof' => FALSE",
      $script,
    );

    foreach ([
      '->generateComponents(',
      '->build(',
      '->save(',
      '->write(',
      '->delete(',
      'drush cex',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The trusted route is fixed to #776 and live main.
   */
  public function testTrustedWorkflowIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-argumented-block-label-776.yml';
    self::assertFileExists($path);
    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      'github.event.issue.number == 776',
      "'/agency-config-language-block-arguments correlate'",
      'test "$EVENT_DEFAULT_SHA" = "$main_sha"',
      'persist-credentials: false',
      'ddev drush site:install --existing-config',
      'ddev drush cim -y',
      "grep -Fq 'No differences'",
      'ARGUMENTED_BLOCK_LABEL_PROVENANCE_ANALYZED',
      '.counts.candidate_total == 3',
      '.counts.problem_count == 0',
      '.constraints.read_only == true',
      '.constraints.migration_allowed_by_this_proof == false',
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

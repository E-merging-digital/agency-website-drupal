<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the #776 safe-markup to plain-text provenance recovery.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageArgumentedBlockPlainText776Test extends TestCase {

  /**
   * The recovery reuses the exact three-item #776 source proof.
   */
  public function testRecoveryReusesExactSourceProvenance(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-argumented-block-plain-text-provenance-776.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    foreach ([
      'configuration-language-argumented-block-label-provenance-776.php',
      'ad40d7521d300bd8b77978997d3a290440e4aaa775179ec4009491e328a78f14',
      "'candidate_total' => 3",
      'ARGUMENTED_BLOCK_PLAIN_TEXT_PROVENANCE_ANALYZED',
    ] as $expected) {
      self::assertStringContainsString($expected, $script);
    }
  }

  /**
   * Safe markup is projected to config text through Drupal only.
   */
  public function testRecoveryUsesDrupalPlainTextOutputWithoutHeuristics(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-argumented-block-plain-text-provenance-776.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString(
      'Drupal\\Component\\Render\\PlainTextOutput',
      $script,
    );
    self::assertStringContainsString(
      'PlainTextOutput::renderFromHtml($frSafeMarkup)',
      $script,
    );
    self::assertStringContainsString(
      "'plain_text_projection_via_drupal_output_strategy' => TRUE",
      $script,
    );
    self::assertStringContainsString(
      "'generic_normalization_used' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'strict_equality_only' => TRUE",
      $script,
    );
    self::assertStringContainsString(
      "'migration_allowed_by_this_proof' => FALSE",
      $script,
    );

    foreach ([
      'html_entity_decode(',
      'str_replace(',
      'preg_replace(',
      'similar_text(',
      'levenshtein(',
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
   * Trusted recovery requires all three cases to become deterministic.
   */
  public function testTrustedRecoveryIsBoundedAndFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-argumented-block-plain-text-776.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    foreach ([
      'github.event.issue.number == 776',
      "'/agency-config-language-block-arguments correlate-plain-text'",
      'test "$EVENT_DEFAULT_SHA" = "$main_sha"',
      'persist-credentials: false',
      'ddev drush site:install --existing-config',
      'ddev drush cim -y',
      "grep -Fq 'No differences'",
      'ARGUMENTED_BLOCK_PLAIN_TEXT_PROVENANCE_ANALYZED',
      '.counts.deterministic_native_argument_provenance == 3',
      '.counts.review_required == 0',
      'Language switcher (Interface text)',
      'Sélecteur de langue (Texte d\\u0027interface)',
      '.constraints.generic_normalization_used == false',
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

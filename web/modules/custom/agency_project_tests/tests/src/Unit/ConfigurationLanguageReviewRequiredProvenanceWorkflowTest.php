<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #748 review-required provenance classification.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageReviewRequiredProvenanceWorkflowTest extends TestCase {

  /**
   * The gateway is fixed to issue #748, live main and read-only execution.
   */
  public function testGatewayIsFixedLiveMainAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-review-required-provenance.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.number == 748',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-review-required classify-provenance'",
      $workflow,
    );
    self::assertStringContainsString(
      'test "$GITHUB_ACTOR" = \'E-merging-digital\'',
      $workflow,
    );
    self::assertStringContainsString(
      'test "$EVENT_DEFAULT_SHA" = "$main_sha"',
      $workflow,
    );
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString(
      '.counts.candidate_fr_without_en_override == 140',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.schema_unresolved_review_required == 0',
      $workflow,
    );
    self::assertStringContainsString(
      'test -z "$(git status --short config/sync)"',
      $workflow,
    );

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'drush cex',
      'pm:enable config_language_lock',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'git push',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The analyzer uses typed data and exact extension-default provenance only.
   */
  public function testAnalyzerIsTypedStrictAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-review-required-provenance.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString("\\Drupal::service('config.typed')", $script);
    self::assertStringContainsString('TraversableTypedDataInterface', $script);
    self::assertStringContainsString("'translatable'", $script);
    self::assertStringContainsString('NestedArray::getValue(', $script);
    self::assertStringContainsString("'extension.list.module'", $script);
    self::assertStringContainsString("'extension.list.theme'", $script);
    self::assertStringContainsString("'extension.list.profile'", $script);
    self::assertStringContainsString("['install', 'optional']", $script);
    self::assertStringContainsString(
      "'no_material_translatable_source_candidate'",
      $script,
    );
    self::assertStringContainsString(
      "'english_default_exact_match_candidate'",
      $script,
    );
    self::assertStringContainsString("'material_review_required'", $script);
    self::assertStringContainsString(
      "'schema_unresolved_review_required'",
      $script,
    );
    self::assertStringContainsString(
      "'natural_language_heuristic_used' => FALSE",
      $script,
    );
    self::assertStringContainsString('count($candidates) !== 140', $script);
    self::assertStringContainsString(
      "'REVIEW_REQUIRED_PROVENANCE_CLASSIFIED'",
      $script,
    );

    foreach ([
      '->save(',
      '->write(',
      '->delete(',
      'config.storage.sync',
      'drush cex',
      'OPENAI_API_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

}

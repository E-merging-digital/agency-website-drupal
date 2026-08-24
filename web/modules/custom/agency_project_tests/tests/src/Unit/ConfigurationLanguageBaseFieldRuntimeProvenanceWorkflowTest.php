<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #756 runtime base-field provenance classification.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageBaseFieldRuntimeProvenanceWorkflowTest extends TestCase {

  /**
   * The gateway is fixed to #756, live main and read-only fresh DDEV.
   */
  public function testGatewayIsFixedLiveMainAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-base-field-runtime-provenance.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.number == 756',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-base-field-provenance classify'",
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
      '.counts.candidate_core_base_field_override_fr_without_en_override == 53',
      $workflow,
    );
    self::assertStringContainsString(
      'test -z "$(git status --short config/sync)"',
      $workflow,
    );
    self::assertStringContainsString(
      'ddev exec php -l',
      $workflow,
    );

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'php --version',
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
   * The analyzer uses untranslated runtime source values and exact equality.
   */
  public function testAnalyzerIsRuntimeSourceStrictAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-base-field-runtime-provenance.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString("\\Drupal::service('config.typed')", $script);
    self::assertStringContainsString(
      "\\Drupal::service('entity_field.manager')",
      $script,
    );
    self::assertStringContainsString(
      'getBaseFieldDefinitions($entityTypeId)',
      $script,
    );
    self::assertStringContainsString('getUntranslatedString()', $script);
    self::assertStringContainsString('NestedArray::getValue(', $script);
    self::assertStringContainsString('TraversableTypedDataInterface', $script);
    self::assertStringContainsString("'translatable'", $script);
    self::assertStringContainsString(
      "'runtime_base_definition_exact_match_candidate'",
      $script,
    );
    self::assertStringContainsString(
      "'runtime_base_definition_review_required'",
      $script,
    );
    self::assertStringContainsString(
      "'runtime_base_definition_unresolved_review_required'",
      $script,
    );
    self::assertStringContainsString(
      "'natural_language_heuristic_used' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'migration_authorized' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'BASE_FIELD_RUNTIME_PROVENANCE_CLASSIFIED'",
      $script,
    );
    self::assertStringContainsString(
      "$counts['candidate_core_base_field_override_fr_without_en_override'] === 53",
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

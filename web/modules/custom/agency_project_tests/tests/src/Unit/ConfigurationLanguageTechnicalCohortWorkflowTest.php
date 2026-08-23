<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the typed-config technical-cohort classification proof.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageTechnicalCohortWorkflowTest extends TestCase {

  /**
   * The route remains owner-bound, issue-bound and live-main-only.
   */
  public function testTrustedTechnicalGatewayIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/'
      . 'trusted-configuration-language-technical-no-override.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-technical classify'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'709\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$EVENT_DEFAULT_SHA" == "$main_sha" ]]',
      $workflow,
    );
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('- self-hosted', $workflow);
    self::assertStringContainsString('- agency', $workflow);
    self::assertStringContainsString('- ddev', $workflow);
    self::assertStringContainsString('site:install --existing-config', $workflow);
    self::assertStringContainsString('ddev drush cim -y', $workflow);
    self::assertStringContainsString('agency-config-technical-709-', $workflow);
    self::assertStringContainsString('gh issue comment 709', $workflow);

    foreach ([
      'workflow_dispatch:',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'composer require',
      'drush cex',
      'config:set',
      'pm:enable config_language_lock',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The runner enforces the exact 41-object baseline without mutation.
   */
  public function testTechnicalRunnerIsReadOnlyAndBaselineBound(): void {
    $root = dirname(DRUPAL_ROOT);
    $runnerPath = $root
      . '/scripts/runner/run-configuration-language-technical-no-override.sh';
    $runner = (string) file_get_contents($runnerPath);

    self::assertStringContainsString(
      '.counts.candidate_technical_fr_base_without_en_override == 41',
      $runner,
    );
    self::assertStringContainsString('.counts.classified == 41', $runner);
    self::assertStringContainsString('.counts.baseline_problem == 0', $runner);
    self::assertStringContainsString(
      '.baseline.expected_by_type.entity_form_display == 11',
      $runner,
    );
    self::assertStringContainsString(
      '.baseline.expected_by_type.entity_view_display == 10',
      $runner,
    );
    self::assertStringContainsString(
      '.baseline.expected_by_type.field_storage_config == 6',
      $runner,
    );
    self::assertStringContainsString(
      '.baseline.expected_by_type.language_content_settings == 14',
      $runner,
    );
    self::assertStringContainsString('config-status-before.txt', $runner);
    self::assertStringContainsString('config-status-after.txt', $runner);
    self::assertStringContainsString('git diff --exit-code -- config/sync', $runner);

    foreach ([
      'composer require',
      'drush cex',
      'drush cim',
      'drush en',
      'pm:enable',
      'config:set',
      'state:set',
    ] as $forbiddenMutation) {
      self::assertStringNotContainsString($forbiddenMutation, $runner);
    }

    $output = [];
    $status = 0;
    exec('bash -n ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
  }

  /**
   * The classifier decides from typed-config, never from linguistic heuristics.
   */
  public function testTechnicalClassifierUsesTypedConfigAndFailsClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $classifier = (string) file_get_contents(
      $root . '/scripts/runner/configuration-language-technical-no-override.php',
    );

    self::assertIsArray(token_get_all($classifier, TOKEN_PARSE));
    self::assertStringContainsString(
      "\\Drupal::service('config.typed')",
      $classifier,
    );
    self::assertStringContainsString('createFromNameAndData', $classifier);
    self::assertStringContainsString("['translatable']", $classifier);
    self::assertStringContainsString('entity_form_display', $classifier);
    self::assertStringContainsString('entity_view_display', $classifier);
    self::assertStringContainsString('field_storage_config', $classifier);
    self::assertStringContainsString('language_content_settings', $classifier);
    self::assertStringContainsString(
      'no_material_translatable_source_candidate',
      $classifier,
    );
    self::assertStringContainsString(
      'material_translatable_source_review_required',
      $classifier,
    );
    self::assertStringContainsString(
      'schema_unresolved_review_required',
      $classifier,
    );
    self::assertStringContainsString('REVIEW_REQUIRED', $classifier);
    self::assertStringContainsString('BASELINE_DRIFT', $classifier);
    self::assertStringContainsString(
      "'editorial_semantic_cohort_in_scope' => FALSE",
      $classifier,
    );

    foreach ([
      '->save(',
      '->delete(',
      'config:set',
      'drush cex',
      'drush cim',
      'config_language_lock.settings',
      'preg_match',
    ] as $forbiddenOperation) {
      self::assertStringNotContainsString($forbiddenOperation, $classifier);
    }
  }

  /**
   * The four cohort counts remain anchored to the approved baseline.
   */
  public function testTechnicalCountsRemainAnchoredToBaseline(): void {
    $root = dirname(DRUPAL_ROOT);
    $baseline = Yaml::parseFile(
      $root . '/docs/evidence/configuration-language-baseline-609.yml',
    );
    $byType = $baseline['migration_analysis']['fr_without_en_override_by_entity_type'] ?? [];

    self::assertSame(11, $byType['entity_form_display'] ?? NULL);
    self::assertSame(10, $byType['entity_view_display'] ?? NULL);
    self::assertSame(6, $byType['field_storage_config'] ?? NULL);
    self::assertSame(14, $byType['language_content_settings'] ?? NULL);
    self::assertSame('migration_required', $baseline['policy_status'] ?? NULL);
    self::assertFalse(
      $baseline['classification']['bulk_langcode_replacement_allowed'] ?? TRUE,
    );
  }

}

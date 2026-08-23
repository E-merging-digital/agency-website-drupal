<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the read-only EN translation-coverage proof.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageTranslationCoverageWorkflowTest extends TestCase {

  /**
   * The trusted route remains owner-bound, issue-bound and live-main-only.
   */
  public function testTrustedCoverageGatewayIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/'
      . 'trusted-configuration-language-translation-coverage.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-translation-coverage classify'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'705\' ]]',
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
    self::assertStringContainsString(
      'run-configuration-language-translation-coverage.sh',
      $workflow,
    );
    self::assertStringContainsString('site:install --existing-config', $workflow);
    self::assertStringContainsString('ddev drush cim -y', $workflow);

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('SSH_PRIVATE_KEY', $workflow);
    self::assertStringNotContainsString('SERVER_HOST', $workflow);
    self::assertStringNotContainsString('OPENAI_API_KEY', $workflow);
    self::assertStringNotContainsString('composer require', $workflow);
    self::assertStringNotContainsString('drush cex', $workflow);
    self::assertStringNotContainsString('config:set', $workflow);
    self::assertStringNotContainsString('pm:enable config_language_lock', $workflow);
  }

  /**
   * The runner proves read-only execution around the typed-config classifier.
   */
  public function testCoverageRunnerIsReadOnlyAndFailsClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $runnerPath = $root
      . '/scripts/runner/run-configuration-language-translation-coverage.sh';
    $runner = (string) file_get_contents($runnerPath);

    self::assertStringContainsString(
      'configuration-language-translation-coverage.php',
      $runner,
    );
    self::assertStringContainsString(
      '.counts.candidate_fr_base_with_en_override == 171',
      $runner,
    );
    self::assertStringContainsString('.counts.classified == 171', $runner);
    self::assertStringContainsString('.counts.baseline_problem == 0', $runner);
    self::assertStringContainsString(
      '.focus["webform.webform.contact"] != null',
      $runner,
    );
    self::assertStringContainsString('config-status-before.txt', $runner);
    self::assertStringContainsString('config-status-after.txt', $runner);
    self::assertStringContainsString('git diff --exit-code -- config/sync', $runner);
    self::assertStringContainsString(
      "grep -Fxq 'config_language_lock'",
      $runner,
    );

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
   * The classifier uses Drupal schema and never infers language from strings.
   */
  public function testClassifierUsesTypedConfigAndNeverMutates(): void {
    $root = dirname(DRUPAL_ROOT);
    $classifier = (string) file_get_contents(
      $root . '/scripts/runner/configuration-language-translation-coverage.php',
    );

    self::assertIsArray(token_get_all($classifier, TOKEN_PARSE));
    self::assertStringContainsString(
      "\\Drupal::service('config.typed')",
      $classifier,
    );
    self::assertStringContainsString(
      "\\Drupal::service('language.config_factory_override')",
      $classifier,
    );
    self::assertStringContainsString('createFromNameAndData', $classifier);
    self::assertStringContainsString('NestedArray::getValue', $classifier);
    self::assertStringContainsString("['translatable']", $classifier);
    self::assertStringContainsString(
      'en_override_complete_for_material_translatable_source',
      $classifier,
    );
    self::assertStringContainsString(
      'en_override_partial_review_required',
      $classifier,
    );
    self::assertStringContainsString(
      'schema_unresolved_review_required',
      $classifier,
    );
    self::assertStringContainsString(
      'en_override_redundant_or_source_equal',
      $classifier,
    );
    self::assertStringContainsString('webform.webform.contact', $classifier);
    self::assertStringContainsString('REVIEW_REQUIRED', $classifier);
    self::assertStringContainsString('BASELINE_DRIFT', $classifier);
    self::assertStringContainsString(
      "'configuration_migration_allowed_by_this_proof' => FALSE",
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
      'str_contains',
    ] as $forbiddenOperation) {
      self::assertStringNotContainsString($forbiddenOperation, $classifier);
    }
  }

  /**
   * The coverage proof stays anchored to the approved #609 baseline.
   */
  public function testCoverageBaselineRemainsMigrationRequired(): void {
    $root = dirname(DRUPAL_ROOT);
    $baseline = Yaml::parseFile(
      $root . '/docs/evidence/configuration-language-baseline-609.yml',
    );

    self::assertSame(
      'agency-config-language-609-pre-migration-v1',
      $baseline['baseline_id'] ?? NULL,
    );
    self::assertSame('migration_required', $baseline['policy_status'] ?? NULL);
    self::assertSame(
      171,
      $baseline['migration_analysis']['fr_base_with_en_override'] ?? NULL,
    );
    self::assertSame(
      181,
      $baseline['migration_analysis']['fr_base_without_en_override'] ?? NULL,
    );
    self::assertFalse(
      $baseline['classification']['bulk_langcode_replacement_allowed'] ?? TRUE,
    );
  }

}

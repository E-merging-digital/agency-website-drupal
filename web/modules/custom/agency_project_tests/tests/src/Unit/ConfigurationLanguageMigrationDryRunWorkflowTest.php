<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded configuration-language migration dry-run.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageMigrationDryRunWorkflowTest extends TestCase {

  /**
   * The issue-comment gateway remains owner-bound and live-main-only.
   */
  public function testTrustedMigrationGatewayIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-configuration-language-migration-dry-run.yml',
    );

    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-migration classify'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'684\' ]]',
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
      'run-configuration-language-migration-dry-run.sh',
      $workflow,
    );

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('SSH_PRIVATE_KEY', $workflow);
    self::assertStringNotContainsString('SERVER_HOST', $workflow);
    self::assertStringNotContainsString('OPENAI_API_KEY', $workflow);
    self::assertStringNotContainsString('composer require', $workflow);
    self::assertStringNotContainsString('drush cex', $workflow);
    self::assertStringNotContainsString('config:set', $workflow);
  }

  /**
   * The runner must reuse the proven snapshot and leave configuration intact.
   */
  public function testDryRunReusesAuditAndDoesNotMutateConfig(): void {
    $root = dirname(DRUPAL_ROOT);
    $runner = (string) file_get_contents(
      $root . '/scripts/runner/run-configuration-language-migration-dry-run.sh',
    );

    self::assertStringContainsString(
      'run-configuration-language-audit.sh',
      $runner,
    );
    self::assertStringContainsString(
      'configuration-language-migration-dry-run.php',
      $runner,
    );
    self::assertStringContainsString(
      '.counts.fr_base_without_en_override == 181',
      $runner,
    );
    self::assertStringContainsString(
      '.counts.classified_fr_without_en_override == 181',
      $runner,
    );
    self::assertStringContainsString('.counts.unknown == 0', $runner);
    self::assertStringContainsString('.special_invariants.pass == true', $runner);
    self::assertStringContainsString('git diff --exit-code -- config/sync', $runner);
    self::assertStringContainsString('drush config:status', $runner);

    foreach ([
      'composer require',
      'drush cex',
      'drush cim',
      'drush en',
      'config:set',
      'state:set',
    ] as $forbiddenMutation) {
      self::assertStringNotContainsString($forbiddenMutation, $runner);
    }
  }

  /**
   * Classification remains conservative and enforcement stays forbidden.
   */
  public function testClassifierFailsClosedAndProtectsSpecialLanguages(): void {
    $root = dirname(DRUPAL_ROOT);
    $classifier = (string) file_get_contents(
      $root . '/scripts/runner/configuration-language-migration-dry-run.php',
    );

    foreach ([
      "'entity_form_display' => TRUE",
      "'entity_view_display' => TRUE",
      "'field_storage_config' => TRUE",
      "'language_content_settings' => TRUE",
    ] as $technicalType) {
      self::assertStringContainsString($technicalType, $classifier);
    }

    self::assertStringContainsString(
      'editorial_or_semantic_review_required',
      $classifier,
    );
    self::assertStringContainsString('UNKNOWN_CLASSIFICATION', $classifier);
    self::assertStringContainsString('BASELINE_DRIFT', $classifier);
    self::assertStringContainsString('REVIEW_REQUIRED', $classifier);
    self::assertStringContainsString('language.entity.und', $classifier);
    self::assertStringContainsString('language.entity.zxx', $classifier);
    self::assertStringContainsString('system.menu.footer', $classifier);
    self::assertStringContainsString(
      "'bulk_langcode_replacement_allowed' => FALSE",
      $classifier,
    );
    self::assertStringContainsString(
      "'config_language_lock_activation_allowed_by_this_proof' => FALSE",
      $classifier,
    );

    foreach (['->save(', 'config.factory', 'drush cex', 'drush en'] as $mutation) {
      self::assertStringNotContainsString($mutation, $classifier);
    }
  }

  /**
   * The dry-run remains anchored to the durable #609 baseline.
   */
  public function testDryRunBaselineContractIsStillMigrationRequired(): void {
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

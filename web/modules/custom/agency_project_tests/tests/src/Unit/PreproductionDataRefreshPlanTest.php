<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the phase-1 PREPROD data-refresh PLAN boundary.
 *
 * @group agency_project_tests
 * @group preproduction_data_refresh
 */
final class PreproductionDataRefreshPlanTest extends TestCase {

  /**
   * The route is manual PLAN only and has no remote mutation credentials.
   */
  public function testWorkflowIsManualMetadataOnlyPlan(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString('workflow_dispatch:', $workflow);
    self::assertStringContainsString('runs-on: ubuntu-24.04', $workflow);
    self::assertStringNotContainsString("\n  push:\n", $workflow);
    self::assertStringNotContainsString("\n  pull_request:\n", $workflow);
    self::assertStringNotContainsString("\n  schedule:\n", $workflow);
    self::assertStringContainsString(
      'test "$GITHUB_REF" = \'refs/heads/main\'',
      $workflow,
    );
    self::assertStringNotContainsString('${{ secrets.', $workflow);

    foreach ([
      'ssh ',
      'scp ',
      'rsync ',
      'mariadb ',
      'mysql ',
      'drush ',
      'curl ',
      'wget ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * GitHub may receive only the fixed metadata PLAN evidence file.
   */
  public function testWorkflowCannotUploadRawProductionData(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString(
      'path: artifacts/preproduction-refresh-plan/plan.env',
      $workflow,
    );
    self::assertStringContainsString(
      'raw_prod_data_in_github=NONE',
      $workflow,
    );
    foreach ([
      '*.sql',
      '*.sql.gz',
      '*.dump',
      'shared/backups',
      'sites/default/files',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The script has no executable APPLY or data movement path.
   */
  public function testPlanScriptRejectsApplyAndContainsNoDataPath(): void {
    $script = $this->script();

    self::assertStringContainsString(
      'Only PLAN is implemented. APPLY is not authorized or executable',
      $script,
    );
    self::assertStringContainsString(
      'prod_write_path=NONE',
      $script,
    );
    self::assertStringContainsString(
      'real_prod_data_transfer=NONE',
      $script,
    );
    self::assertStringContainsString(
      'preprod_db_activation=NONE',
      $script,
    );
    self::assertStringContainsString(
      'apply_authority=NOT_AUTHORIZED',
      $script,
    );

    foreach ([
      'ssh ',
      'scp ',
      'rsync ',
      'mariadb-dump',
      'mysqldump',
      'sql:dump',
      'drush ',
      'curl ',
      'wget ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * Invalid identity, lock and capacity states fail closed.
   */
  public function testPlanScriptHasFailClosedPreconditions(): void {
    $script = $this->script();

    foreach ([
      'REPOSITORY_SHA is invalid.',
      'SOURCE_PROD_RELEASE_SHA is invalid.',
      'PREPROD_RELEASE_SHA is invalid.',
      'Refresh lock state is not FREE.',
      'PREPROD available capacity must be positive.',
      'Estimated staging size must be positive.',
      'Repository SHA does not match the checked-out PLAN source.',
      'CAPACITY_MULTIPLIER=3',
      'Declared PREPROD capacity is below the phase-1 safety multiplier.',
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }
  }

  /**
   * The policy covers all sensitive state required by #816.
   */
  public function testSanitizationPolicyIsCompleteAndApplyIsDisabled(): void {
    $policy = $this->policy();

    self::assertSame(1, $policy['schema_version']);
    self::assertSame(
      'agency-preprod-refresh-v1',
      $policy['policy_version'],
    );
    self::assertSame('PROD_READ_ONLY', $policy['scope']['source']);
    self::assertSame('NEVER', $policy['scope']['private_files']);
    self::assertFalse($policy['future_apply']['implemented']);
    self::assertTrue(
      $policy['future_apply']['owner_authorization_required'],
    );
    self::assertSame('NONE', $policy['future_apply']['prod_rollback_path']);
    self::assertFalse($policy['github_evidence']['raw_sql_allowed']);
    self::assertFalse($policy['github_evidence']['pii_allowed']);
    self::assertFalse($policy['github_evidence']['secrets_allowed']);

    $rules = [];
    foreach ($policy['mandatory_sanitization'] as $rule) {
      self::assertTrue($rule['required']);
      $rules[] = $rule['id'];
    }

    foreach ([
      'users',
      'preprod_admin',
      'webform_submissions',
      'sessions',
      'flood_rate_limit',
      'dblog_watchdog',
      'caches',
      'batch_temp_state',
      'queues',
      'cron_update_announcements_linkchecker_state',
      'one_time_auth_material',
      'persisted_credentials',
      'production_environment_state',
    ] as $required) {
      self::assertContains($required, $rules);
    }
  }

  /**
   * Raw PROD data may never execute on GitHub-hosted infrastructure.
   */
  public function testRawProdDataExecutionBoundaryIsFailClosed(): void {
    $policy = $this->policy();
    $boundary = $policy['execution_boundary'];

    self::assertFalse($boundary['github_hosted']['raw_prod_data_allowed']);
    self::assertContains(
      'NON_SENSITIVE_METADATA_VALIDATION',
      $boundary['github_hosted']['allowed_roles'],
    );
    self::assertSame(
      'FORBIDDEN',
      $boundary['raw_prod_data']['github_hosted_runner'],
    );

    $paths = [];
    foreach ($boundary['raw_prod_data']['allowed_paths'] as $path) {
      $paths[$path['type']] = $path;
    }

    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency'],
      $paths['TRUSTED_AGENCY_RUNNER']['required_labels'],
    );
    self::assertSame(
      'RAW_DATA_NEVER_TRANSITS_OR_MATERIALIZES_ON_GITHUB_HOSTED_INFRASTRUCTURE',
      $paths['CONTROLLED_SERVER_TO_SERVER']['requirement'],
    );

    $document = $this->document();
    foreach ([
      'RAW PROD DATA ON GITHUB-HOSTED RUNNER = FORBIDDEN',
      '`self-hosted`, `linux`, `x64`, `agency`',
      'controlled server-to-server path',
      'never materializes on GitHub-hosted infrastructure',
      'Metadata-only PLAN jobs may remain on `ubuntu-24.04`',
    ] as $required) {
      self::assertStringContainsString($required, $document);
    }
  }

  /**
   * All mandatory pre-activation assertions are versioned.
   */
  public function testPolicyContainsFailClosedActivationAssertions(): void {
    $assertions = $this->policy()['activation_assertions'];

    foreach ([
      'webform_submissions_zero',
      'active_sessions_zero',
      'production_mail_transport_inactive',
      'production_config_split_off',
      'preproduction_config_split_on',
      'google_tag_off',
      'provider_egress_off',
      'production_credentials_absent',
      'externally_acting_queues_cleared_or_explicitly_bounded',
      'staged_db_bootstrap_health_pass',
      'basic_auth_preserved',
      'noindex_preserved',
    ] as $required) {
      self::assertContains($required, $assertions);
    }
  }

  /**
   * Governed Content and rollback remain outside implicit refresh mutation.
   */
  public function testDocumentationDefinesFidelityAndRollbackBoundary(): void {
    $document = $this->document();

    foreach ([
      'must **not** automatically execute `emerging:governed-content --all`',
      'isolated staging database',
      'PROD is never part of rollback',
      'TERMINAL_REAL_PROOF = #953 / COMMITTED',
      'Private files are never copied by default',
    ] as $required) {
      self::assertStringContainsString($required, $document);
    }
  }

  /**
   * Returns and validates the manual PLAN workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/preproduction-data-refresh-plan.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

  /**
   * Returns the phase-1 PLAN script.
   */
  private function script(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/preproduction-refresh/plan.sh';
    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

  /**
   * Returns the decoded sanitization policy.
   *
   * @return array<string, mixed>
   *   The sanitization policy.
   */
  private function policy(): array {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/preproduction-refresh/sanitization-policy.json';
    self::assertFileExists($path);

    $policy = json_decode(
      (string) file_get_contents($path),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($policy);

    return $policy;
  }

  /**
   * Returns the durable data-refresh contract.
   */
  private function document(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/docs/operations/preproduction-data-refresh.md';
    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

}

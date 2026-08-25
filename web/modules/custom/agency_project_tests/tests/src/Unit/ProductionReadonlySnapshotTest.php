<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed read-only PROD snapshot boundary.
 *
 * @group agency_project_tests
 * @group production_readonly_snapshot
 */
final class ProductionReadonlySnapshotTest extends TestCase {

  /**
   * Raw production work is isolated from the GitHub-hosted gateway.
   */
  public function testGatewayAndTrustedRunnerBoundary(): void {
    $workflow = $this->workflow();
    $parsed = Yaml::parse($workflow);
    self::assertIsArray($parsed);

    self::assertSame(
      'ubuntu-24.04',
      $parsed['jobs']['validate-authority']['runs-on'],
    );
    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency'],
      $parsed['jobs']['snapshot']['runs-on'],
    );

    self::assertStringContainsString('issue_comment:', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString("\n  schedule:\n", $workflow);
    self::assertStringNotContainsString("\n  pull_request:\n", $workflow);
    self::assertStringContainsString(
      'Stream one fixed read-only PROD snapshot on trusted Agency runner',
      $workflow,
    );
  }

  /**
   * Snapshot authority is fresh, exact-main bound and non-replayable.
   */
  public function testAuthorityIsFreshAndBound(): void {
    $workflow = $this->workflow();

    foreach ([
      "github.event.issue.number == 826",
      "GITHUB_ACTOR\" == 'E-merging-digital'",
      "GITHUB_RUN_ATTEMPT\" == '1'",
      'request_id must be fresh',
      'Requested main SHA is stale.',
      'Snapshot workflow must execute tooling from live main.',
      'agency-prod-readonly-snapshot-v1',
      'profile_sha256',
      'AUTHORITY_COMMENT_ID',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringContainsString(
      '/agency-prod-readonly-snapshot prove',
      $workflow,
    );
    self::assertStringNotContainsString('command_input', $workflow);
    self::assertStringNotContainsString('sql_input', $workflow);
    self::assertStringNotContainsString('path_input', $workflow);
  }

  /**
   * The remote operation is one fixed, streaming, read-only dump operation.
   */
  public function testRemoteSnapshotOperationIsFixedAndReadOnly(): void {
    $remote = $this->remoteScript();

    foreach ([
      "if [[ \"$#\" -ne 1 ]]",
      "CURRENT_RELEASE='/var/www/agency/current'",
      'git -C "$CURRENT_RELEASE" rev-parse HEAD',
      'sql:connect --show-passwords',
      'mariadb-dump',
      'mysqldump',
      '--single-transaction',
      '--quick',
      '--skip-lock-tables',
      '--no-tablespaces',
    ] as $required) {
      self::assertStringContainsString($required, $remote);
    }

    foreach ([
      'state:set',
      'config:import',
      'config:set',
      'config:delete',
      ' updb',
      ' cim',
      'sql:query',
      'sql:drop',
      'sql:create',
      'user:create',
      'user:password',
      'maintenance_mode',
      'git checkout',
      'ln -sfn',
      'scp ',
      'rsync ',
      'PREPROD',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $remote);
    }
  }

  /**
   * Raw material is private, transient and cleaned on all supported exits.
   */
  public function testRawLifecycleIsPrivateAndFailClosed(): void {
    $script = $this->lifecycleScript();
    $finalizer = $this->finalizerScript();

    foreach ([
      'umask 077',
      'RUNNER_TEMP must be outside the repository workspace.',
      'agency-prod-readonly-snapshot-${GITHUB_RUN_ID}',
      'chmod 600 "$RAW_PATH"',
      "stat -c '%a' \"$RAW_PATH\"",
      'trap cleanup_and_finalize EXIT',
      "trap 'exit 129' HUP",
      "trap 'exit 130' INT",
      "trap 'exit 143' TERM",
      'rm -f -- "$RAW_PATH" "$REMOTE_STDERR_PATH"',
      "SNAPSHOT_CLEANUP='PASS'",
      "RAW_SNAPSHOT_PRESENT_AFTER_CLEANUP='NO'",
      'final_status=97',
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }

    self::assertStringNotContainsString(
      'agency-prod-readonly-snapshot-${REQUEST_ID}',
      $script,
    );
    self::assertStringContainsString('rm -f -- "$raw_path"', $finalizer);
    self::assertStringContainsString(
      'Trusted runner cleanup could not prove raw snapshot absence.',
      $finalizer,
    );
  }

  /**
   * GitHub evidence is exact-key metadata and can never contain raw SQL.
   */
  public function testEvidenceIsFixedAllowlistedMetadata(): void {
    $profile = $this->profile();
    $workflow = $this->workflow();

    $expected = [
      'schema_version',
      'request_id',
      'authority_comment_id',
      'authority_run_id',
      'repository_sha',
      'source_prod_release_sha',
      'operation_profile',
      'profile_sha256',
      'execution_mode',
      'snapshot_byte_size',
      'snapshot_sha256',
      'snapshot_created',
      'raw_material_mode',
      'snapshot_cleanup',
      'raw_snapshot_present_after_cleanup',
      'prod_write_path',
      'preprod_path',
      'raw_prod_artifact_in_github',
    ];

    self::assertSame($expected, $profile['evidence']['allowlist']);
    self::assertFalse($profile['evidence']['raw_prod_artifact_allowed']);
    self::assertFalse($profile['evidence']['pii_allowed']);
    self::assertFalse($profile['evidence']['secrets_allowed']);
    self::assertSame(
      'artifacts/prod-readonly-snapshot/evidence.env',
      $profile['evidence']['artifact_path'],
    );
    self::assertStringContainsString(
      'path: artifacts/prod-readonly-snapshot/evidence.env',
      $workflow,
    );

    foreach (['*.sql', '*.sql.gz', '*.dump', 'sites/default/files'] as $raw) {
      self::assertStringNotContainsString($raw, $workflow);
    }
  }

  /**
   * The reviewed profile fixes execution, semantics and stop boundaries.
   */
  public function testProfileIsBoundedToIssue826(): void {
    $profile = $this->profile();

    self::assertSame(1, $profile['schema_version']);
    self::assertSame('agency-prod-readonly-snapshot-v1', $profile['profile_id']);
    self::assertSame(826, $profile['issue_number']);
    self::assertSame(
      'FORBIDDEN',
      $profile['execution']['github_hosted_raw_prod_data'],
    );
    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency'],
      $profile['execution']['trusted_runner_labels'],
    );
    self::assertSame('NONE', $profile['execution']['prod_write_path']);
    self::assertSame('NONE', $profile['execution']['preprod_path']);
    self::assertSame('0600', $profile['snapshot']['raw_material_mode']);
    self::assertFalse($profile['snapshot']['remote_raw_materialization']);
    self::assertTrue($profile['cleanup']['exit_trap_required']);
    self::assertTrue($profile['cleanup']['workflow_finalizer_required']);
    self::assertTrue($profile['cleanup']['cleanup_failure_is_terminal']);
    self::assertSame(
      'HUMAN_REQUIRED_AFTER_PROJECT_LEAD_REVIEW',
      $profile['authority']['first_real_snapshot'],
    );
  }

  /**
   * No PREPROD/full-refresh or production mutation route is introduced.
   */
  public function testNoImplicitApplyOrPreprodPath(): void {
    $workflow = $this->workflow();
    $script = $this->lifecycleScript();

    foreach ([
      'drush updb',
      'drush cim',
      'state:set',
      'config:import',
      'deploy-production',
      'deploy-preproduction',
      'sql:sanitize',
      'APPLY',
      'scp ',
      'rsync ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
      self::assertStringNotContainsString($forbidden, $script);
    }

    self::assertStringContainsString('prod_write_path=NONE', $script);
    self::assertStringContainsString('preprod_path=NONE', $script);
  }

  /**
   * The #816 GitHub-hosted raw-data prohibition remains authoritative.
   */
  public function testInherited816ExecutionBoundaryStillHolds(): void {
    $policy = $this->refreshPolicy();
    $boundary = $policy['execution_boundary'];

    self::assertFalse($boundary['github_hosted']['raw_prod_data_allowed']);
    self::assertSame(
      'FORBIDDEN',
      $boundary['raw_prod_data']['github_hosted_runner'],
    );
    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency'],
      $boundary['raw_prod_data']['allowed_paths'][0]['required_labels'],
    );

    self::assertStringContainsString(
      'RAW PROD DATA ON GITHUB-HOSTED RUNNER = FORBIDDEN',
      $this->document(),
    );
  }

  /**
   * Synthetic validation proves success, failure cleanup and anti-replay.
   */
  public function testTargetedValidationCoversRequiredSyntheticProofs(): void {
    $validation = $this->validationWorkflow();

    foreach ([
      'Prove synthetic success metadata, mode and cleanup',
      'AGENCY_SNAPSHOT_SYNTHETIC_FAIL_AFTER_WRITE=1',
      'snapshot_created=FAIL',
      'snapshot_cleanup=PASS',
      'raw_snapshot_present_after_cleanup=NO',
      'GITHUB_RUN_ATTEMPT=2',
      'Synthetic failure path unexpectedly succeeded.',
      'Snapshot proof unexpectedly accepted a replay attempt.',
    ] as $required) {
      self::assertStringContainsString($required, $validation);
    }
  }

  /**
   * Returns the governed snapshot workflow.
   */
  private function workflow(): string {
    return $this->file(
      '.github/workflows/prod-readonly-snapshot.yml',
      TRUE,
    );
  }

  /**
   * Returns the targeted validation workflow.
   */
  private function validationWorkflow(): string {
    return $this->file(
      '.github/workflows/prod-readonly-snapshot-validation.yml',
      TRUE,
    );
  }

  /**
   * Returns the raw lifecycle script.
   */
  private function lifecycleScript(): string {
    return $this->file('scripts/production-readonly-snapshot/run-snapshot.sh');
  }

  /**
   * Returns the independent cleanup finalizer.
   */
  private function finalizerScript(): string {
    return $this->file(
      'scripts/production-readonly-snapshot/finalize-cleanup.sh',
    );
  }

  /**
   * Returns the fixed remote stream operation.
   */
  private function remoteScript(): string {
    return $this->file(
      'scripts/production-readonly-snapshot/remote-stream.sh',
    );
  }

  /**
   * Returns the decoded snapshot profile.
   *
   * @return array<string, mixed>
   *   The profile.
   */
  private function profile(): array {
    $json = $this->file('scripts/production-readonly-snapshot/profile.json');
    $profile = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($profile);
    return $profile;
  }

  /**
   * Returns the inherited #816 refresh policy.
   *
   * @return array<string, mixed>
   *   The policy.
   */
  private function refreshPolicy(): array {
    $json = $this->file(
      'scripts/preproduction-refresh/sanitization-policy.json',
    );
    $policy = json_decode($json, TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($policy);
    return $policy;
  }

  /**
   * Returns the durable snapshot contract.
   */
  private function document(): string {
    return $this->file('docs/operations/production-readonly-snapshot.md');
  }

  /**
   * Reads a repository file and optionally validates YAML syntax.
   */
  private function file(string $relative, bool $yaml = FALSE): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/' . $relative;
    self::assertFileExists($path);
    if ($yaml) {
      self::assertIsArray(Yaml::parseFile($path));
    }
    return (string) file_get_contents($path);
  }

}

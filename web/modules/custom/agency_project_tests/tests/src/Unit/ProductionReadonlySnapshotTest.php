<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the current read-only PROD snapshot boundary consumed by #914.
 *
 * @group agency_project_tests
 * @group production_readonly_snapshot
 */
final class ProductionReadonlySnapshotTest extends TestCase {

  /**
   * The current refresh route keeps raw PROD work on the trusted runner.
   */
  public function testCurrentTrustedRunnerBoundary(): void {
    $workflow = $this->workflow();
    $parsed = Yaml::parse($workflow);
    self::assertIsArray($parsed);

    self::assertArrayHasKey('workflow_call', $parsed['on']);
    self::assertArrayNotHasKey('issue_comment', $parsed['on']);
    self::assertSame(
      'ubuntu-24.04',
      $parsed['jobs']['validate-authority']['runs-on'],
    );
    foreach (['plan', 'apply'] as $job) {
      self::assertSame(
        ['self-hosted', 'linux', 'x64', 'agency'],
        $parsed['jobs'][$job]['runs-on'],
      );
    }
    self::assertStringNotContainsString(
      'actions/upload-artifact',
      $workflow,
    );
  }

  /**
   * Runtime identity comes from the durable promotion receipt, not Git.
   */
  public function testRuntimeIdentityUsesExactPromotionReceipt(): void {
    $remote = $this->remoteScript();

    foreach ([
      "PROJECT_ROOT='/var/www/agency'",
      'CURRENT_RELEASE="$PROJECT_ROOT/current"',
      'PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"',
      'current_release="$(readlink -f "$CURRENT_RELEASE")"',
      "'^release_path='",
      "'^candidate_sha='",
      'matched_receipts',
      'Current PROD release must map to exactly one promotion receipt.',
      'Current PROD release identity does not match authorization.',
    ] as $required) {
      self::assertStringContainsString($required, $remote);
    }

    self::assertStringNotContainsString(
      'git -C "$CURRENT_RELEASE" rev-parse HEAD',
      $remote,
    );
  }

  /**
   * The remote operation remains one fixed, streaming, read-only dump.
   */
  public function testRemoteSnapshotOperationIsFixedAndReadOnly(): void {
    $remote = $this->remoteScript();

    foreach ([
      'if [[ "$#" -ne 1 ]]',
      'vendor/bin/drush sql:dump',
      '--no-interaction',
      '--single-transaction',
      '--quick',
      '--skip-lock-tables',
      '--no-tablespaces',
    ] as $required) {
      self::assertStringContainsString($required, $remote);
    }

    foreach ([
      'sql:connect',
      '--result-file',
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
   * Issue #914 verifies PROD trust before snapshot and sanitizes before transfer.
   */
  public function testCurrentApplySanitizesBeforePreprodTransfer(): void {
    $apply = $this->applyScript();

    foreach ([
      "PROD_REMOTE='scripts/production-readonly-snapshot/remote-stream.sh'",
      "PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'",
      'docker network create --internal',
      '< "$PROD_REMOTE" > "$raw"',
      'ddev import-db --file="$raw"',
      'ddev drush sql:sanitize',
      'rm -f -- "$raw"',
      'SANITIZED_ONLY_TO_PREPROD=PASS',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    $trust = strpos($apply, 'bash "$PROD_TRUST" VERIFY_ONLY');
    $snapshot = strpos($apply, '< "$PROD_REMOTE" > "$raw"');
    $import = strpos($apply, 'ddev import-db --file="$raw"');
    $sanitize = strpos($apply, 'ddev drush sql:sanitize');
    $transfer = strpos($apply, 'scp -q');

    self::assertIsInt($trust);
    self::assertIsInt($snapshot);
    self::assertIsInt($import);
    self::assertIsInt($sanitize);
    self::assertIsInt($transfer);
    self::assertLessThan($snapshot, $trust);
    self::assertLessThan($import, $snapshot);
    self::assertLessThan($sanitize, $import);
    self::assertLessThan($transfer, $sanitize);
  }

  /**
   * The inherited #816 raw-data execution boundary remains authoritative.
   */
  public function testInherited816ExecutionBoundaryStillHolds(): void {
    $policy = $this->refreshPolicy();
    $boundary = $policy['execution_boundary'];

    self::assertFalse(
      $boundary['github_hosted']['raw_prod_data_allowed'],
    );
    self::assertSame(
      'FORBIDDEN',
      $boundary['raw_prod_data']['github_hosted_runner'],
    );
    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency'],
      $boundary['raw_prod_data']['allowed_paths'][0]['required_labels'],
    );
  }

  /**
   * The targeted validator now proves the current shared primitive.
   */
  public function testTargetedValidationCoversCurrentPrimitive(): void {
    $validation = $this->validationWorkflow();

    foreach ([
      'CURRENT_PROD_SNAPSHOT_PRIMITIVE=PASS',
      'PROD_WRITE=NONE',
      'RAW_PROD_GITHUB_ARTIFACT=NONE',
      'RAW_BEFORE_SANITIZE=TRUSTED_INTERNAL_STAGING_ONLY',
    ] as $required) {
      self::assertStringContainsString($required, $validation);
    }
    self::assertStringContainsString(
      '.github/workflows/preprod-914-governed-successor.yml',
      $validation,
    );
  }

  /**
   * Returns the active #914 refresh workflow.
   */
  private function workflow(): string {
    return $this->file(
      '.github/workflows/preprod-914-governed-successor.yml',
      TRUE,
    );
  }

  /**
   * Returns the current targeted snapshot validation workflow.
   */
  private function validationWorkflow(): string {
    return $this->file(
      '.github/workflows/prod-readonly-snapshot-validation.yml',
      TRUE,
    );
  }

  /**
   * Returns the current #914 trusted-runner APPLY implementation.
   */
  private function applyScript(): string {
    return $this->file(
      'scripts/preproduction-refresh/governed-successor/run-apply.sh',
    );
  }

  /**
   * Returns the fixed remote read-only snapshot operation.
   */
  private function remoteScript(): string {
    return $this->file(
      'scripts/production-readonly-snapshot/remote-stream.sh',
    );
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
   * Reads one repository file and optionally validates YAML syntax.
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

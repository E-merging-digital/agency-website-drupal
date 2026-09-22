<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded #1300 post-reboot recovery capability.
 *
 * @group agency_project_tests
 */
final class ProdOsMaintenance1300RecoveryTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const APPLY_WORKFLOW = '.github/workflows/prod-os-maintenance-1183.yml';
  private const RECOVERY_WORKFLOW =
    '.github/workflows/prod-os-maintenance-1183-recovery.yml';
  private const RECOVERY =
    'scripts/production-maintenance-1183/remote-post-reboot-recovery.sh';
  private const CLAIM =
    'scripts/production-maintenance-1183/claim-one-shot-recovery.sh';
  private const CLAIM_TEST =
    'scripts/production-maintenance-1183/tests/test_one_shot_recovery_claim.py';
  private const READINESS =
    'scripts/production-maintenance-1183/stable-ssh-readiness.sh';
  private const READINESS_TEST =
    'scripts/production-maintenance-1183/tests/test_stable_ssh_readiness.py';
  private const MAINTENANCE_TEST =
    'scripts/production-maintenance-1183/tests/test_recovery_maintenance_reopen.py';

  /**
   * Dispatcher recovery route remains direct-OWNER and claim-gated.
   */
  public function testDispatcherRequiresAtomicRecoveryClaim(): void {
    $dispatcher = $this->source(self::DISPATCHER);
    foreach ([
      'prod-os-maintenance-1300-recovery-claim:',
      'claim-one-shot-recovery.sh',
      'github.event.issue.number == 1300',
      "github.event.comment.author_association == 'OWNER'",
      "github.event.comment.user.login == 'E-merging-digital'",
      "startsWith(github.event.comment.body, '/agency-prod-os-maintenance-1183 recover-post-reboot ')",
      'prod-os-maintenance-1300-recovery:',
      'prod-os-maintenance-1183-recovery.yml',
    ] as $required) {
      self::assertStringContainsString($required, $dispatcher);
    }
    $this->parsed(self::DISPATCHER);
  }

  /**
   * Recovery is pinned to immutable incident evidence and stable SSH.
   */
  public function testRecoveryWorkflowIsIncidentBoundAndFailClosed(): void {
    $workflow = $this->source(self::RECOVERY_WORKFLOW);
    foreach ([
      'incident_run=(35602886717)',
      'plan_run=(35600328878)',
      '07a672088469959e01863450acd8b5078cd5efc2041dda5545e4bb986635744b',
      '10639950744',
      'sha256:b34390409fa699d39b064a6fd198cef14d264a48ae529477069469ae8dce6c18',
      'stable-ssh-readiness.sh',
      '3 60 5 -- ssh',
      'Never retry this SSH command if transport status is ambiguous',
      'remote-post-reboot-recovery.sh',
      '.REBOOT_PERFORMED_BY_RECOVERY == "NO"',
      '.REAL_PACKAGE_MUTATION == "NONE"',
      '.APT == "NONE"',
      '.SNAPSHOT_RESTORE == "NONE"',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
    $this->parsed(self::RECOVERY_WORKFLOW);
  }

  /**
   * Remote recovery cannot invoke package mutation or another reboot.
   */
  public function testRemoteRecoveryHasNoPackageOrRebootPrimitive(): void {
    $recovery = $this->source(self::RECOVERY);
    foreach ([
      'POST_REBOOT_RECOVERY',
      "'APT': 'NONE'",
      "'REAL_PACKAGE_MUTATION': 'NONE'",
      "'REBOOT_PERFORMED_BY_RECOVERY': 'NO'",
      "'CONFIG_AUTO_CORRECTION': 'NONE'",
      "'SNAPSHOT_RESTORE': 'NONE'",
      "'KEEP_PREVIOUS_KERNEL': 'YES'",
      "'VERSION_LOG_REQUIRED': 'YES'",
    ] as $required) {
      self::assertStringContainsString($required, $recovery);
    }
    foreach ([
      'apt-get ',
      'apt install',
      'apt upgrade',
      'systemctl reboot',
      '/usr/local/sbin/agency-prod-os-maintenance-1183-reboot',
      'drush cim',
      'config:import',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $recovery);
    }
  }

  /**
   * Existing APPLY path now requires stable consecutive SSH sessions.
   */
  public function testConsumedApplyPathUsesStableReadinessBoundary(): void {
    $workflow = $this->source(self::APPLY_WORKFLOW);
    self::assertStringContainsString(
      'scripts/production-maintenance-1183/stable-ssh-readiness.sh',
      $workflow,
    );
    self::assertStringContainsString('3 60 5 -- ssh', $workflow);
    self::assertStringNotContainsString('reachable=0', $workflow);
  }

  /**
   * Focused harnesses prove claim, readiness and maintenance semantics.
   */
  public function testFocusedRecoveryHarnesses(): void {
    $claim = $this->runCommand([
      'python3',
      self::CLAIM_TEST,
      self::CLAIM,
    ]);
    self::assertStringContainsString(
      'ONE_SHOT_RECOVERY_CLAIM_HARNESS=PASS',
      $claim,
    );

    $readiness = $this->runCommand(['python3', self::READINESS_TEST]);
    foreach ([
      'TRANSIENT_SINGLE_SUCCESS_NOT_SUFFICIENT=PASS',
      'STABLE_CONSECUTIVE_READINESS=PASS',
      'READINESS_TIMEOUT_FAIL_CLOSED=PASS',
    ] as $required) {
      self::assertStringContainsString($required, $readiness);
    }

    $maintenance = $this->runCommand(['python3', self::MAINTENANCE_TEST]);
    foreach ([
      'MAINTENANCE_1_TO_0=PASS',
      'MAINTENANCE_0_NO_TOGGLE=PASS',
      'MAINTENANCE_UNKNOWN_FAIL_CLOSED=PASS',
    ] as $required) {
      self::assertStringContainsString($required, $maintenance);
    }
  }

  /**
   * Executes a bounded repository-local command.
   */
  private function runCommand(array $arguments): string {
    $root = dirname(DRUPAL_ROOT);
    $command = [];
    foreach ($arguments as $argument) {
      $path = str_contains($argument, '/')
        ? $root . '/' . $argument
        : $argument;
      $command[] = escapeshellarg($path);
    }
    $output = [];
    $status = 1;
    exec(implode(' ', $command) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
    return implode("\n", $output);
  }

  /**
   * Parses one repository YAML file.
   */
  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

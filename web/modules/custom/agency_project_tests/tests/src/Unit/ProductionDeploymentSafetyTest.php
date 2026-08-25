<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the explicit emergency production deployment lane.
 *
 * @group agency_project_tests
 * @group production_deploy
 */
final class ProductionDeploymentSafetyTest extends TestCase {

  /**
   * Ordinary pushes to main can no longer deploy production.
   */
  public function testWorkflowIsManualAndEmergencyRefOnly(): void {
    $workflow = $this->deploymentWorkflow();

    self::assertStringContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString("\n  push:\n", $workflow);
    self::assertStringContainsString('hotfix/*|security/*', $workflow);
    self::assertStringContainsString(
      'Emergency production deploy is restricted to hotfix/* or security/* refs.',
      $workflow,
    );
    self::assertStringContainsString(
      'group: agency-production-deploy',
      $workflow,
    );
    self::assertStringContainsString('cancel-in-progress: false', $workflow);
    self::assertStringContainsString('ref: ${{ github.sha }}', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringNotContainsString('branches:', $workflow);
  }

  /**
   * Emergency requests keep exact-SHA detached execution and branch identity.
   */
  public function testWorkflowStagesAndPollsBoundedEmergencyRequest(): void {
    $workflow = $this->deploymentWorkflow();

    foreach ([
      'Stage exact detached emergency deployment request',
      'scripts/deploy-production.sh',
      'scripts/launch-production-deploy.sh',
      'scripts/inspect-production-deploy.sh',
      'Launch detached emergency production worker',
      "'$REMOTE_DIR/launch-production-deploy.sh' '$REQUEST_ID' '$EXPECTED_SHA' '$TARGET_BRANCH'",
      'Poll detached emergency production result',
      'agency-production-emergency-${{ github.sha }}-${{ github.run_id }}',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('git pull', $workflow);
    self::assertStringNotContainsString(
      'cd /var/www/agency/current',
      $workflow,
    );
  }

  /**
   * The launcher refuses non-emergency refs and owns the server deploy lock.
   */
  public function testLauncherBindsWorkerToEmergencyRefAndLock(): void {
    $launcher = $this->script('scripts/launch-production-deploy.sh');

    foreach ([
      'TARGET_BRANCH="${3:-}"',
      'hotfix/*|security/*',
      'LOCK_FILE="/var/www/agency/shared/deploy.lock"',
      'flock -n 9',
      'write_result LOCKED 75',
      'write_result SUCCESS 0',
      'write_result FAILURE "$deploy_exit"',
      'nohup setsid --wait',
      '"$DEPLOY_SCRIPT" "$TARGET_BRANCH" "$EXPECTED_SHA"',
    ] as $required) {
      self::assertStringContainsString($required, $launcher);
    }

    self::assertStringNotContainsString(
      '"$DEPLOY_SCRIPT" main "$EXPECTED_SHA"',
      $launcher,
    );
    self::assertStringNotContainsString('drush ', $launcher);
  }

  /**
   * The legacy emergency script still pins the exact requested commit.
   */
  public function testEmergencyScriptPinsExactCommitAndKeepsCurrentImmutable(): void {
    $script = $this->script('scripts/deploy-production.sh');

    self::assertStringContainsString('BRANCH="${1:-main}"', $script);
    self::assertStringContainsString('EXPECTED_SHA="${2:-}"', $script);
    self::assertStringContainsString(
      'git -C "$NEW_RELEASE" checkout --detach "$EXPECTED_SHA"',
      $script,
    );
    self::assertStringContainsString(
      'if [[ "$GIT_COMMIT" != "$EXPECTED_SHA" ]]',
      $script,
    );
    self::assertStringNotContainsString('git pull', $script);
    self::assertStringNotContainsString(
      'git -C "$CURRENT_LINK" checkout',
      $script,
    );
    self::assertStringNotContainsString(
      'git -C "$CURRENT_LINK" reset',
      $script,
    );
  }

  /**
   * Maintenance recovery stays explicit across the emergency release switch.
   */
  public function testEmergencyMaintenanceRecoveryRemainsFailSafe(): void {
    $script = $this->script('scripts/deploy-production.sh');

    self::assertStringContainsString('SWITCH_COMPLETED=0', $script);
    self::assertStringContainsString('SWITCH_COMPLETED=1', $script);
    self::assertStringContainsString(
      'vendor/bin/drush sql:query \'SELECT 1\' >/dev/null',
      $script,
    );
    self::assertStringContainsString(
      'automatic database rollback is not attempted.',
      $script,
    );

    $maintenanceStart = strpos($script, 'log "[deploy] Maintenance ON"');
    self::assertIsInt($maintenanceStart);
    $maintenanceBlock = substr($script, $maintenanceStart, 500);
    $stateOn = strpos(
      $maintenanceBlock,
      'vendor/bin/drush state:set system.maintenance_mode 1',
    );
    $armed = strpos($maintenanceBlock, 'MAINTENANCE_ENABLED=1');
    self::assertIsInt($stateOn);
    self::assertIsInt($armed);
    self::assertLessThan($armed, $stateOn);
  }

  /**
   * Polling remains read-only and classifies lost workers fail-closed.
   */
  public function testInspectorIsReadOnlyAndFailClosed(): void {
    $inspector = $this->script('scripts/inspect-production-deploy.sh');

    foreach ([
      'outcome=NOT_STARTED',
      'outcome=STARTING',
      'outcome=RUNNING',
      'outcome=LOST',
      'outcome=INVALID',
      'worker_exited_without_result',
      'worker_pid_reused',
      'result_request_mismatch',
      'result_sha_mismatch',
    ] as $required) {
      self::assertStringContainsString($required, $inspector);
    }

    foreach ([
      'drush ',
      'git pull',
      'git checkout',
      'git reset',
      'composer ',
      'systemctl ',
      'sudo ',
      'rm -',
      'mv ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $inspector);
    }
  }

  /**
   * Returns and validates the emergency production workflow.
   */
  private function deploymentWorkflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/deploy-production.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

  /**
   * Returns a repository-owned deployment script.
   */
  private function script(string $relativePath): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/' . $relativePath;

    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

}

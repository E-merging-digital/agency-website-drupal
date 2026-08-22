<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects production deployment atomicity and maintenance recovery.
 *
 * @group agency_project_tests
 * @group production_deploy
 */
final class ProductionDeploymentSafetyTest extends TestCase {

  /**
   * Deploys the exact SHA through a detached, observable server request.
   */
  public function testWorkflowStagesDetachedDeployAndPollsItsResult(): void {
    $workflow = $this->deploymentWorkflow();

    self::assertStringContainsString(
      'group: agency-production-deploy',
      $workflow,
    );
    self::assertStringContainsString('cancel-in-progress: false', $workflow);
    self::assertStringContainsString('timeout-minutes: 35', $workflow);
    self::assertStringContainsString('uses: actions/checkout@v6', $workflow);
    self::assertStringContainsString('ref: ${{ github.sha }}', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString(
      'EXPECTED_SHA: ${{ github.sha }}',
      $workflow,
    );

    foreach ([
      'Stage exact detached deployment request',
      'scripts/deploy-production.sh',
      'scripts/launch-production-deploy.sh',
      'scripts/inspect-production-deploy.sh',
      'Launch detached production worker',
      'Launch transport exit was $launch_status; detached result polling remains authoritative.',
      'Poll detached production result',
      'outcome=LOST',
      'outcome=TIMEOUT',
      'agency-production-deploy-${{ github.run_id }}-${{ github.run_attempt }}',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString(
      'bash -s -- main "$EXPECTED_SHA" < scripts/deploy-production.sh',
      $workflow,
    );
    self::assertStringNotContainsString('git pull', $workflow);
    self::assertStringNotContainsString(
      'cd /var/www/agency/current',
      $workflow,
    );
  }

  /**
   * The launcher must detach the worker and own a server-side deploy lock.
   */
  public function testLauncherDetachesWorkerAndPublishesAtomicResult(): void {
    $launcher = $this->script('scripts/launch-production-deploy.sh');

    foreach ([
      'JOBS_DIR="/var/www/agency/shared/deploy-jobs"',
      'LOCK_FILE="/var/www/agency/shared/deploy.lock"',
      'result.env',
      'worker.sh',
      'flock -n 9',
      'write_result LOCKED 75',
      'write_result SUCCESS 0',
      'write_result FAILURE "$deploy_exit"',
      'mv -f "$result_tmp" "$RESULT_FILE"',
      'nohup setsid --wait',
      '</dev/null >> "$BOOTSTRAP_LOG" 2>&1 &',
      '"$DEPLOY_SCRIPT" main "$EXPECTED_SHA"',
    ] as $required) {
      self::assertStringContainsString($required, $launcher);
    }

    $lock = strpos($launcher, 'flock -n 9');
    $deploy = strpos($launcher, '"$DEPLOY_SCRIPT" main "$EXPECTED_SHA"');
    self::assertIsInt($lock);
    self::assertIsInt($deploy);
    self::assertLessThan($deploy, $lock);

    self::assertStringNotContainsString('drush ', $launcher);
    self::assertStringNotContainsString('git pull', $launcher);
  }

  /**
   * Polling must remain read-only and classify lost workers fail-closed.
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
      'kill -0 "$worker_pid"',
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
   * The server script must materialize and verify the exact requested commit.
   */
  public function testScriptPinsExactCommitAndKeepsCurrentImmutable(): void {
    $script = $this->script('scripts/deploy-production.sh');

    self::assertStringContainsString('EXPECTED_SHA="${2:-}"', $script);
    self::assertStringContainsString(
      '[[ ! "$EXPECTED_SHA" =~ ^[0-9a-f]{40}$ ]]',
      $script,
    );
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
   * Maintenance recovery must be armed immediately and use the old release.
   */
  public function testMaintenanceIsFailSafeAcrossReleaseSwitch(): void {
    $script = $this->script('scripts/deploy-production.sh');

    self::assertStringContainsString(
      'vendor/bin/drush sql:query \'SELECT 1\' >/dev/null',
      $script,
    );
    self::assertStringContainsString('SWITCH_COMPLETED=0', $script);
    self::assertStringContainsString('SWITCH_COMPLETED=1', $script);

    $maintenanceStart = strpos($script, 'log "[deploy] Maintenance ON"');
    self::assertIsInt($maintenanceStart);
    $maintenanceBlock = substr($script, $maintenanceStart, 500);

    $stateOn = strpos(
      $maintenanceBlock,
      'vendor/bin/drush state:set system.maintenance_mode 1',
    );
    $armed = strpos($maintenanceBlock, 'MAINTENANCE_ENABLED=1');
    $cacheRebuild = strpos($maintenanceBlock, 'vendor/bin/drush cr');

    self::assertIsInt($stateOn);
    self::assertIsInt($armed);
    self::assertIsInt($cacheRebuild);
    self::assertLessThan($armed, $stateOn);
    self::assertLessThan($cacheRebuild, $armed);

    $trapStart = strpos($script, 'fail_trap() {');
    $trapEnd = strpos($script, "\n}\n\ntrap ", $trapStart);
    self::assertIsInt($trapStart);
    self::assertIsInt($trapEnd);
    $trap = substr($script, $trapStart, $trapEnd - $trapStart);

    self::assertStringContainsString(
      '$ACTIVE_RELEASE/vendor/bin/drush',
      $trap,
    );
    self::assertStringNotContainsString(
      '$CURRENT_LINK/vendor/bin/drush',
      $trap,
    );
    self::assertStringContainsString(
      'Deployment failed before release switch.',
      $trap,
    );
    self::assertStringContainsString(
      'Deployment failed after release switch.',
      $trap,
    );
    self::assertStringContainsString(
      'automatic database rollback is not attempted.',
      $trap,
    );
  }

  /**
   * Returns and validates the production deployment workflow.
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

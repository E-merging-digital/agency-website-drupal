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
   * Deploys the exact checked-out SHA without mutating current.
   */
  public function testWorkflowDeploysExactShaAndSerializesProduction(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/deploy-production.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);

    self::assertStringContainsString(
      'group: agency-production-deploy',
      $workflow,
    );
    self::assertStringContainsString('cancel-in-progress: false', $workflow);
    self::assertStringContainsString('uses: actions/checkout@v6', $workflow);
    self::assertStringContainsString('ref: ${{ github.sha }}', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString(
      'EXPECTED_SHA: ${{ github.sha }}',
      $workflow,
    );
    self::assertStringContainsString(
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
   * The server script must materialize and verify the exact requested commit.
   */
  public function testScriptPinsExactCommitAndKeepsCurrentImmutable(): void {
    $script = $this->deploymentScript();

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
    $script = $this->deploymentScript();

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
   * Returns the production deployment script.
   */
  private function deploymentScript(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/deploy-production.sh';

    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

}

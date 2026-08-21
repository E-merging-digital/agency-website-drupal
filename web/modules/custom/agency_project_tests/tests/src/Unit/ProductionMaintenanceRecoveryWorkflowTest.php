<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the one-shot production maintenance recovery route.
 *
 * @group agency_project_tests
 * @group production_maintenance_recovery
 */
final class ProductionMaintenanceRecoveryWorkflowTest extends TestCase {

  /**
   * The recovery control surface must stay issue-bound and live-main trusted.
   */
  public function testControlSurfaceIsClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-maintenance-recovery.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('/agency-production-maintenance recover', $workflow);
    self::assertStringContainsString("github.actor == 'E-merging-digital'", $workflow);
    self::assertStringContainsString("ISSUE_NUMBER\" == '592'", $workflow);
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString('runs-on: ubuntu-24.04', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Recovery must be pinned to the exact runtime diagnosed by issue #590.
   */
  public function testRecoveryPreconditionsArePinnedAndRaceChecked(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-maintenance-recovery.yml',
    );

    foreach ([
      "EXPECTED_PRODUCTION_SHA='37214cb0913339829b97e3576443712e8a6a24f9'",
      'unexpected_runtime_sha',
      'drupal_bootstrap_failed',
      'maintenance_not_stuck',
      'deploy_active',
      'nginx_not_active',
      'php_fpm_not_active',
      'runtime_changed_before_mutation',
      'deploy_started_before_mutation',
      'maintenance_changed_before_mutation',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringContainsString("pgrep -af '[d]eploy-production.sh'", $workflow);
    self::assertStringContainsString('service_state nginx', $workflow);
    self::assertStringContainsString('service_state php8.4-fpm', $workflow);
  }

  /**
   * Only the two reviewed Drupal mutations may be present.
   */
  public function testMutationSurfaceIsMinimal(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-maintenance-recovery.yml',
    );

    self::assertSame(
      1,
      substr_count($workflow, 'vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer'),
    );
    self::assertSame(1, substr_count($workflow, 'vendor/bin/drush cr >/dev/null'));

    foreach ([
      'systemctl restart',
      'systemctl reload',
      'service nginx restart',
      'deploy-production.sh main',
      'drush cim',
      'drush updb',
      'drush sql:',
      'sudo ',
      'git pull',
      'git reset',
      'git checkout',
      'composer ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Recovery must prove maintenance, runtime and public HTTP postconditions.
   */
  public function testPostconditionsAndEvidenceAreFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-maintenance-recovery.yml',
    );

    foreach ([
      'maintenance_after',
      'current_sha_after',
      'active_deploy_processes_after',
      'public_fr_status',
      'public_en_status',
      "outcome 'RECOVERED'",
      "outcome 'RECOVERY_POSTCHECK_FAILED'",
      'artifacts/production-maintenance-recovery/result.json',
      'artifacts/production-maintenance-recovery/recovery.txt',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * The route may reuse only the existing production SSH credentials.
   */
  public function testOnlyExistingSshSecretsAreUsed(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-maintenance-recovery.yml',
    );

    self::assertStringContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
    self::assertStringContainsString('secrets.SERVER_HOST', $workflow);
    self::assertStringContainsString('secrets.SERVER_USER', $workflow);
    self::assertStringNotContainsString('secrets.OPENAI', $workflow);
    self::assertStringNotContainsString('secrets.DRUPAL', $workflow);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the incident-bound production maintenance recovery for #600.
 *
 * @group agency_project_tests
 * @group production_maintenance_recovery
 */
final class ProductionMaintenanceRecovery600WorkflowTest extends TestCase {

  /**
   * The v2 control surface must stay issue-bound and live-main trusted.
   */
  public function testControlSurfaceIsClosed(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString(
      '/agency-production-maintenance recover-600',
      $workflow,
    );
    self::assertStringContainsString(
      "github.event.issue.number == 600",
      $workflow,
    );
    self::assertStringContainsString(
      "github.actor == 'E-merging-digital'",
      $workflow,
    );
    self::assertStringContainsString(
      "ISSUE_NUMBER\" == '600'",
      $workflow,
    );
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString('runs-on: ubuntu-24.04', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Recovery must be pinned to the exact runtime diagnosed for #600.
   */
  public function testPreconditionsArePinnedAndRaceChecked(): void {
    $workflow = $this->workflow();

    foreach ([
      "EXPECTED_PRODUCTION_SHA='ffccfc35c24805c4ae973cbd847b827b21a04184'",
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

    self::assertStringContainsString(
      "pgrep -af '[d]eploy-production.sh'",
      $workflow,
    );
    self::assertStringContainsString('service_state nginx', $workflow);
    self::assertStringContainsString('service_state php8.4-fpm', $workflow);
  }

  /**
   * Only the two reviewed Drupal mutations may be present.
   */
  public function testMutationSurfaceIsMinimal(): void {
    $workflow = $this->workflow();

    self::assertSame(
      1,
      substr_count(
        $workflow,
        'vendor/bin/drush state:set system.maintenance_mode 0 --input-format=integer',
      ),
    );
    self::assertSame(
      1,
      substr_count($workflow, 'vendor/bin/drush cr >/dev/null'),
    );

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
   * Recovery must prove the fixed postconditions and persist evidence.
   */
  public function testPostconditionsAndEvidenceAreFailClosed(): void {
    $workflow = $this->workflow();

    foreach ([
      'maintenance_after',
      'current_sha_after',
      'active_deploy_processes_after',
      'public_fr_status',
      'public_en_status',
      "outcome 'RECOVERED'",
      "outcome 'RECOVERY_POSTCHECK_FAILED'",
      'production-maintenance-recovery-600/result.json',
      'production-maintenance-recovery-600/recovery.txt',
      'gh issue comment 600',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Loads and parses the v2 workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-production-maintenance-recovery-600.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

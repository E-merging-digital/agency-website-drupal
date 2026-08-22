<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the read-only production deploy-jobs diagnostic.
 *
 * @group agency_project_tests
 * @group production_deploy_jobs_diagnostic
 */
final class ProductionDeployJobsDiagnosticWorkflowTest extends TestCase {

  /**
   * The route must remain incident-bound and live-main trusted.
   */
  public function testControlSurfaceIsClosed(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-deploy-jobs diagnose',
      'github.event.issue.number == 636',
      "github.actor == 'E-merging-digital'",
      "ISSUE_NUMBER\" == '636'",
      'currently on live main',
      'runs-on: ubuntu-24.04',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * It may inspect only the bounded deploy-job evidence surface.
   */
  public function testRemoteDiagnosticIsReadOnlyAndBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      "JOBS_DIR='/var/www/agency/shared/deploy-jobs'",
      '-name "run-*-*-${EXPECTED_SHA}"',
      'request.env',
      'result.env',
      'output.log',
      'bootstrap.log',
      'kill -0 "$worker_pid"',
      "stat -c '%s'",
      'result_outcome',
      "diagnostic_outcome 'NOT_STAGED'",
      "diagnostic_outcome 'RESULT_PRESENT'",
      "diagnostic_outcome 'RUNNING'",
      "diagnostic_outcome 'LOST'",
      "diagnostic_outcome 'STAGED_NO_WORKER'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'source ',
      '. "$request_file"',
      '. "$result_file"',
      'deploy-production.sh main',
      'state:set',
      'drush cr',
      'drush cim',
      'drush updb',
      'composer ',
      'git pull',
      'git checkout',
      'git reset',
      'systemctl restart',
      'systemctl reload',
      'sudo ',
      'rm -',
      'mv ',
      'chown ',
      'mkdir -p /var/www/agency',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The route must preserve bounded machine-readable evidence.
   */
  public function testEvidenceContainsDeployIdentityAndState(): void {
    $workflow = $this->workflow();

    foreach ([
      'artifacts/production-deploy-jobs/result.json',
      'artifacts/production-deploy-jobs/diagnostic.env',
      'agency-production-deploy-jobs-636-${{ github.run_id }}-${{ github.run_attempt }}',
      'deploy_request_id:',
      'deploy_workflow_run_id:',
      'deploy_workflow_attempt:',
      'worker_state:',
      'result_outcome:',
      'result_exit:',
      'request_env_present:',
      'pid_present:',
      'result_present:',
      'output_log_size:',
      'bootstrap_log_size:',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Loads and parses the diagnostic workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-production-deploy-jobs-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the read-only GitHub Actions production deploy-run diagnostic.
 *
 * @group agency_project_tests
 * @group production_deploy_run_diagnostic
 */
final class ProductionDeployRunDiagnosticWorkflowTest extends TestCase {

  /**
   * The control surface must stay incident-bound and trusted-main only.
   */
  public function testControlSurfaceIsClosed(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-deploy-run diagnose',
      'github.event.issue.number == 636',
      "github.actor == 'E-merging-digital'",
      "ISSUE_NUMBER\" == '636'",
      'currently on live main',
      'actions: read',
      'contents: read',
      'issues: write',
      'pull-requests: read',
      'runs-on: ubuntu-24.04',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * The target must be derived from PR #641, never supplied by the caller.
   */
  public function testDeployTargetAndRunSelectionAreFixed(): void {
    $workflow = $this->workflow();

    foreach ([
      'pulls/641',
      "jq -r '.merge_commit_sha'",
      'actions/runs?event=push&branch=main&per_page=100',
      '.name == "Deploy Production"',
      '.event == "push"',
      '.head_branch == "main"',
      '.head_sha == $sha',
      'actions/runs/$run_id/jobs?filter=latest&per_page=100',
      'RUN_NOT_FOUND',
      'RUN_FOUND_NO_JOBS',
      'RUN_FOUND',
      'failed_step',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'github.event.inputs',
      'TARGET_SHA: ${{ github.event',
      'workflow_call:',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * A pending target must expose an older non-terminal predecessor if present.
   */
  public function testPendingConcurrencyBlockerIsObservable(): void {
    $workflow = $this->workflow();

    foreach ([
      'RUN_PENDING_BEHIND_ACTIVE_PREDECESSOR',
      'target_pending_while_older_deploy_run_is_non_terminal',
      '.status == "in_progress"',
      '.status == "queued"',
      '.status == "waiting"',
      '.status == "requested"',
      '.status == "pending"',
      'actions/runs/$blocker_run_id/jobs?filter=latest&per_page=100',
      'blocker_run_id',
      'blocker_run_attempt',
      'blocker_sha',
      'blocker_status',
      'blocker_conclusion',
      'blocker_job_id',
      'blocker_job_name',
      'blocker_job_status',
      'blocker_job_conclusion',
      'blocker_active_step',
      'select(.status == "in_progress")',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * The route must have no production or Actions mutation capability.
   */
  public function testDiagnosticIsReadOnlyAndBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      'ssh ',
      'scp ',
      'SERVER_HOST',
      'SERVER_USER',
      'SSH_PRIVATE_KEY',
      'deploy-production.sh',
      'gh workflow run',
      'gh run rerun',
      '/rerun',
      '/dispatches',
      'actions: write',
      'contents: write',
      'pull-requests: write',
      'workflow_dispatch:',
      'state:set',
      'drush ',
      'systemctl ',
      'sudo ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Evidence must identify target and blocker state when available.
   */
  public function testEvidenceIsMachineReadableAndBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      'schema_version: 2',
      'artifacts/production-deploy-run/result.json',
      'agency-production-deploy-run-636-${{ github.run_id }}-${{ github.run_attempt }}',
      'trusted_main',
      'target_sha',
      'deploy_run_id',
      'deploy_run_attempt',
      'deploy_run_status',
      'deploy_run_conclusion',
      'deploy_job_id',
      'deploy_job_name',
      'deploy_job_status',
      'deploy_job_conclusion',
      'failed_step',
      'blocker_run_id',
      'blocker_sha',
      'blocker_active_step',
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
      . '/.github/workflows/trusted-production-deploy-run-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

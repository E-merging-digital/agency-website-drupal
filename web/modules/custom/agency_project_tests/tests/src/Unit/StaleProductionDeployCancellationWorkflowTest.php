<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the one-shot cancellation of the exact stale production blocker.
 *
 * @group agency_project_tests
 * @group stale_production_deploy_cancellation
 */
final class StaleProductionDeployCancellationWorkflowTest extends TestCase {

  /**
   * The route must stay incident-bound and execute only from live main.
   */
  public function testControlSurfaceIsClosed(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-deploy-run cancel-stale-blocker',
      'github.event.issue.number == 636',
      "github.actor == 'E-merging-digital'",
      'currently on live main',
      'actions: write',
      'contents: read',
      'issues: write',
      'pull-requests: read',
      'runs-on: ubuntu-24.04',
      'agency-stale-production-deploy-cancellation',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * The only mutable Actions target must be the proven stale blocker.
   */
  public function testExactRunsAndOnlyCancelMutationArePinned(): void {
    $workflow = $this->workflow();

    foreach ([
      "BLOCKER_RUN_ID: '32565825830'",
      "TARGET_RUN_ID: '32567826577'",
      'pulls/638',
      'pulls/641',
      "7816206a037ea6fe741112f13991c3c76e54eea7",
      "acea55cf0aa02acdee9ee9866977733f0e0cff8e",
      'actions/runs/$BLOCKER_RUN_ID/cancel',
      'cancel_blocker_run_only',
      'Target run',
      'is never cancelled, rerun or redispatched',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'actions/runs/$TARGET_RUN_ID/cancel',
      '/rerun',
      '/rerun-failed-jobs',
      '/dispatches',
      'gh workflow run',
      'gh run rerun',
      'workflow_dispatch:',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Cancellation requires the immutable green production-health proof.
   */
  public function testHealthAndConcurrencyPreconditionsArePinned(): void {
    $workflow = $this->workflow();

    foreach ([
      "HEALTH_DIAGNOSTIC_RUN_ID: '32569318935'",
      "HEALTH_TRUSTED_MAIN: 'b587d2cc5049fb07f3617d078e66a339923a990e'",
      "HEALTH_RUNTIME_SHA: '0ccadc58c16acdde8297ff5b6902f5406b9efaf9'",
      'Agency production health diagnostic PUBLIC_HTTP_HEALTHY',
      'maintenance_mode: `0`',
      'active_deploy_processes: `0`',
      'public_fr_status: `301`',
      'public_en_status: `200`',
      "blocker_status\" != 'in_progress'",
      "target_status\" != 'pending'",
      "target_job_count\" != '0'",
      'NO_ACTION_STATE_CHANGED',
      'mutation_performed: false',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * The route must not gain server or Drupal mutation capabilities.
   */
  public function testNoProductionServerMutationCapabilityExists(): void {
    $workflow = $this->workflow();

    foreach ([
      'ssh ',
      'scp ',
      'SERVER_HOST',
      'SERVER_USER',
      'SSH_PRIVATE_KEY',
      'deploy-production.sh',
      'state:set',
      'drush ',
      'systemctl ',
      'sudo ',
      'contents: write',
      'pull-requests: write',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Postconditions must observe blocker release and the existing target only.
   */
  public function testPostconditionsAreMachineReadable(): void {
    $workflow = $this->workflow();

    foreach ([
      'schema_version: 1',
      'BLOCKER_CANCELLED_TARGET_RELEASED',
      'BLOCKER_TERMINAL_TARGET_RELEASED',
      'BLOCKER_TERMINAL_TARGET_STILL_PENDING',
      'BLOCKER_CANCEL_REQUESTED',
      'artifacts/stale-production-deploy-cancellation/result.json',
      'blocker_status',
      'blocker_conclusion',
      'target_status',
      'target_conclusion',
      'target_job_count',
      'target_job_id',
      'target_job_status',
      'agency-stale-production-deploy-cancellation-636-${{ github.run_id }}-${{ github.run_attempt }}',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Loads and parses the trusted workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-stale-production-deploy-cancellation.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

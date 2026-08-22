<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded read-only detached deploy phase diagnostic.
 *
 * @group agency_project_tests
 * @group production_deploy_phase_diagnostic
 */
final class ProductionDeployPhaseDiagnosticWorkflowTest extends TestCase {

  public function testControlSurfaceAndTargetArePinned(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-deploy-phase diagnose',
      'github.event.issue.number == 636',
      "github.actor == 'E-merging-digital'",
      'currently on live main',
      'run-32567826577-1-acea55cf0aa02acdee9ee9866977733f0e0cff8e',
      'acea55cf0aa02acdee9ee9866977733f0e0cff8e',
      'runs-on: ubuntu-24.04',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  public function testOnlyAllowlistedPhaseMarkersAreReported(): void {
    $workflow = $this->workflow();

    foreach ([
      'last_allowlisted_phase',
      "phase=MAINTENANCE_ON",
      "phase=SWITCH_RELEASE",
      "phase=MAINTENANCE_OFF",
      "phase=SUCCESS",
      "phase=ERROR",
      "grep -Fq '[deploy] Maintenance ON'",
      "grep -Fq '[deploy] Switch release'",
      "grep -Fq '[deploy] SUCCESS'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'cat "$output_file"',
      'tail "$output_file"',
      'tail -n',
      'sed -n "$output_file"',
      'command=',
      'args=',
      'cmdline',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  public function testProcessEvidenceContainsNamesWithoutArguments(): void {
    $workflow = $this->workflow();

    foreach ([
      "ps -p \"$1\" -o comm=",
      "ps -p \"$1\" -o etimes=",
      'ps --ppid "$1" -o pid=',
      'worker_comm',
      'worker_elapsed_seconds',
      'child_comm',
      'child_elapsed_seconds',
      'grandchild_comm',
      'grandchild_elapsed_seconds',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'ps aux',
      'ps -ef',
      '-o args',
      '-o command',
      '/proc/',
      'environ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  public function testRouteHasNoMutationCapability(): void {
    $workflow = $this->workflow();

    foreach ([
      'kill -TERM',
      'kill -KILL',
      'pkill',
      'state:set',
      'drush cr',
      'drush cim',
      'drush updb',
      'composer install',
      'git pull',
      '/cancel',
      '/rerun',
      '/dispatches',
      'actions: write',
      'contents: write',
      'sudo ',
      'rm -',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }

    self::assertStringContainsString('kill -0 "$worker_pid"', $workflow);
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString('issues: write', $workflow);
  }

  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-production-deploy-phase-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the read-only production DB runtime privilege diagnostic.
 *
 * @group agency_project_tests
 * @group production_database_runtime_privilege
 */
final class ProductionDatabaseRuntimePrivilegeDiagnosticWorkflowTest extends TestCase {

  /**
   * Ensures the control surface is owner-only and pinned to issue 664.
   */
  public function testControlSurfaceIsPinned(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-db-runtime-privilege diagnose',
      'github.event.issue.number == 664',
      "github.actor == 'E-merging-digital'",
      '[[ "$ISSUE_NUMBER" == \'664\' ]]',
      'EVENT_DEFAULT_SHA',
      'currently on live main',
      'runs-on: ubuntu-24.04',
      "CURRENT='/var/www/agency/current'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('github.event.inputs', $workflow);
  }

  /**
   * Ensures the incident preconditions are fixed before privilege inspection.
   */
  public function testProductionPreconditionsAreFixed(): void {
    $workflow = $this->workflow();

    foreach ([
      '9188a2ebd6516a738be6df6f854794d41889aa90',
      "EXPECTED_PACKET='16777216'",
      '11.8.8-MariaDB-ubu2404',
      'SELECT VERSION();',
      'SELECT @@global.max_allowed_packet;',
      'system.maintenance_mode',
      "pgrep -fc '[d]eploy-production.sh'",
      'drupal_bootstrap',
      'active_deploy_processes',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Ensures only bounded privilege booleans can leave the remote shell.
   */
  public function testGrantInspectionIsBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      'SHOW GRANTS FOR CURRENT_USER;',
      'has_super=0',
      'has_global_all=0',
      'RUNTIME_GLOBAL_CHANGE_AVAILABLE',
      'NOT_AVAILABLE',
      "grep -Eiq '(^|[ ,])SUPER([, ]|$)'",
      "grep -Eiq '^GRANT ALL PRIVILEGES ON \\*\\.\\* '",
      'Raw grants, database identity and credentials are never published',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'scalar grants',
      'current_user:',
      'database_user',
      'database_host',
      'cat "$grants"',
      'echo "$grants"',
      'printf \'%s\\n\' "$grants" >',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Ensures the route cannot mutate the database, Drupal or the host.
   */
  public function testRouteHasNoMutationCapability(): void {
    $workflow = $this->workflow();

    foreach ([
      'SET GLOBAL',
      'SET SESSION',
      'REVOKE ',
      'FLUSH ',
      'vendor/bin/drush cr',
      'state:set',
      'config:import',
      'drush updb',
      'drush cim',
      'systemctl restart',
      'systemctl reload',
      'sudo ',
      'git pull',
      'actions: write',
      'contents: write',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }

    self::assertStringNotContainsString("sql:query 'GRANT", $workflow);
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString('issues: write', $workflow);
  }

  /**
   * Ensures only the JSON scalar result is retained as an artifact.
   */
  public function testArtifactIsBounded(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString(
      'path: artifacts/production-db-runtime-privilege/result.json',
      $workflow,
    );
    self::assertStringNotContainsString(
      "path: artifacts/production-db-runtime-privilege\n",
      $workflow,
    );
  }

  /**
   * Loads and parses the trusted runtime privilege diagnostic workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-production-db-runtime-privilege-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

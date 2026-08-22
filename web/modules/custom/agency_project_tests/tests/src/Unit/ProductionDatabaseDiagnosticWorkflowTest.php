<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded production database diagnostic workflow.
 *
 * @group agency_project_tests
 * @group production_database_diagnostic
 */
final class ProductionDatabaseDiagnosticWorkflowTest extends TestCase {

  /**
   * Ensures the route is pinned to the owner-created incident.
   */
  public function testControlSurfaceIsPinned(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-db diagnose',
      'github.event.issue.number == 658',
      "github.actor == 'E-merging-digital'",
      '[[ "$ISSUE_NUMBER" == \'658\' ]]',
      "jq -r '.state'",
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
   * Ensures the diagnostic measures the suspected database limits.
   */
  public function testDatabaseMetricsAreFixedAndReadOnly(): void {
    $workflow = $this->workflow();

    foreach ([
      'SELECT 1;',
      'SELECT VERSION();',
      'SELECT @@global.max_allowed_packet;',
      'SELECT @@session.max_allowed_packet;',
      'SELECT @@global.max_connections;',
      'SELECT @@global.wait_timeout;',
      'SELECT @@global.net_read_timeout;',
      'SELECT @@global.net_write_timeout;',
      'status_number Aborted_clients',
      'status_number Aborted_connects',
      'status_number Threads_connected',
      'status_number Max_used_connections',
      'OCTET_LENGTH(data)',
      'information_schema.tables',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'INSERT INTO',
      'DELETE FROM',
      'TRUNCATE TABLE',
      'ALTER TABLE',
      'DROP TABLE',
      'CREATE TABLE',
      'REPLACE INTO',
      'SET GLOBAL',
      'SET SESSION',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Ensures only known cache tables can be interpolated into SQL.
   */
  public function testCacheTableNamesAreAllowlisted(): void {
    $workflow = $this->workflow();

    foreach ([
      'cache_file_parsing|cache_discovery|cache_config|router',
      'table_exists()',
      'table_info_number()',
      'cache_data_number()',
      'case "$table" in',
      'case "$column" in',
      'case "$aggregate" in',
      'table_rows|data_length|index_length',
      'count) sql_number',
      'max) sql_number',
      'sum) sql_number',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Ensures the route cannot repair or mutate production itself.
   */
  public function testRouteHasNoRepairCapability(): void {
    $workflow = $this->workflow();

    foreach ([
      'vendor/bin/drush cr',
      'state:set',
      'config:import',
      'drush updb',
      'drush cim',
      'systemctl restart',
      'systemctl reload',
      'service restart',
      'kill -TERM',
      'kill -KILL',
      'pkill',
      'sudo ',
      'git pull',
      '/dispatches',
      '/rerun',
      '/cancel',
      'actions: write',
      'contents: write',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }

    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString('issues: write', $workflow);
  }

  /**
   * Ensures the published evidence remains bounded to scalar metrics.
   */
  public function testPublishedEvidenceIsBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      'global_max_allowed_packet',
      'session_max_allowed_packet',
      'cache_file_parsing_max_data_bytes',
      'cache_discovery_max_data_bytes',
      'cache_config_max_data_bytes',
      'router_data_length',
      'Fixed read-only diagnostic only.',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'journalctl',
      'cat /var/log',
      'cat "$raw"',
      'env |',
      'printenv',
      '/proc/',
      'SHOW PROCESSLIST',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Loads and parses the trusted database diagnostic workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-production-db-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

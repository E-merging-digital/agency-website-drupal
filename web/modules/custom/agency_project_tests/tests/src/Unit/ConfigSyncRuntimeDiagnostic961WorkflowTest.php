<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the reused #961 PREPROD primitive for #982.
 *
 * @group agency_project_tests
 * @group config_sync_runtime_diagnostic_961
 */
final class ConfigSyncRuntimeDiagnostic961WorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/config-sync-runtime-diagnostic.yml';
  private const RUNNER = 'scripts/runner/run-config-sync-runtime-diagnostic-961.sh';
  private const FILTER = 'scripts/runner/filter-config-status-metadata.php';

  /**
   * The operational route is now bounded to #982 and its exact authority.
   */
  public function testWorkflowIsBoundToIssue982AndExactCommand(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);

    $on = $workflow['on'] ?? NULL;
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayHasKey('pull_request', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertStringContainsString('github.event.issue.number == 982', $source);
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-sync-runtime diagnose'",
      $source,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'982\' ]]', $source);
    self::assertStringContainsString('5528251064', $source);
    self::assertStringContainsString('EVENT_DEFAULT_SHA', $source);
    self::assertStringContainsString(
      'JIT revalidate live main before PREPROD identity',
      $source,
    );

    self::assertSame(
      ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($on['workflow_call']['secrets'] ?? []),
    );
    self::assertArrayNotHasKey('inputs', $on['workflow_call']);
  }

  /**
   * Pull-request validation has no PREPROD identity or network operation.
   */
  public function testPullRequestValidationIsNonOperational(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $static = $workflow['jobs']['static-validation'] ?? NULL;
    self::assertIsArray($static);
    self::assertSame(
      '${{ github.event_name == \'pull_request\' }}',
      $static['if'] ?? NULL,
    );
    self::assertArrayNotHasKey('secrets', $static);

    $surface = json_encode($static, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('PREPROD_SSH_PRIVATE_KEY', $surface);
    self::assertStringNotContainsString('PREPROD_SERVER_HOST', $surface);
    self::assertStringNotContainsString('ssh ', $surface);
    self::assertStringNotContainsString('scp ', $surface);
  }

  /**
   * The #961 SSH/trust primitive is reused rather than replaced.
   */
  public function testRunnerReusesFixedPreprodTrustAndPaths(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'961\' || "$ISSUE_NUMBER" == \'982\' ]]',
      $runner,
    );
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency-preprod'", $runner);
    self::assertStringContainsString('agency-preprod@$PREPROD_SERVER_HOST', $runner);
    self::assertStringContainsString(
      'scripts/preproduction-ssh-trust/manage-known-host.sh',
      $runner,
    );
    self::assertStringContainsString(
      'scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh',
      $runner,
    );
    self::assertStringContainsString('StrictHostKeyChecking=yes', $runner);
    self::assertStringContainsString('/var/www/agency-preprod/current', $runner);
    self::assertStringContainsString(
      '/var/www/agency-preprod/shared/settings/settings.php',
      $runner,
    );

    self::assertStringNotContainsString('workflow_dispatch', $runner);
    self::assertStringNotContainsString('/var/www/agency/current', $runner);
    self::assertStringNotContainsString('TARGET=', $runner);
    self::assertStringNotContainsString('bash -s', $runner);
    self::assertStringNotContainsString('scp ', $runner);
    self::assertStringNotContainsString('ssh-keyscan', $runner);
    self::assertStringNotContainsString('StrictHostKeyChecking=no', $runner);
    self::assertStringNotContainsString('accept-new', $runner);
  }

  /**
   * Existing nounset-safe SHA construction remains intact.
   */
  public function testSettingsShaCommandSurvivesNounset(): void {
    $runner = $this->source(self::RUNNER);
    $matches = array_values(array_filter(
      preg_split('/\R/', $runner) ?: [],
      static fn(string $line): bool => str_contains(
        $line,
        'sha256sum \'$EXPECTED_SETTINGS\' | awk',
      ),
    ));
    self::assertCount(1, $matches);
    $expression = trim($matches[0]);
    self::assertStringStartsWith('"set -euo pipefail;', $expression);
    self::assertStringEndsWith('")"', $expression);
    $expression = substr($expression, 0, -2);

    $bash = implode("\n", [
      'set -u',
      "EXPECTED_SETTINGS='/var/www/agency-preprod/shared/settings/settings.php'",
      'remote_command=' . $expression,
      'printf \'%s\\n\' "$remote_command"',
    ]);
    $process = new Process(['bash', '-c', $bash]);
    $process->run();
    self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    self::assertStringContainsString('awk \'{print $1}\'', $process->getOutput());
  }

  /**
   * Drush remains read-only and raw status is filtered locally.
   */
  public function testConfigStatusInventoryIsReadOnlyAndMetadataOnly(): void {
    $runner = $this->source(self::RUNNER);
    $workflow = $this->source(self::WORKFLOW);
    $filter = $this->source(self::FILTER);

    self::assertStringContainsString(
      'vendor/bin/drush config:status --format=json',
      $runner,
    );
    self::assertStringContainsString(
      'php "$CONFIG_STATUS_FILTER" PREPROD',
      $runner,
    );
    self::assertStringContainsString('runtime_config_metadata', $runner);
    self::assertStringContainsString('config_values_exposed', $runner . $filter);
    self::assertStringContainsString(
      'environment + config_name + operation/state',
      $filter . $workflow,
    );
    self::assertStringContainsString('public-result.json', $workflow);
    self::assertStringContainsString("jq '.runtime_config_metadata'", $workflow);
    self::assertStringNotContainsString('config_status_raw`', $workflow);

    foreach ([
      'vendor/bin/drush cim',
      'vendor/bin/drush cex',
      'vendor/bin/drush config:import',
      'vendor/bin/drush config:export',
      'vendor/bin/drush cr',
      'vendor/bin/drush updb',
      'vendor/bin/drush deploy',
      'vendor/bin/drush pm:enable',
      'vendor/bin/drush config:set',
      'state:set',
      'sql:query',
      'sql:dump',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner, $forbidden);
    }

    foreach ([$runner, $workflow, $filter] as $surface) {
      self::assertStringNotContainsString('DB_PASSWORD', $surface);
      self::assertStringNotContainsString('DATABASE_URL', $surface);
      self::assertStringNotContainsString('cat settings.php', $surface);
      self::assertStringNotContainsString('cat "$EXPECTED_SETTINGS"', $surface);
      self::assertStringNotContainsString('source "$EXPECTED_SETTINGS"', $surface);
      self::assertStringNotContainsString(
        'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
        $surface,
      );
    }
  }

  /**
   * Reads one YAML workflow structurally.
   */
  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded read-only PREPROD config sync runtime probe.
 *
 * @group agency_project_tests
 * @group config_sync_runtime_diagnostic_961
 */
final class ConfigSyncRuntimeDiagnostic961WorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/config-sync-runtime-diagnostic.yml';
  private const RUNNER = 'scripts/runner/run-config-sync-runtime-diagnostic-961.sh';

  /**
   * Proves the workflow is bound to issue #961 and one exact command.
   */
  public function testWorkflowIsBoundToIssue961AndExactCommand(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);

    self::assertArrayHasKey('on', $workflow);
    $on = $workflow['on'];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayHasKey('pull_request', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertStringContainsString('github.event.issue.number == 961', $source);
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-sync-runtime diagnose'",
      $source,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'961\' ]]', $source);
    self::assertStringContainsString('[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]', $source);
    self::assertStringContainsString("== 'open'", $source);
    self::assertStringContainsString('5516638679', $source);
    self::assertStringContainsString('EVENT_DEFAULT_SHA', $source);
    self::assertStringContainsString(
      'JIT revalidate live main before PREPROD identity',
      $source,
    );

    $secrets = $on['workflow_call']['secrets'] ?? [];
    self::assertSame(
      ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($secrets),
    );
    self::assertArrayNotHasKey('inputs', $on['workflow_call']);
  }

  /**
   * Proves pull-request validation cannot reach the PREPROD runtime.
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
   * Proves the runner uses fixed PREPROD trust, identity and paths only.
   */
  public function testRunnerIsFixedToPreprodAndHasNoArbitraryExecution(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'961\' ]]', $runner);
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
    self::assertStringNotContainsString('SERVER_USER', $runner);
    self::assertStringNotContainsString('SSH_PRIVATE_KEY', $runner);
    self::assertStringNotContainsString('/var/www/agency/current', $runner);
    self::assertStringNotContainsString('TARGET=', $runner);
    self::assertStringNotContainsString('eval "$', $runner);
    self::assertStringNotContainsString('bash -s', $runner);
    self::assertStringNotContainsString('scp ', $runner);
    self::assertStringNotContainsString('ssh-keyscan', $runner);
    self::assertStringNotContainsString('StrictHostKeyChecking=no', $runner);
    self::assertStringNotContainsString('accept-new', $runner);
  }

  /**
   * Proves Drupal supplies the setting and config status remains read-only.
   */
  public function testDrupalGetterAndConfigStatusAreReadOnly(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      "\\Drupal\\Core\\Site\\Settings::get('config_sync_directory')",
      $runner,
    );
    self::assertStringContainsString('base64_encode(DRUPAL_ROOT)', $runner);
    self::assertStringContainsString('vendor/bin/drush status --field=bootstrap', $runner);
    self::assertStringContainsString('vendor/bin/drush php:eval', $runner);
    self::assertStringContainsString('vendor/bin/drush config:status --format=json', $runner);
    self::assertStringContainsString('DRUPAL_STATUS_CONFIG_SYNC_WARNING', strtoupper($runner));

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
  }

  /**
   * Proves evidence is metadata-only and settings contents cannot be emitted.
   */
  public function testEvidenceAndSecretBoundaryIsMetadataOnly(): void {
    $runner = $this->source(self::RUNNER);
    $workflow = $this->source(self::WORKFLOW);

    foreach ([
      'CURRENT_RELEASE',
      'CURRENT_SYMLINK_TARGET',
      'DRUPAL_ROOT',
      'SETTINGS_SYMLINK_TARGET',
      'SHARED_SETTINGS_SHA256',
      'EFFECTIVE_CONFIG_SYNC_DIRECTORY',
      'RESOLVED_CONFIG_SYNC_PATH',
      'RESOLVED_PATH_EXISTS',
      'CONFIG_SYNC_ENTRY_COUNT',
      'DRUSH_BOOTSTRAP',
      'DRUSH_CONFIG_STATUS',
      'DRUPAL_STATUS_CONFIG_SYNC_WARNING',
    ] as $field) {
      self::assertStringContainsString($field, strtoupper($workflow . $runner), $field);
    }

    self::assertStringContainsString('sha256sum', $runner);
    self::assertStringContainsString('readlink -f', $runner);
    self::assertStringContainsString('find', $runner);
    self::assertStringContainsString('"NOT_OBSERVABLE"', $runner);
    self::assertStringContainsString('preprod_mutation: "NONE"', $runner);
    self::assertStringContainsString('prod_access: "NONE"', $runner);
    self::assertStringContainsString('prod_write: "NONE"', $runner);

    foreach ([$runner, $workflow] as $surface) {
      self::assertStringNotContainsString('DB_PASSWORD', $surface);
      self::assertStringNotContainsString('DATABASE_URL', $surface);
      self::assertStringNotContainsString('runtime.env', $surface);
      self::assertStringNotContainsString('cat settings.php', $surface);
      self::assertStringNotContainsString('cat "$EXPECTED_SETTINGS"', $surface);
      self::assertStringNotContainsString('source "$EXPECTED_SETTINGS"', $surface);
      self::assertDoesNotMatchRegularExpression(
        '/(?<!PRE)PROD_SSH_PRIVATE_KEY/',
        $surface,
      );
      self::assertStringNotContainsString(
        'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
        $surface,
      );
    }
  }

  /**
   * Parses one repository workflow structurally.
   *
   * @return array<string, mixed>
   *   The parsed workflow structure.
   */
  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads one repository source file as text.
   *
   * @return string
   *   The repository source contents.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

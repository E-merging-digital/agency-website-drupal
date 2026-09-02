<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded read-only PROD config sync runtime probe.
 *
 * @group agency_project_tests
 * @group prod_config_sync_runtime_diagnostic_980
 */
final class ProdConfigSyncRuntimeDiagnostic980WorkflowTest extends TestCase {

  private const WORKFLOW =
    '.github/workflows/prod-config-sync-runtime-diagnostic.yml';

  private const RUNNER =
    'scripts/runner/run-prod-config-sync-runtime-diagnostic-980.sh';

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  private const LEGACY_PROD_HEALTH_DOC =
    'docs/operations/production-health-diagnostic.md';

  /**
   * Proves the workflow is bound to issue #980 and one exact command.
   */
  public function testWorkflowIsBoundToIssue980AndExactCommand(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);

    self::assertArrayHasKey('on', $workflow);
    $on = $workflow['on'];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayHasKey('pull_request', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertStringContainsString('github.event.issue.number == 980', $source);
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-sync-prod-runtime diagnose'",
      $source,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'980\' ]]', $source);
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $source,
    );
    self::assertStringContainsString("== 'open'", $source);
    self::assertStringContainsString('EVENT_DEFAULT_SHA', $source);
    self::assertStringContainsString(
      'JIT revalidate live main before PROD identity',
      $source,
    );

    $secrets = $on['workflow_call']['secrets'] ?? [];
    self::assertSame(
      ['SSH_PRIVATE_KEY', 'SERVER_HOST', 'SERVER_USER'],
      array_keys($secrets),
    );
    self::assertArrayNotHasKey('inputs', $on['workflow_call']);
  }

  /**
   * Proves pull-request validation cannot reach PROD or PREPROD runtime.
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
    self::assertStringNotContainsString('SSH_PRIVATE_KEY', $surface);
    self::assertStringNotContainsString('SERVER_HOST', $surface);
    self::assertStringNotContainsString('SERVER_USER', $surface);
    self::assertStringNotContainsString('ssh ', $surface);
    self::assertStringNotContainsString('scp ', $surface);
  }

  /**
   * Proves fixed PROD trust, identity source and paths only are used.
   */
  public function testRunnerIsFixedToProdAndReusesPinnedTrust(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'980\' ]]', $runner);
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency'", $runner);
    self::assertStringContainsString('$SERVER_USER@$SERVER_HOST', $runner);
    self::assertStringContainsString(
      "PROD_TRUST='scripts/production-ssh-trust/manage-known-host.sh'",
      $runner,
    );
    self::assertStringContainsString(
      'SERVER_HOST="$SERVER_HOST" bash "$PROD_TRUST" PROVISION',
      $runner,
    );
    self::assertStringContainsString(
      'SERVER_HOST="$SERVER_HOST" bash "$PROD_TRUST" VERIFY_ONLY',
      $runner,
    );
    self::assertStringContainsString('StrictHostKeyChecking=yes', $runner);
    self::assertStringContainsString('/var/www/agency/current', $runner);
    self::assertStringContainsString(
      '/var/www/agency/shared/settings/settings.php',
      $runner,
    );

    self::assertStringNotContainsString('/var/www/agency-preprod', $runner);
    self::assertStringNotContainsString('PREPROD_SERVER_HOST', $runner);
    self::assertStringNotContainsString('PREPROD_SSH_PRIVATE_KEY', $runner);
    self::assertStringNotContainsString('TARGET=', $runner);
    self::assertStringNotContainsString('bash -s', $runner);
    self::assertStringNotContainsString('scp ', $runner);
    self::assertStringNotContainsString('ssh-keyscan', $runner);
    self::assertStringNotContainsString('StrictHostKeyChecking=no', $runner);
    self::assertStringNotContainsString('accept-new', $runner);
  }

  /**
   * Proves SHA construction survives set -u without positional arguments.
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
      "EXPECTED_SETTINGS='/var/www/agency/shared/settings/settings.php'",
      'remote_command=' . $expression,
      'printf \'%s\\n\' "$remote_command"',
    ]);
    $process = new Process(['bash', '-c', $bash]);
    $process->run();

    self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    self::assertSame(
      'set -euo pipefail; sha256sum ' .
      '\'/var/www/agency/shared/settings/settings.php\' | ' .
      'awk \'{print $1}\'' . "\n",
      $process->getOutput(),
    );
  }

  /**
   * Proves Drupal provides the value and config status remains read-only.
   */
  public function testDrupalGetterResolutionAndConfigStatusAreReadOnly(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      "\\Drupal\\Core\\Site\\Settings::get('config_sync_directory')",
      $runner,
    );
    self::assertStringContainsString('base64_encode(DRUPAL_ROOT)', $runner);
    self::assertStringContainsString(
      'vendor/bin/drush status --field=bootstrap',
      $runner,
    );
    self::assertStringContainsString('vendor/bin/drush php:eval', $runner);
    self::assertStringContainsString(
      'vendor/bin/drush config:status --format=json',
      $runner,
    );
    self::assertStringContainsString(
      'realpath -m -- "$drupal_root/$effective_config_sync"',
      $runner,
    );

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
   * Proves metadata-only evidence and preserves existing diagnostic contracts.
   */
  public function testEvidenceAndExistingRoutesRemainBounded(): void {
    $runner = $this->source(self::RUNNER);
    $workflow = $this->source(self::WORKFLOW);
    $dispatcher = $this->source(self::DISPATCHER);
    $legacyHealth = $this->source(self::LEGACY_PROD_HEALTH_DOC);

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
      self::assertStringContainsString(
        $field,
        strtoupper($workflow . $runner),
        $field,
      );
    }

    self::assertStringContainsString('sha256sum', $runner);
    self::assertStringContainsString('"NOT_OBSERVABLE"', $runner);
    self::assertStringContainsString('prod_access: "READ_ONLY"', $runner);
    self::assertStringContainsString('prod_mutation: "NONE"', $runner);
    self::assertStringContainsString('prod_write: "NONE"', $runner);
    self::assertStringContainsString('preprod_access: "NONE"', $runner);
    self::assertStringContainsString('preprod_write: "NONE"', $runner);

    foreach ([$runner, $workflow] as $surface) {
      self::assertStringNotContainsString('DB_PASSWORD', $surface);
      self::assertStringNotContainsString('DATABASE_URL', $surface);
      self::assertStringNotContainsString('runtime.env', $surface);
      self::assertStringNotContainsString('cat settings.php', $surface);
      self::assertStringNotContainsString('cat "$EXPECTED_SETTINGS"', $surface);
      self::assertStringNotContainsString('source "$EXPECTED_SETTINGS"', $surface);
      self::assertStringNotContainsString(
        'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
        $surface,
      );
    }

    self::assertStringContainsString(
      '/agency-production-health diagnose',
      $legacyHealth,
    );
    self::assertStringContainsString('/var/www/agency/current', $legacyHealth);
    self::assertStringContainsString('existing production SSH channel', $legacyHealth);
    self::assertStringContainsString(
      '/agency-config-sync-runtime diagnose',
      $dispatcher,
    );
    self::assertStringContainsString(
      '/agency-config-sync-prod-runtime diagnose',
      $dispatcher,
    );
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
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the reused #961 PREPROD primitive for #982/#995/#1318.
 *
 * @group agency_project_tests
 * @group config_sync_runtime_diagnostic_961
 */
final class ConfigSyncRuntimeDiagnostic961WorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/config-sync-runtime-diagnostic.yml';
  private const RUNNER = 'scripts/runner/run-config-sync-runtime-diagnostic-961.sh';
  private const FILTER = 'scripts/runner/filter-config-status-metadata.php';

  /**
   * Proves #982/#995/#1318 PREPROD binding and exact command.
   */
  public function testWorkflowIsBoundToIssues982And995And1318AndExactCommand(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);

    self::assertArrayHasKey('on', $workflow);
    $on = $workflow['on'];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayHasKey('pull_request', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertStringContainsString(
      '(github.event.issue.number == 982 || github.event.issue.number == 995 || github.event.issue.number == 1318)',
      $source,
    );
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-sync-runtime diagnose'",
      $source,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'982\' || "$ISSUE_NUMBER" == \'995\' || "$ISSUE_NUMBER" == \'1318\' ]]',
      $source,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $source,
    );
    self::assertStringContainsString("== 'open'", $source);
    self::assertStringContainsString('5528251064', $source);
    self::assertStringContainsString('5529562346', $source);
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
   * Proves #1318 is fail-closed to fresh R2 authority and language_lock.
   */
  public function testIssue1318AuthorityAndProfileAreExact(): void {
    $workflow = $this->source(self::WORKFLOW);

    self::assertStringContainsString(
      'PROJECT_LEAD_PREPROD_READONLY_CONTINUATION_1318_R2',
      $workflow,
    );
    self::assertStringContainsString('COMMENT_AUTHOR_ASSOCIATION', $workflow);
    self::assertStringContainsString('COMMENT_FROM_APP', $workflow);
    self::assertStringContainsString(
      "github.event.issue.number == 1318 && 'language_lock'",
      $workflow,
    );
    self::assertStringContainsString('authority_created_at', $workflow);
    self::assertStringContainsString('prior_commands', $workflow);
    self::assertStringContainsString('authority_main_sha', $workflow);
    self::assertStringContainsString(
      '[[ "$main_sha" == "$authority_main_sha" ]]',
      $workflow,
    );
    self::assertStringNotContainsString('5833138659', $workflow);
    self::assertStringNotContainsString(
      'PROJECT_LEAD_PREPROD_VALIDATION_AUTHORITY_1318_R1',
      $workflow,
    );
  }

  /**
   * Proves #1318 language_lock is exact, VERIFY-only and value-safe.
   */
  public function testLanguageLockProfileIsExactReadOnlyAndValueSafe(): void {
    $runner = $this->source(self::RUNNER);
    $workflow = $this->source(self::WORKFLOW);

    self::assertStringContainsString(
      "EXPECTED_LANGUAGE_LOCK_RELEASE='/var/www/agency-preprod/releases/20260925235206-b33b6c240ea5'",
      $runner,
    );
    self::assertStringNotContainsString(
      '/var/www/agency-preprod/releases/20260925130338-5cabffd93782',
      $runner,
    );

    self::assertStringContainsString(
      "EXPECTED_LANGUAGE_LOCK_COMPOSER_SHA256='d3948f88b04e057182a1689a492f5371c5a174f3fad3c83cf7b8ce26f498ab73'",
      $runner,
    );
    self::assertStringContainsString(
      'RUNTIME_LANGUAGE_HELPER="$EXPECTED_LANGUAGE_LOCK_RELEASE/' .
      'scripts/runner/config-language-policy-candidate-1314-runtime-proof.php"',
      $runner,
    );
    self::assertStringContainsString(
      'COLLECTION_LANGUAGE_HELPER="$EXPECTED_LANGUAGE_LOCK_RELEASE/' .
      'scripts/runner/materialize-config-language-collections-1316.php"',
      $runner,
    );
    self::assertStringContainsString(
      '"$EXPECTED_LANGUAGE_LOCK_RELEASE" "$RUNTIME_LANGUAGE_HELPER"',
      $runner,
    );
    self::assertStringContainsString(
      '"$EXPECTED_LANGUAGE_LOCK_RELEASE" "$COLLECTION_LANGUAGE_HELPER"',
      $runner,
    );
    self::assertStringNotContainsString(
      "vendor/bin/drush php:script 'scripts/runner/",
      $runner,
    );
    self::assertStringContainsString(
      'AGENCY_CONFIG_LANGUAGE_COLLECTION_MODE=VERIFY',
      $runner,
    );
    self::assertStringContainsString(
      'AGENCY_CONFIG_LANGUAGE_COLLECTION_DIAGNOSTIC_ONLY=1',
      $runner,
    );
    self::assertStringNotContainsString(
      'AGENCY_CONFIG_LANGUAGE_COLLECTION_MODE=MATERIALIZE',
      $runner,
    );

    foreach ([
      'LANGUAGE_LOCK_PHASE=IDENTITY_GATE_PASS',
      'LANGUAGE_LOCK_PHASE=HELPER_EXISTENCE_PASS',
      'LANGUAGE_LOCK_PHASE=RUNTIME_HELPER_EXECUTION_PASS',
      'LANGUAGE_LOCK_PHASE=RUNTIME_CONTRACT_PASS',
      'LANGUAGE_LOCK_PHASE=COLLECTION_HELPER_EXECUTION_PASS',
      'LANGUAGE_LOCK_PHASE=COLLECTION_CONTRACT_PASS',
      'LANGUAGE_LOCK_PHASE=RESULT_MATERIALIZED',
    ] as $phase) {
      self::assertStringContainsString($phase, $runner, $phase);
    }

    foreach ([
      'LANGUAGE_LOCK_FAILURE=HELPER_EXISTENCE',
      'LANGUAGE_LOCK_FAILURE=RUNTIME_HELPER_EXECUTION',
      'LANGUAGE_LOCK_FAILURE=RUNTIME_CONTRACT',
      'LANGUAGE_LOCK_FAILURE=COLLECTION_HELPER_EXECUTION',
      'LANGUAGE_LOCK_FAILURE=COLLECTION_CONTRACT',
    ] as $failure) {
      self::assertStringContainsString($failure, $runner, $failure);
    }

    self::assertStringContainsString(
      '"$helper_gate" >/dev/null 2>/dev/null',
      $runner,
    );
    self::assertStringContainsString(
      '"$runtime_command" 2>/dev/null)',
      $runner,
    );
    self::assertStringContainsString(
      '"$collection_command" 2>/dev/null)',
      $runner,
    );
    self::assertStringNotContainsString(
      'echo "$runtime_language_raw"',
      $runner,
    );
    self::assertStringNotContainsString(
      'echo "$strict_collection_raw"',
      $runner,
    );
    self::assertStringContainsString('vendor/bin/drush php:script', $runner);
    self::assertStringContainsString('.drupal_core_version == "11.4.7"', $runner);
    self::assertStringContainsString('.canvas_version == "1.11.0"', $runner);
    self::assertStringContainsString('.config_language_lock_version == "1.0.2"', $runner);
    self::assertStringContainsString('.site_default_language == "fr"', $runner);
    self::assertStringContainsString('.locked_langcode == "fr"', $runner);
    self::assertStringContainsString('.follow_site_default == true', $runner);
    self::assertStringContainsString('.canvas_requirement_verdict == "PASS"', $runner);
    self::assertStringContainsString('.collections.fr.count == 7', $runner);
    self::assertStringContainsString('.collections.en.count == 418', $runner);
    self::assertStringContainsString(
      '528df3f930b3a5b0cc26e14cabf1e628e5e7d3da8fe19634ef870bb5855a80ca',
      $runner,
    );
    self::assertStringContainsString(
      '5123ea2664916fb9059165b7cd2657b14b382b3ae88d0d039648e7eb4cf57b2c',
      $runner,
    );
    self::assertStringContainsString(
      '31ff109065631edad357abbf9569f68d55dc81ac03feeb7fe99d173f00a14425',
      $runner,
    );
    self::assertStringContainsString(
      '7ab417ceafdd77939f8fdb12f042acb6fd9bcd54242e8da5cac074f4708d36ac',
      $runner,
    );
    self::assertStringContainsString('diagnostic_profile: "language_lock"', $runner);
    self::assertStringContainsString('runtime_language_policy', $runner . $workflow);
    self::assertStringContainsString('strict_collection_verify', $runner . $workflow);
    self::assertStringContainsString('config_values_exposed: false', $runner);
    self::assertStringContainsString('preprod_access: "READ_ONLY"', $runner);
    self::assertStringContainsString('preprod_write: "NONE"', $runner);
    self::assertStringContainsString('prod_access: "NONE"', $runner);

    foreach ([
      'vendor/bin/drush cim',
      'vendor/bin/drush cex',
      'vendor/bin/drush config:import',
      'vendor/bin/drush config:export',
      'vendor/bin/drush config:set',
      'vendor/bin/drush state:set',
      'vendor/bin/drush sql:query',
      'vendor/bin/drush sql:cli',
      'vendor/bin/drush sql:dump',
      'vendor/bin/drush cr',
      'vendor/bin/drush updb',
      'vendor/bin/drush deploy',
      'vendor/bin/drush pm:enable',
      'file_put_contents',
      'scp ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner, $forbidden);
    }
  }

  /**
   * Proves runtime mismatch evidence is bounded and deterministic.
   */
  public function testLanguageLockRuntimeMismatchEvidenceIsBoundedAndDeterministic(): void {
    $runner = $this->source(self::RUNNER);

    $begin = '# RUNTIME_CONTRACT_OBSERVABILITY_BEGIN';
    $end = '# RUNTIME_CONTRACT_OBSERVABILITY_END';
    $beginPosition = strpos($runner, $begin);
    $endPosition = strpos($runner, $end);
    self::assertNotFalse($beginPosition);
    self::assertNotFalse($endPosition);
    self::assertGreaterThan($beginPosition, $endPosition);

    $observability = substr(
      $runner,
      $beginPosition,
      $endPosition - $beginPosition + strlen($end),
    );

    preg_match_all(
      '/\\{path: "([^"]+)", ok:/',
      $observability,
      $pathMatches,
    );
    self::assertSame([
      'schema_version',
      'drupal_core_version',
      'canvas_version',
      'config_language_lock_version',
      'site_default_language',
      'locked_langcode',
      'follow_site_default',
      'canvas_requirement_verdict',
      'collections.fr.count',
      'collections.en.count',
      'languages.und.id',
      'languages.und.locked',
      'languages.und.technical_langcode',
      'languages.zxx.id',
      'languages.zxx.locked',
      'languages.zxx.technical_langcode',
      'config_values_exposed',
    ], $pathMatches[1]);

    self::assertSame(
      1,
      preg_match(
        '/def observed:\\s*(\\{.*?\\n      \\});\\s*\\[/s',
        $observability,
        $observedMatch,
      ),
    );
    $observed = $observedMatch[1];

    preg_match_all(
      '/:\\s*\\.([A-Za-z0-9_.]+)/',
      $observed,
      $selectorMatches,
    );
    self::assertSame([
      'schema_version',
      'drupal_core_version',
      'canvas_version',
      'config_language_lock_version',
      'site_default_language',
      'locked_langcode',
      'follow_site_default',
      'canvas_requirement_verdict',
      'collections.fr.count',
      'collections.en.count',
      'languages.und.id',
      'languages.und.locked',
      'languages.und.technical_langcode',
      'languages.zxx.id',
      'languages.zxx.locked',
      'languages.zxx.technical_langcode',
      'config_values_exposed',
    ], $selectorMatches[1]);

    foreach ([
      'active_names_sha256',
      'sync_names_sha256',
      'active_values_sha256',
      'sync_values_sha256',
      'raw_config_values',
      'settings',
      'ssh',
      'secret',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $observed, $forbidden);
    }

    foreach ([
      '.schema_version == 1',
      '.drupal_core_version == "11.4.7"',
      '.canvas_version == "1.11.0"',
      '.config_language_lock_version == "1.0.2"',
      '.site_default_language == "fr"',
      '.locked_langcode == "fr"',
      '.follow_site_default == true',
      '.canvas_requirement_verdict == "PASS"',
      '.collections.fr.count == 7',
      '.collections.en.count == 418',
      '.languages.und.id == "und"',
      '.languages.und.locked == true',
      '.languages.und.technical_langcode == "fr"',
      '.languages.zxx.id == "zxx"',
      '.languages.zxx.locked == true',
      '.languages.zxx.technical_langcode == "fr"',
      '.config_values_exposed == false',
    ] as $expectation) {
      self::assertStringContainsString($expectation, $observability, $expectation);
    }

    self::assertStringContainsString(
      'jq -c "$runtime_contract_jq" "$runtime_language_json" 2>/dev/null',
      $observability,
    );
    self::assertStringContainsString(
      'LANGUAGE_LOCK_FAILURE=RUNTIME_CONTRACT',
      $observability,
    );
    self::assertStringContainsString(
      'LANGUAGE_LOCK_RUNTIME_MISMATCH_FIELDS=INVALID_JSON',
      $observability,
    );
    self::assertStringContainsString(
      'LANGUAGE_LOCK_RUNTIME_MISMATCH_FIELDS=%s',
      $observability,
    );
    self::assertStringContainsString(
      'LANGUAGE_LOCK_RUNTIME_OBSERVED=%s',
      $observability,
    );
    self::assertStringContainsString(
      '.mismatches | join(",")',
      $observability,
    );
    self::assertStringContainsString(
      "jq -c '.observed'",
      $observability,
    );
    self::assertStringNotContainsString('runtime_language_raw', $observability);

    $failurePosition = strpos(
      $runner,
      'LANGUAGE_LOCK_RUNTIME_OBSERVED=%s',
    );
    $collectionPosition = strpos($runner, 'printf -v collection_command');
    self::assertNotFalse($failurePosition);
    self::assertNotFalse($collectionPosition);
    self::assertLessThan($collectionPosition, $failurePosition);

    $failureToCollection = substr(
      $runner,
      $failurePosition,
      $collectionPosition - $failurePosition,
    );
    self::assertStringContainsString('exit 1', $failureToCollection);
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
   * Proves historical PREPROD trust and path fences remain intact.
   */
  public function testRunnerIsFixedToPreprodAndHasNoArbitraryExecution(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'961\' || "$ISSUE_NUMBER" == \'982\' || "$ISSUE_NUMBER" == \'995\' || "$ISSUE_NUMBER" == \'1318\' ]]',
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
   * Proves the historical SHA command remains nounset-safe and exact.
   */
  public function testSettingsShaCommandSurvivesNounsetWithoutPositionalParameter(): void {
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
    self::assertSame(
      'set -euo pipefail; sha256sum ' .
      '\'/var/www/agency-preprod/shared/settings/settings.php\' | ' .
      'awk \'{print $1}\'' . "\n",
      $process->getOutput(),
    );
  }

  /**
   * Proves the Drupal getter and config status remain strictly read-only.
   */
  public function testDrupalGetterAndConfigStatusAreReadOnly(): void {
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
      'DRUPAL_STATUS_CONFIG_SYNC_WARNING',
      strtoupper($runner),
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
   * Restores historical evidence/security assertions and adds #982 metadata.
   */
  public function testEvidenceAndSecretBoundaryIsMetadataOnly(): void {
    $runner = $this->source(self::RUNNER);
    $workflow = $this->source(self::WORKFLOW);
    $filter = $this->source(self::FILTER);

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
    self::assertStringContainsString('readlink -f', $runner);
    self::assertStringContainsString('find', $runner);
    self::assertStringContainsString('"NOT_OBSERVABLE"', $runner);
    self::assertStringContainsString('preprod_mutation: "NONE"', $runner);
    self::assertStringContainsString('prod_access: "NONE"', $runner);
    self::assertStringContainsString('prod_write: "NONE"', $runner);

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

    foreach ([$runner, $workflow, $filter] as $surface) {
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

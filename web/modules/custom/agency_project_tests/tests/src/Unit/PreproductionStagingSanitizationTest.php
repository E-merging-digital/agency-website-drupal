<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the synthetic-only PREPROD staging sanitization boundary.
 *
 * @group agency_project_tests
 * @group preproduction_data_refresh
 */
final class PreproductionStagingSanitizationTest extends TestCase {

  /**
   * Every mandatory sensitive class has exactly one explicit handler.
   */
  public function testMandatorySensitiveClassesAreExactlyHandled(): void {
    $policy = $this->policy();
    $execution = $policy['sanitization_execution'];

    self::assertSame('SYNTHETIC_FIXTURE_ONLY', $execution['mode']);
    self::assertFalse($execution['real_runtime_enabled']);
    self::assertSame('FAIL_CLOSED', $execution['unknown_mandatory_class']);
    self::assertSame('NOT_IMPLEMENTED', $execution['unknown_table_scanning']);

    $mandatory = array_map(
      static fn (array $rule): string => $rule['id'],
      $policy['mandatory_sanitization'],
    );
    $handled = array_keys($execution['mandatory_class_handlers']);
    sort($mandatory);
    sort($handled);

    self::assertSame($mandatory, $handled);
    self::assertSame(
      'preprod-user-<uid>',
      $execution['users']['username_format'],
    );
    self::assertSame(
      'preprod-user+<uid>@example.invalid',
      $execution['users']['email_format'],
    );
    self::assertSame(
      'PREPROD_SERVER_OWNED',
      $execution['preprod_admin']['source'],
    );
    self::assertFalse($execution['preprod_admin']['restore_in_issue_855']);
  }

  /**
   * Project-specific sensitive tables and runtime state stay explicit.
   */
  public function testSensitiveSchemaMappingIsExplicitAndBounded(): void {
    $execution = $this->policy()['sanitization_execution'];

    self::assertSame(
      ['webform_submission', 'webform_submission_data'],
      $execution['purge_tables_by_class']['webform_submissions'],
    );
    self::assertSame(
      ['sessions'],
      $execution['purge_tables_by_class']['sessions'],
    );
    self::assertSame(
      ['flood'],
      $execution['purge_tables_by_class']['flood_rate_limit'],
    );
    self::assertSame(
      ['watchdog'],
      $execution['purge_tables_by_class']['dblog_watchdog'],
    );
    self::assertSame(
      ['batch', 'semaphore', 'key_value_expire'],
      $execution['purge_tables_by_class']['batch_temp_state'],
    );
    self::assertSame(
      ['queue'],
      $execution['purge_tables_by_class']['queues'],
    );
    self::assertSame(['cache_'], $execution['cache_table_prefixes']);
    self::assertSame(
      'ENV_PROVIDER_ONLY',
      $execution['credential_config']['observed_repository_key_source'],
    );
  }

  /**
   * The proof executable cannot be repointed at a real database or host.
   */
  public function testProofExecutableHasNoRealRuntimeRoute(): void {
    $script = $this->script();

    self::assertStringContainsString('sqlite3.connect(":memory:")', $script);
    self::assertStringContainsString('if argv != ["PROVE"]:', $script);
    self::assertStringContainsString('REAL_PROD_ACCESS', $script);
    self::assertStringContainsString('REAL_PREPROD_DB_MUTATION', $script);
    self::assertStringContainsString('REAL_SANITIZATION', $script);

    foreach ([
      'import argparse',
      'import os',
      'import socket',
      'import subprocess',
      'paramiko',
      'pymysql',
      'mysql.connector',
      'requests.',
      'urllib.request',
      '--host',
      '--database',
      '--password',
      'shell=True',
      'os.system(',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The observed Agency OpenAI key remains environment-backed, not persisted.
   */
  public function testObservedOpenAiKeyStorageIsEnvironmentOwned(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/config/sync/key.key.openai_api_key.yml';
    self::assertFileExists($path);

    $key = Yaml::parseFile($path);
    self::assertIsArray($key);
    self::assertSame('env', $key['key_provider']);
    self::assertSame(
      'OPENAI_API_KEY',
      $key['key_provider_settings']['env_variable'],
    );
    self::assertSame('none', $key['key_input']);
  }

  /**
   * The targeted validation publishes no raw fixture or secret artifact.
   */
  public function testValidationWorkflowIsSyntheticMetadataOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/preprod-staging-sanitization-validation.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('runs-on: ubuntu-24.04', $workflow);
    self::assertStringContainsString(
      'python3 scripts/preproduction-refresh/sanitize-staging-fixture.py PROVE',
      $workflow,
    );
    self::assertStringNotContainsString('${{ secrets.', $workflow);
    self::assertStringNotContainsString('upload-artifact', $workflow);
    self::assertStringNotContainsString('self-hosted', $workflow);
    self::assertStringNotContainsString('ssh ', $workflow);
    self::assertStringNotContainsString('mariadb ', $workflow);
    self::assertStringNotContainsString('mysql ', $workflow);
  }

  /**
   * Returns the decoded sanitization policy.
   *
   * @return array<string, mixed>
   *   The sanitization policy.
   */
  private function policy(): array {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/preproduction-refresh/sanitization-policy.json';
    self::assertFileExists($path);

    $policy = json_decode(
      (string) file_get_contents($path),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($policy);

    return $policy;
  }

  /**
   * Returns the synthetic-only fixture proof executable.
   */
  private function script(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/preproduction-refresh/sanitize-staging-fixture.py';
    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

}

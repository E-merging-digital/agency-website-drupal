<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the #857 ephemeral MariaDB sanitization boundary.
 *
 * @group agency_project_tests
 * @group preproduction_data_refresh
 */
final class PreproductionMariaDbStagingSanitizationTest extends TestCase {

  /**
   * The sole #816 policy remains authoritative and runtime-disabled.
   */
  public function testMariaDbProofUsesSoleSanitizationPolicy(): void {
    $policy = $this->policy();
    $execution = $policy['sanitization_execution'];
    self::assertIsArray($execution);

    self::assertSame(
      'agency-preprod-refresh-v1',
      $policy['policy_version'],
    );
    self::assertSame('SYNTHETIC_FIXTURE_ONLY', $execution['mode']);
    self::assertFalse($execution['real_runtime_enabled']);

    $proof = $execution['mariadb_proof'];
    self::assertIsArray($proof);
    self::assertSame('EPHEMERAL_SYNTHETIC_CI_ONLY', $proof['mode']);
    self::assertSame('11.8', $proof['mariadb_version']);
    self::assertFalse($proof['real_runtime_enabled']);
    self::assertSame('FORBIDDEN', $proof['raw_prod_data']);
    self::assertSame('FORBIDDEN', $proof['real_preprod_connection']);

    $target = $proof['target_database'];
    self::assertIsArray($target);
    self::assertSame('agency_preprod', $target['runtime_database']);
    self::assertFalse($target['runtime_targetable']);
    self::assertSame('agency_preprod_stage_', $target['staging_prefix']);
    self::assertSame(
      'SHA256_REQUEST_ID_FIRST_12_HEX',
      $target['derivation'],
    );
    self::assertSame('FORBIDDEN', $target['caller_database_name']);

    $callerInputs = $proof['caller_inputs'];
    self::assertIsArray($callerInputs);
    foreach ($callerInputs as $value) {
      self::assertSame('FORBIDDEN', $value);
    }

    $executionBoundary = $policy['execution_boundary'];
    self::assertIsArray($executionBoundary);
    $githubHosted = $executionBoundary['github_hosted'];
    self::assertIsArray($githubHosted);
    self::assertContains(
      'SYNTHETIC_EPHEMERAL_DB_VALIDATION',
      $githubHosted['allowed_roles'],
    );
    self::assertFalse($githubHosted['raw_prod_data_allowed']);
  }

  /**
   * The proof executable exposes no target, SQL or generic shell input.
   */
  public function testMariaDbProofExecutableIsFixedPurposeOnly(): void {
    $script = $this->script();

    foreach ([
      'if argv != ["PROVE"]',
      'MARIADB_BIN = "/usr/bin/mariadb"',
      'MARIADB_HOST = "127.0.0.1"',
      'MARIADB_PORT = "3306"',
      'RUNTIME_DB = "agency_preprod"',
      'STAGING_PREFIX = "agency_preprod_stage_"',
      'runtime database is outside sanitization authority',
      'caller_database_name',
      'REAL_PREPROD_DB_MUTATION',
      'REAL_HELPER_PROVISIONING',
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }

    foreach ([
      'argparse',
      'shell=True',
      'os.system(',
      'eval(',
      'exec(',
      '/usr/local/sbin/agency-preprod-staging-db',
      'ssh ',
      'scp ',
      'rsync ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * MariaDB validation uses the repository DDEV database version.
   */
  public function testWorkflowUsesEphemeralMariaDb118Only(): void {
    $workflow = $this->workflow();
    $ddev = $this->ddevConfig();
    $database = $ddev['database'];
    self::assertIsArray($database);

    self::assertSame('mariadb', $database['type']);
    self::assertSame('11.8', (string) $database['version']);
    self::assertStringContainsString('image: mariadb:11.8', $workflow);
    self::assertStringContainsString(
      'runs-on: ubuntu-24.04',
      $workflow,
    );
    self::assertStringContainsString(
      'sanitize-staging-mariadb-proof.py PROVE',
      $workflow,
    );
    self::assertStringContainsString(
      'INCOMPATIBLE_SENSITIVE_SCHEMA_FAIL_CLOSED=PASS',
      $workflow,
    );
    self::assertStringContainsString(
      'RUNTIME_DB_TARGET_REJECTION=PASS',
      $workflow,
    );
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('${{ secrets.', $workflow);
    self::assertStringNotContainsString('upload-artifact', $workflow);
  }

  /**
   * #849/#851 installed-helper authority stays unchanged by #857.
   */
  public function testExistingPrivilegedHelperContractIsUnchanged(): void {
    $root = dirname(DRUPAL_ROOT);
    $helper = $root
      . '/scripts/preproduction-staging-import/privileged/'
      . 'agency-preprod-staging-db';
    $digest = $root
      . '/scripts/preproduction-staging-import/privileged/'
      . 'agency-preprod-staging-db.sha256';
    $capability = $root
      . '/scripts/preproduction-staging-import/privileged/'
      . 'capability.json';
    $provisioning = $root
      . '/scripts/preproduction-staging-import/provisioning/profile.json';

    self::assertFileExists($helper);
    self::assertFileExists($digest);

    $expectedDigest = trim((string) file_get_contents($digest));
    $actualDigest = hash_file('sha256', $helper);
    self::assertIsString($actualDigest);
    self::assertSame($expectedDigest, $actualDigest);
    self::assertSame(
      'ddd2785849da76f3a30dd3cf0ac59d03ed53692ad1e5cd03480a82d86d63a6a3',
      $expectedDigest,
    );

    $capabilityData = json_decode(
      (string) file_get_contents($capability),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($capabilityData);
    self::assertSame(
      ['PRECHECK', 'IMPORT', 'CLEANUP', 'VERIFY_ABSENCE'],
      $capabilityData['actions'],
    );
    self::assertFalse(
      $capabilityData['database_scope']['runtime_targetable'],
    );
    self::assertSame(
      'FORBIDDEN',
      $capabilityData['sudo']['direct_mariadb'],
    );
    self::assertSame(
      'FORBIDDEN',
      $capabilityData['sudo']['generic_shell'],
    );

    $provisioningData = json_decode(
      (string) file_get_contents($provisioning),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($provisioningData);
    self::assertSame(
      $expectedDigest,
      $provisioningData['helper']['expected_sha256'],
    );
    self::assertSame(
      'FIXED_HELPER_ONLY',
      $provisioningData['sudoers']['nopasswd_scope'],
    );
    self::assertSame(
      'FORBIDDEN',
      $provisioningData['sudoers']['direct_mariadb'],
    );
  }

  /**
   * Returns the authoritative sanitization policy.
   *
   * @return array<string, mixed>
   *   Decoded policy.
   */
  private function policy(): array {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/preproduction-refresh/sanitization-policy.json';
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
   * Returns the MariaDB proof executable.
   */
  private function script(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/preproduction-refresh/'
      . 'sanitize-staging-mariadb-proof.py';
    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

  /**
   * Returns the targeted workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'preprod-staging-mariadb-sanitization-validation.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

  /**
   * Returns DDEV configuration.
   *
   * @return array<string, mixed>
   *   Parsed DDEV config.
   */
  private function ddevConfig(): array {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.ddev/config.yaml';
    self::assertFileExists($path);
    $config = Yaml::parseFile($path);
    self::assertIsArray($config);

    return $config;
  }

}

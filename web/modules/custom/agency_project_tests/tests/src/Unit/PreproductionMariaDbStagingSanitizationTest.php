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
   * Guards the exact governed #859 helper revision.
   */
  public function testPrivilegedHelperRevisionRemainsGoverned(): void {
    $root = dirname(DRUPAL_ROOT);
    $privileged = $root . '/scripts/preproduction-staging-import/privileged';
    $helper = $privileged . '/agency-preprod-staging-db';
    $digest = $privileged . '/agency-preprod-staging-db.sha256';
    $sanitizerDigest = $privileged
      . '/agency-preprod-staging-sanitizer.py.sha256';
    $policyDigest = $privileged . '/sanitization-policy.sha256';
    $capability = $privileged . '/capability.json';
    $bundle = $privileged . '/bundle.json';
    $provisioning = $root
      . '/scripts/preproduction-staging-import/provisioning/profile.json';

    foreach ([
      $helper,
      $digest,
      $sanitizerDigest,
      $policyDigest,
      $capability,
      $bundle,
      $provisioning,
    ] as $path) {
      self::assertFileExists($path);
    }

    $expectedDigest = trim((string) file_get_contents($digest));
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $expectedDigest);
    $actualDigest = hash_file('sha256', $helper);
    self::assertIsString($actualDigest);
    self::assertSame($expectedDigest, $actualDigest);

    $expectedSanitizerDigest = trim((string) file_get_contents($sanitizerDigest));
    $expectedPolicyDigest = trim((string) file_get_contents($policyDigest));
    self::assertMatchesRegularExpression(
      '/^[0-9a-f]{64}$/',
      $expectedSanitizerDigest,
    );
    self::assertMatchesRegularExpression(
      '/^[0-9a-f]{64}$/',
      $expectedPolicyDigest,
    );

    $capabilityData = json_decode(
      (string) file_get_contents($capability),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($capabilityData);
    self::assertSame(849, $capabilityData['issue_number']);
    self::assertSame(859, $capabilityData['revision_issue_number']);
    self::assertSame(
      'FIXED_ROOT_OWNED_BOUNDED_HELPER',
      $capabilityData['model'],
    );
    self::assertSame(
      [
        'PRECHECK',
        'IMPORT',
        'IMPORT_SANITIZE_PROVE',
        'CLEANUP',
        'VERIFY_ABSENCE',
      ],
      $capabilityData['actions'],
    );
    self::assertNotContains('SANITIZE', $capabilityData['actions']);
    self::assertSame(
      'IMPORT_SANITIZE_PROVE',
      $capabilityData['one_shot']['action'],
    );
    self::assertSame(
      'FORBIDDEN',
      $capabilityData['one_shot']['unsanitized_persistence_between_caller_actions'],
    );
    foreach ([
      'cleanup_on_success',
      'cleanup_on_import_failure',
      'cleanup_on_sanitization_failure',
      'cleanup_on_assertion_failure',
      'cleanup_on_handled_signal',
    ] as $cleanupKey) {
      self::assertSame(
        'MANDATORY',
        $capabilityData['one_shot'][$cleanupKey],
      );
    }
    self::assertSame(
      'METADATA_ONLY',
      $capabilityData['one_shot']['evidence'],
    );
    self::assertSame(
      'agency_preprod',
      $capabilityData['database_scope']['runtime_database'],
    );
    self::assertFalse(
      $capabilityData['database_scope']['runtime_targetable'],
    );
    foreach ($capabilityData['caller_inputs'] as $value) {
      self::assertSame('FORBIDDEN', $value);
    }
    self::assertSame(
      'FORBIDDEN',
      $capabilityData['sudo']['direct_mariadb'],
    );
    self::assertSame(
      'FORBIDDEN',
      $capabilityData['sudo']['generic_shell'],
    );
    self::assertSame('FORBIDDEN', $capabilityData['sudo']['setenv']);
    self::assertSame(
      'FORBIDDEN',
      $capabilityData['root_owned_bundle']['mutable_checkout_runtime_read'],
    );
    self::assertSame(
      $expectedSanitizerDigest,
      $capabilityData['root_owned_bundle']['sanitizer_sha256'],
    );
    self::assertSame(
      $expectedPolicyDigest,
      $capabilityData['root_owned_bundle']['policy_sha256'],
    );
    self::assertSame(
      'NOT_PERFORMED_IN_ISSUE_859',
      $capabilityData['real_preprod_provisioning'],
    );

    $bundleData = json_decode(
      (string) file_get_contents($bundle),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($bundleData);
    self::assertSame(859, $bundleData['revision_issue_number']);
    self::assertSame($expectedDigest, $bundleData['files']['helper']['sha256']);
    self::assertSame(
      $expectedSanitizerDigest,
      $bundleData['files']['sanitizer']['sha256'],
    );
    self::assertSame(
      $expectedPolicyDigest,
      $bundleData['files']['policy']['sha256'],
    );
    self::assertSame(
      'FORBIDDEN',
      $bundleData['mutable_checkout_runtime_read'],
    );
    self::assertFalse($bundleData['sudoers']['change_required']);
    self::assertFalse($bundleData['real_provisioning_performed']);

    $provisioningData = json_decode(
      (string) file_get_contents($provisioning),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($provisioningData);
    self::assertSame(859, $provisioningData['revision_issue_number']);
    self::assertSame(
      $expectedDigest,
      $provisioningData['helper']['expected_sha256'],
    );
    self::assertSame(
      $expectedSanitizerDigest,
      $provisioningData['bundle']['sanitizer']['expected_sha256'],
    );
    self::assertSame(
      $expectedPolicyDigest,
      $provisioningData['bundle']['policy']['expected_sha256'],
    );
    self::assertSame(
      'FIXED_HELPER_ONLY',
      $provisioningData['sudoers']['nopasswd_scope'],
    );
    self::assertFalse(
      $provisioningData['sudoers']['change_required_for_issue_859'],
    );
    self::assertSame(
      'FORBIDDEN',
      $provisioningData['sudoers']['direct_mariadb'],
    );
    self::assertSame(
      'FORBIDDEN',
      $provisioningData['sudoers']['shell'],
    );
    self::assertSame(
      'FORBIDDEN',
      $provisioningData['apply']['import_sanitize_prove'],
    );
    self::assertFalse(
      $provisioningData['real_provisioning_performed_in_issue_859'],
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

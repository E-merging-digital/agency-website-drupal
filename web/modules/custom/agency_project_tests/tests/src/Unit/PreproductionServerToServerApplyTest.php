<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the temporary #938 controlled server-to-server APPLY route.
 *
 * @group agency_project_tests
 * @group preproduction_data_refresh
 */
final class PreproductionServerToServerApplyTest extends TestCase {

  /**
   * APPLY uses GitHub-hosted only as a control plane after JIT.
   */
  public function testHostedControlPlaneContainsNoRawProdPath(): void {
    $workflow = $this->source('.github/workflows/preprod-914-governed-successor.yml');
    self::assertIsArray(Yaml::parse($workflow));
    $apply = "  apply:\n" . explode("\n  apply:\n", $workflow, 2)[1];

    self::assertStringContainsString('runs-on: ubuntu-24.04', $apply);
    self::assertStringContainsString('${{ runner.environment }}', $apply);
    self::assertStringContainsString(
      '[[ "$RUNNER_ENVIRONMENT" == github-hosted ]]',
      $apply,
    );
    self::assertStringNotContainsString(
      'runs-on: [self-hosted, linux, x64, agency]',
      $apply,
    );
    $jit = strpos($apply, 'JIT revalidate before APPLY SSH secrets');
    $secret = strpos($apply, 'secrets.SSH_PRIVATE_KEY');
    self::assertIsInt($jit);
    self::assertIsInt($secret);
    self::assertLessThan($secret, $jit);
    self::assertStringContainsString(
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      $apply,
    );
    self::assertStringContainsString(
      'run-server-to-server-apply.sh',
      $apply,
    );
    self::assertStringNotContainsString(
      'run: bash scripts/preproduction-refresh/governed-successor/run-apply.sh',
      $apply,
    );

    $control = $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'run-server-to-server-apply.sh',
    );
    self::assertStringContainsString(
      'RAW_PROD_ON_GITHUB_HOSTED=NONE',
      $control,
    );
    self::assertStringContainsString(
      'RAW_PROD_ROUTE=PROD_TO_PREPROD_DIRECT',
      $control,
    );
    self::assertStringContainsString('StrictHostKeyChecking=yes', $control);
    self::assertStringNotContainsString('ssh-keyscan', strtolower($control));
    self::assertStringNotContainsString('prod_ssh=(', $control);
    self::assertStringNotContainsString(
      '"$PROD_SSH_USER@$PROD_SSH_HOST"',
      $control,
    );
    self::assertStringNotContainsString('.sql', $control);
  }

  /**
   * Raw PROD is streamed only inside PREPROD isolated staging.
   */
  public function testRawProdRouteIsDirectAndRequestScoped(): void {
    $worker = $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'remote-server-to-server-worker.py',
    );

    foreach ([
      'HELPER_PATH = Path("/usr/local/sbin/agency-preprod-staging-db")',
      'EXPECTED_HELPER_SHA256',
      'controlled_server_to_server_allowed(policy)',
      'RAW_DATA_NEVER_TRANSITS_OR_MATERIALIZES_ON_GITHUB_HOSTED_INFRASTRUCTURE',
      'scripts/production-readonly-snapshot/remote-stream.sh',
      '"/usr/bin/ssh"',
      '"StrictHostKeyChecking=yes"',
      'import_snapshot_stream(helper, scope, client_file, snapshot.stdout)',
      'helper.prepare_import_scope(scope)',
      'helper.cleanup_scope(scope)',
      'helper.require_absent(scope)',
      'prod-read.key',
      '0o600',
    ] as $required) {
      self::assertStringContainsString($required, $worker);
    }
    self::assertStringNotContainsString('raw.sql', strtolower($worker));
    self::assertStringNotContainsString('raw_dump', strtolower($worker));
    self::assertStringContainsString(
      'stderr=subprocess.DEVNULL',
      $worker,
    );
  }

  /**
   * The existing single policy and sanitizer cover the #914 outcome.
   */
  public function testExistingSanitizerSemanticCoverageIsPreserved(): void {
    $worker = $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'remote-server-to-server-worker.py',
    );
    $sanitizer = $this->source(
      'scripts/preproduction-staging-import/privileged/'
      . 'agency-preprod-staging-sanitizer.py',
    );
    $policy = json_decode(
      $this->source('scripts/preproduction-refresh/sanitization-policy.json'),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($policy);
    self::assertSame('agency-preprod-refresh-v1', $policy['policy_version']);
    self::assertFalse(
      $policy['execution_boundary']['github_hosted']['raw_prod_data_allowed'],
    );

    $paths = [];
    foreach ($policy['execution_boundary']['raw_prod_data']['allowed_paths'] as $path) {
      $paths[$path['type']] = $path;
    }
    self::assertSame(
      'RAW_DATA_NEVER_TRANSITS_OR_MATERIALIZES_ON_GITHUB_HOSTED_INFRASTRUCTURE',
      $paths['CONTROLLED_SERVER_TO_SERVER']['requirement'],
    );

    foreach ([
      'user_sanitization',
      'auth_material_invalidation',
      'webform_submissions_purge',
      'sessions_purge',
      'flood_rate_limit_purge',
      'dblog_watchdog_purge',
      'batch_temp_state_purge',
      'queues_purge',
      'cache_purge',
      'runtime_state_reset',
      'credential_state_removal',
    ] as $evidence) {
      self::assertStringContainsString($evidence, $worker);
      self::assertStringContainsString($evidence, $sanitizer);
    }
    foreach ([
      'preprod-user-',
      'webform_submission',
      'sessions',
      'flood',
      'watchdog',
      'queue',
      'cache_',
      'system.cron_last',
      'announcements_feed.',
      'linkchecker.',
      'ai_provider_openai.settings',
      'key.key.',
    ] as $semantic) {
      self::assertStringContainsString($semantic, $sanitizer);
    }
  }

  /**
   * Sanitized export precedes the unchanged activation/rollback worker.
   */
  public function testSanitizedOnlyActivationReusesExistingWorker(): void {
    $worker = $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'remote-server-to-server-worker.py',
    );
    $activation = $this->source(
      'scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh',
    );

    $assertion = strpos($worker, 'sanitizer.assert_sanitized');
    $dump = strpos($worker, 'MARIADB_DUMP');
    $cleanup = strpos($worker, 'shutil.rmtree(stage)');
    $handoff = strpos($worker, 'os.execv(');
    foreach ([$assertion, $dump, $cleanup, $handoff] as $offset) {
      self::assertIsInt($offset);
    }
    self::assertLessThan($dump, $assertion);
    self::assertLessThan($handoff, $cleanup);
    self::assertStringContainsString('remote-apply-worker.sh', $worker);

    $backup = strpos(
      $activation,
      'sql:dump --no-interaction --result-file="$BACKUP"',
    );
    $maintenance = strpos($activation, 'maint:set 1');
    $drop = strpos($activation, 'sql:drop -y', $maintenance);
    self::assertIsInt($backup);
    self::assertIsInt($maintenance);
    self::assertIsInt($drop);
    self::assertLessThan($maintenance, $backup);
    self::assertLessThan($drop, $maintenance);
    self::assertStringContainsString('sql:cli < "$BACKUP"', $activation);
    self::assertStringContainsString('HUMAN_RECOVERY_REQUIRED', $activation);
    self::assertStringContainsString('admin-reconcile.php', $activation);
    self::assertStringNotContainsString('#915', $worker);
    self::assertStringNotContainsString('#917', $worker);
  }

  /**
   * Reads a repository source file.
   */
  private function source(string $relativePath): string {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
  }

}

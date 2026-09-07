<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the fixed #1075 PREPROD EN-503 read-only diagnostic route.
 *
 * @group agency_project_tests
 * @group preprod_en_503_diagnostic
 */
final class PreprodEn503Diagnostic1075WorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/preprod-en-503-diagnostic-1075.yml';
  private const RUNNER = 'scripts/runner/run-preprod-en-503-diagnostic-1075.sh';

  /**
   * Proves the future runtime surface is workflow-dispatch only and exact-main.
   */
  public function testWorkflowIsFixedWorkflowDispatchOnlyAndExactMain(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);

    self::assertSame(['workflow_dispatch'], array_keys($workflow['on'] ?? []));
    $inputs = $workflow['on']['workflow_dispatch']['inputs'] ?? [];
    self::assertSame(['request_id', 'head_sha'], array_keys($inputs));

    self::assertStringContainsString(
      "github.event_name == 'workflow_dispatch'",
      $source,
    );
    self::assertStringContainsString(
      "github.ref == 'refs/heads/main'",
      $source,
    );
    self::assertStringContainsString(
      "test '\${{ github.sha }}' = \"\$EXPECTED_HEAD_SHA\"",
      $source,
    );
    self::assertStringContainsString(
      'gh api "repos/$GITHUB_REPOSITORY/branches/main"',
      $source,
    );
    self::assertStringContainsString(
      'JIT revalidate live main before PREPROD identities',
      $source,
    );
    self::assertStringContainsString(
      'persist-credentials: false',
      $source,
    );

    foreach ([
      'command:',
      'shell_command:',
      'script:',
      'script_path:',
      'url:',
      'host:',
      'hostname:',
      'remote_path:',
      'drush_command:',
      'language:',
      'contract:',
      'release:',
      'release_path:',
      'log_range:',
      'time_range:',
    ] as $forbiddenInput) {
      self::assertArrayNotHasKey(
        rtrim($forbiddenInput, ':'),
        $inputs,
        $forbiddenInput,
      );
    }
  }

  /**
   * Proves credentials are PREPROD-only and appear only after JIT.
   */
  public function testWorkflowReusesPreprodIdentityAndPinnedTrustOnly(): void {
    $source = $this->source(self::WORKFLOW);

    self::assertStringContainsString('PREPROD_SSH_PRIVATE_KEY', $source);
    self::assertStringContainsString('PREPROD_SERVER_HOST', $source);
    self::assertStringContainsString('PREPROD_BASIC_AUTH_USER', $source);
    self::assertStringContainsString('PREPROD_BASIC_AUTH_PASSWORD', $source);
    self::assertStringContainsString(
      'scripts/preproduction-ssh-trust/manage-known-host.sh',
      $source,
    );
    self::assertStringContainsString(
      'scripts/preproduction-staging-import/verify-preprod-pinned-trust.sh',
      $source,
    );
    self::assertStringContainsString('StrictHostKeyChecking', $this->source(self::RUNNER));

    $jit = strpos($source, 'JIT revalidate live main before PREPROD identities');
    $identity = strpos($source, 'Materialize transient PREPROD SSH identity after JIT');
    self::assertIsInt($jit);
    self::assertIsInt($identity);
    self::assertLessThan($identity, $jit);

    self::assertStringNotContainsString('PROD_SSH_PRIVATE_KEY', $source);
    self::assertStringNotContainsString('PROD_SERVER_HOST', $source);
    self::assertStringNotContainsString('SERVER_USER', $source);
    self::assertStringNotContainsString('agency-command-dispatch', $source);
  }

  /**
   * Proves the fixed runner supports observations A-G and bounded H metadata.
   */
  public function testRunnerSupportsRequiredReadOnlyObservations(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      '/var/www/agency-preprod/releases/20260906233627-0cfc408a0be1',
      $runner,
    );
    self::assertStringContainsString(
      'readlink -f "$CURRENT"',
      $runner,
    );
    self::assertStringContainsString('system.maintenance_mode', $runner);
    self::assertStringContainsString(
      'vendor/bin/drush status --field=bootstrap',
      $runner,
    );

    self::assertStringContainsString(
      "PREPROD_ORIGIN='https://preprod.emergingdigital.be'",
      $runner,
    );
    self::assertStringContainsString("probe_external external_fr '/fr'", $runner);
    self::assertStringContainsString("probe_external external_en '/en'", $runner);
    self::assertStringContainsString(
      "RESOLVE='preprod.emergingdigital.be:443:127.0.0.1'",
      $runner,
    );
    self::assertStringContainsString("probe_local local_fr '/fr'", $runner);
    self::assertStringContainsString("probe_local local_en '/en'", $runner);
    self::assertStringContainsString('body_sha256', $runner);
    self::assertStringContainsString('x_drupal_cache', $runner);

    self::assertStringContainsString('2026-09-07T14:32:45Z', $runner);
    self::assertStringContainsString('2026-09-07T14:32:55Z', $runner);
    self::assertStringContainsString("tableExists('watchdog')", $runner);
    self::assertStringContainsString("'status' => 'UNOBSERVABLE'", $runner);
    self::assertStringContainsString("'rows_scanned' => 0", $runner);

    self::assertStringContainsString(
      '\\Drupal::languageManager()->getLanguages()',
      $runner,
    );
    self::assertStringContainsString(
      '\\Drupal::languageManager()->getDefaultLanguage()->getId()',
      $runner,
    );
    self::assertStringContainsString(
      "\\Drupal::config('system.site')->get('page.front')",
      $runner,
    );
    self::assertStringContainsString("getStorage('node')->load(5)", $runner);
    self::assertStringContainsString("hasTranslation('en')", $runner);

    self::assertStringContainsString("cache_evidence='NOT_NEEDED'", $runner);
    self::assertStringContainsString(
      "cache_evidence='RESPONSE_HEADERS_ONLY'",
      $runner,
    );
  }

  /**
   * Proves all classifications exist while G remains the default.
   */
  public function testClassificationContractIsCompleteAndFailClosed(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString("classification='G'", $runner);
    self::assertStringContainsString(
      "classification_label='INSUFFICIENT_EVIDENCE'",
      $runner,
    );

    foreach ([
      'A: "GLOBAL_DRUPAL_MAINTENANCE_STATE"',
      'B: "LANGUAGE_SPECIFIC_DRUPAL_RUNTIME_DEFECT"',
      'C: "STALE_APPLICATION_CACHE_FOR_EN"',
      'D: "WEB_TIER_OR_EDGE_EN_SPECIFIC_503"',
      'E: "HOMEPAGE_EN_TRANSLATION_OR_ROUTE_CAUSES_MAINTENANCE_RESPONSE"',
      'F: "OTHER_PROVEN_RUNTIME_DEFECT"',
      'G: "INSUFFICIENT_EVIDENCE"',
    ] as $classification) {
      self::assertStringContainsString($classification, $runner);
    }
  }

  /**
   * Proves the repository-owned diagnostic has no mutation capability.
   */
  public function testRunnerContainsNoRuntimeMutationSurface(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString('preprod_write: "NONE"', $runner);
    self::assertStringContainsString('mutation_capability: "NONE"', $runner);
    self::assertStringContainsString('prod_access: "NONE"', $runner);
    self::assertStringContainsString('prod_write: "NONE"', $runner);

    foreach ([
      'state:set',
      'drush cr',
      'cache:rebuild',
      'cache:clear',
      'cache:delete',
      'config:import',
      'config:set',
      'entity:save',
      '->save(',
      '->delete(',
      'ln -s',
      'ln -sfn',
      'systemctl restart',
      'systemctl reload',
      'service restart',
      'service reload',
      'sql:query',
      'sql:cli',
      'sql:dump',
      'sql:sync',
      '/var/www/agency/current',
      '/var/www/agency/shared',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner, $forbidden);
    }

    self::assertStringNotContainsString('StrictHostKeyChecking=no', $runner);
    self::assertStringNotContainsString('accept-new', $runner);
    self::assertStringNotContainsString('ssh-keyscan', $runner);
  }

  /**
   * Proves the shell itself is syntactically valid.
   */
  public function testRunnerShellSyntax(): void {
    $path = dirname(DRUPAL_ROOT) . '/' . self::RUNNER;
    $output = [];
    $status = 1;
    exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
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

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects #995 reuse of the existing read-only runtime routes.
 *
 * @group agency_project_tests
 * @group canvas_runtime_diff_paths_995
 */
final class CanvasRuntimeDiffPaths995WorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const PREPROD_WORKFLOW = '.github/workflows/config-sync-runtime-diagnostic.yml';
  private const PROD_WORKFLOW = '.github/workflows/prod-config-sync-runtime-diagnostic.yml';
  private const PREPROD_RUNNER = 'scripts/runner/run-config-sync-runtime-diagnostic-961.sh';
  private const PROD_RUNNER = 'scripts/runner/run-prod-config-sync-runtime-diagnostic-980.sh';
  private const PROBE = 'scripts/runner/canvas-runtime-diff-paths-995.php';
  private const TARGETED = '.github/workflows/canvas-runtime-diff-paths-995-validation.yml';

  /**
   * Existing commands are reused and routed only for #982 or #995.
   */
  public function testDispatcherReusesExactRoutesForIssue995(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $raw = $dispatcher['env']['AGENCY_COMMAND_ROUTES'] ?? NULL;
    self::assertIsString($raw);
    $routes = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertCount(14, $routes);

    $byName = array_column($routes, NULL, 'route');
    self::assertSame(
      ['/agency-config-sync-runtime diagnose'],
      $byName['CONFIG_SYNC_RUNTIME_DIAGNOSTIC']['exact'] ?? NULL,
    );
    self::assertSame(
      ['/agency-config-sync-prod-runtime diagnose'],
      $byName['PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC']['exact'] ?? NULL,
    );

    $source = $this->source(self::DISPATCHER);
    self::assertStringContainsString(
      "'CONFIG_SYNC_RUNTIME_DIAGNOSTIC': ('982', '995')",
      $source,
    );
    self::assertStringContainsString(
      "'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC': ('982', '995')",
      $source,
    );
    self::assertStringContainsString('if isinstance(required_issue, tuple):', $source);
    self::assertStringContainsString('if issue not in required_issue:', $source);
  }

  /**
   * Both reusable workflows authorize #995 while remaining non-operational on PR.
   */
  public function testReusableWorkflowsRemainPrSafeAndIssueBound(): void {
    foreach ([self::PREPROD_WORKFLOW, self::PROD_WORKFLOW] as $path) {
      $workflow = $this->parsed($path);
      $source = $this->source($path);
      $on = $workflow['on'] ?? NULL;
      self::assertIsArray($on);
      self::assertArrayHasKey('workflow_call', $on);
      self::assertArrayHasKey('pull_request', $on);
      self::assertArrayNotHasKey('issue_comment', $on);

      $static = $workflow['jobs']['static-validation'] ?? NULL;
      self::assertIsArray($static);
      self::assertSame('ubuntu-24.04', $static['runs-on'] ?? NULL);
      self::assertArrayNotHasKey('secrets', $static);
      $staticSurface = json_encode($static, JSON_THROW_ON_ERROR);
      self::assertStringNotContainsString('ssh ', $staticSurface);
      self::assertStringNotContainsString('scp ', $staticSurface);

      self::assertStringContainsString('github.event.issue.number == 982', $source);
      self::assertStringContainsString('github.event.issue.number == 995', $source);
      self::assertStringContainsString('5528251064', $source);
      self::assertStringContainsString('5529562346', $source);
      self::assertStringContainsString("DIAGNOSTIC_PROFILE:", $source);
      self::assertStringContainsString("'canvas_paths'", $source);
      self::assertStringContainsString('runtime_canvas_paths', $source);
      self::assertStringContainsString(
        'environment + config_name + differing_paths[] + classification',
        $source,
      );
      self::assertStringContainsString('config_values_exposed == false', $source);
    }
  }

  /**
   * The reused runners retain fixed trust/paths and add no write capability.
   */
  public function testRunnersReuseTrustAndStayReadOnly(): void {
    $preprod = $this->source(self::PREPROD_RUNNER);
    $prod = $this->source(self::PROD_RUNNER);

    self::assertStringContainsString('/var/www/agency-preprod/current', $preprod);
    self::assertStringContainsString('agency-preprod@$PREPROD_SERVER_HOST', $preprod);
    self::assertStringContainsString(
      'scripts/preproduction-ssh-trust/manage-known-host.sh',
      $preprod,
    );
    self::assertStringContainsString('/var/www/agency/current', $prod);
    self::assertStringContainsString('$SERVER_USER@$SERVER_HOST', $prod);
    self::assertStringContainsString(
      'scripts/production-ssh-trust/manage-known-host.sh',
      $prod,
    );

    foreach ([$preprod, $prod] as $runner) {
      self::assertStringContainsString("$ISSUE_NUMBER\" == '995'", $runner);
      self::assertStringContainsString("$DIAGNOSTIC_PROFILE\" == 'canvas_paths'", $runner);
      self::assertStringContainsString('canvas-runtime-diff-paths-995.php', $runner);
      self::assertStringContainsString('AGENCY_CANVAS_995_EXECUTE=1', $runner);
      self::assertStringContainsString('runtime_canvas_paths', $runner);
      self::assertStringContainsString('config_values_exposed == false', $runner);
      self::assertStringNotContainsString('scp ', $runner);
      self::assertStringNotContainsString('bash -s', $runner);
      self::assertStringNotContainsString('StrictHostKeyChecking=no', $runner);
      foreach ([
        'vendor/bin/drush cim',
        'vendor/bin/drush cex',
        'vendor/bin/drush config:set',
        'vendor/bin/drush cr',
        'vendor/bin/drush updb',
        'vendor/bin/drush pm:enable',
        'state:set',
        'sql:query',
      ] as $forbidden) {
        self::assertStringNotContainsString($forbidden, $runner, $forbidden);
      }
    }
  }

  /**
   * Public evidence is path-only and the PR gate is GitHub-hosted only.
   */
  public function testPublicEvidenceAndHostedValidationBoundary(): void {
    $probe = $this->source(self::PROBE);
    foreach ([
      "'environment' => $environment",
      "'config_name' => $name",
      "'differing_paths' =>",
      "'classification' =>",
      "'config_values_exposed' => FALSE",
    ] as $expected) {
      self::assertStringContainsString($expected, $probe);
    }
    foreach ([
      "'active_value'",
      "'sync_value'",
      "'before'",
      "'after'",
      "'raw_yaml'",
      "'raw_active_config'",
      "'raw_sync_config'",
      "'secret'",
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $probe);
    }

    $targeted = $this->parsed(self::TARGETED);
    $job = $targeted['jobs']['validate'] ?? NULL;
    self::assertIsArray($job);
    self::assertSame('ubuntu-24.04', $job['runs-on'] ?? NULL);
    self::assertArrayNotHasKey('secrets', $job);
    $source = $this->source(self::TARGETED);
    self::assertStringNotContainsString('runs-on: self-hosted', $source);
    self::assertStringNotContainsString('ddev ', strtolower($source));
  }

  /**
   * Parses a repository workflow.
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

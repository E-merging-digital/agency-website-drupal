<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded Outside AI Composer security diagnostic route.
 *
 * @group agency_project_tests
 * @group outside_ai
 */
final class OutsideAiComposerSecurityDiagnosticWorkflowTest extends TestCase {

  /**
   * The workflow must expose only the exact authorized control surface.
   */
  public function testWorkflowIsExactHeadAndBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/outside-ai-composer-security-diagnostic.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertMatchesRegularExpression(
      '/^  workflow_dispatch:\n    inputs:\n/m',
      $workflow,
    );

    self::assertSame(
      1,
      preg_match(
        '/^    inputs:\n(?<inputs>(?:      .*\n|        .*\n)*)^\npermissions:/m',
        $workflow,
        $matches,
      ),
    );
    self::assertArrayHasKey('inputs', $matches);
    preg_match_all(
      '/^      ([A-Za-z0-9_-]+):$/m',
      $matches['inputs'],
      $inputMatches,
    );
    self::assertSame(['request_id', 'head_sha'], $inputMatches[1]);

    self::assertStringContainsString(
      'test "$REQUEST_ID" = \'outside-ai-390-composer-audit-20260907-01\'',
      $workflow,
    );
    self::assertStringContainsString(
      'repos/$GITHUB_REPOSITORY/git/ref/heads/main',
      $workflow,
    );
    self::assertStringContainsString(
      'test "$REQUESTED_HEAD_SHA" = "$live_main"',
      $workflow,
    );
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('permissions:', $workflow);
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString('ubuntu-24.04', $workflow);
    self::assertStringContainsString(
      'scripts/outside-ai/run-composer-security-diagnostic.sh',
      $workflow,
    );
    self::assertStringContainsString(
      'agency-outside-ai-composer-security-${{ github.run_id }}-${{ github.run_attempt }}',
      $workflow,
    );
    self::assertStringContainsString('git status --porcelain', $workflow);
    self::assertStringContainsString('steps.cleanup.outcome', $workflow);

    foreach ([
      'command:',
      'script:',
      'package:',
      'constraint:',
      'environment:',
      'host:',
      'url:',
      'tool:',
      'module:',
      'drush',
      'site:install',
      'mcp:server',
      'outside-ai-readonly-pilot.yml',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The fixed script must remain Composer-only and preserve security evidence.
   */
  public function testDiagnosticScriptIsComposerOnlyAndSecurityFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/outside-ai/run-composer-security-diagnostic.sh';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    foreach ([
      "'drupal/tool:1.0.0-beta7'",
      "'drupal/tool_belt:1.0.0-alpha5'",
      "'drupal/mcp_server:2.0.0-beta2'",
      "'drupal/mcp_server_tool_bridge-mcp_server_tool_bridge:1.0.0-beta1'",
      'composer audit --locked --format=json',
      'baseline-audit.json',
      'baseline-audit-exit-code.txt',
      'pilot-audit.json',
      'pilot-audit-exit-code.txt',
      'composer require',
      '--with-all-dependencies',
      'composer why "$affected_package" --tree',
      'advisory-summary.json',
      'dependency-path.txt',
      'package-comparison.json',
      'BASELINE_PRESENT_OR_PILOT_INTRODUCED',
      'composer-files-restored.txt',
      "security_audit='FAIL'",
      'exit "$security_exit"',
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }

    foreach ([
      '--no-audit',
      'audit.ignore',
      'drush',
      'site:install',
      'pm:enable',
      'mcp:server',
      'tool_api__',
      'PREPROD',
      'PROD_ACCESS',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }

    $output = [];
    $exitCode = 0;
    exec(
      'bash -n ' . escapeshellarg($path) . ' 2>&1',
      $output,
      $exitCode,
    );
    self::assertSame(0, $exitCode, implode("\n", $output));
  }

}

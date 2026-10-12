<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the exact-head self-hosted browser validation contract.
 *
 * @group agency_project_tests
 * @group governed_browser
 */
final class SelfHostedBrowserValidationWorkflowTest extends TestCase {

  /**
   * Database, config, governed content and browser gates stay ordered.
   */
  public function testDrupalRebuildRunsMaintenanceGatesBeforeBrowser(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/self-hosted-browser-validation.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);

    $installPosition = strpos(
      $workflow,
      'ddev composer install --no-interaction --no-progress --prefer-dist',
    );
    $siteInstallPosition = strpos(
      $workflow,
      'ddev drush site:install --existing-config -y',
    );
    $updbPosition = strpos($workflow, 'ddev drush updb -y');
    $cimPosition = strpos($workflow, 'ddev drush cim -y');
    $cachePosition = strpos($workflow, 'ddev drush cr');
    $governedPosition = strpos(
      $workflow,
      'ddev drush emerging:governed-content --all',
    );
    $browserCommand =
      'npm run browser:validate -- tests/browser/public-blog.spec.mjs';
    $browserPosition = strpos($workflow, $browserCommand);

    self::assertIsInt($installPosition);
    self::assertIsInt($siteInstallPosition);
    self::assertIsInt($updbPosition);
    self::assertIsInt($cimPosition);
    self::assertIsInt($cachePosition);
    self::assertIsInt($governedPosition);
    self::assertIsInt($browserPosition);

    self::assertGreaterThan($installPosition, $siteInstallPosition);
    self::assertGreaterThan($siteInstallPosition, $updbPosition);
    self::assertGreaterThan($updbPosition, $cimPosition);
    self::assertGreaterThan($cimPosition, $cachePosition);
    self::assertGreaterThan($cachePosition, $governedPosition);
    self::assertGreaterThan($governedPosition, $browserPosition);

    self::assertStringContainsString('ddev drush config:status', $workflow);
    self::assertStringContainsString(
      'bash scripts/runner/prove-governed-content-cli-facade.sh',
      $workflow,
    );
    self::assertStringContainsString(
      'test -f tests/browser/contracts/public-blog.json',
      $workflow,
    );
    self::assertStringContainsString(
      'test -f tests/browser/public-blog.spec.mjs',
      $workflow,
    );
    // The historical Blog route remains the default, while #1421 alone may
    // select the MCP contract after the trusted exact-head guards.
    $mcpHostedGate = strpos($workflow, 'if [[ "$PR_NUMBER" == "1421" ]]; then');
    $runnerCheckout = strpos(
      $workflow,
      '- name: Checkout exact trusted target',
    );
    $mcpRunnerGuard = strpos($workflow, 'MCP source blob mismatch: $path');
    $npmInstall = strpos($workflow, 'npm ci');
    $browserStep = strpos(
      $workflow,
      '- name: Run unattended real-browser validation',
    );
    self::assertIsInt($mcpHostedGate);
    self::assertIsInt($runnerCheckout);
    self::assertIsInt($mcpRunnerGuard);
    self::assertIsInt($npmInstall);
    self::assertIsInt($browserStep);
    self::assertLessThan($runnerCheckout, $mcpHostedGate);
    self::assertLessThan($npmInstall, $mcpRunnerGuard);

    self::assertStringContainsString(
      'test "$changed_files" = "$expected_files"',
      $workflow,
    );
    self::assertSame(
      2,
      substr_count($workflow, 'test "$changed_files" = "$expected_files"'),
    );
    self::assertStringContainsString(
      'git merge-base --is-ancestor "$trusted_main" HEAD',
      $workflow,
    );
    self::assertStringContainsString(
      'test "$(jq -r \'.head.ref\' <<<"$pr_json")" = ' .
        '"feature/1420-mcp-b-hero-uuid"',
      $workflow,
    );
    self::assertStringContainsString(
      "MCP_BROWSER_PROOF: \$" . "{{ inputs.pr_number == '1421' }}",
      $workflow,
    );
    self::assertStringContainsString(
      "if: \$" . "{{ inputs.pr_number == '1421' }}",
      $workflow,
    );
    self::assertStringContainsString(
      'test "$(git hash-object ' .
        'docs/seo/serveurs-mcp-integration-agents-ia-candidate-1415.md)"',
      $workflow,
    );
    foreach ([
      'web/modules/custom/agency_project_tests/tests/src/Unit/'
        . 'McpServiceHero1420Test.php' =>
        '31eebb0415c7373273c95a2354be54d9b302f9d7',
      'web/themes/custom/emerging_digital/css/components.css' =>
        'fdd92edca3f82bc877944514890d159482a35e33',
      'web/themes/custom/emerging_digital/emerging_digital.theme' =>
        'edeac8bc83ac45ceca859a29b8f2dea20045dadb',
      'web/themes/custom/emerging_digital/templates/content/'
        . 'node--service.html.twig' =>
        '0ce266f92d3fc8c5300bbc10edffec806da598dd',
    ] as $path => $blob) {
      self::assertSame(
        2,
        substr_count($workflow, $path . '|' . $blob),
      );
    }

    $browserScript = substr($workflow, $browserStep);
    self::assertStringContainsString(
      'if [[ "$MCP_BROWSER_PROOF" == "true" ]]; then',
      $browserScript,
    );
    self::assertStringContainsString(
      'export BROWSER_VALIDATION_CONTRACT='
        . 'tests/browser/contracts/mcp-service-1420.json',
      $browserScript,
    );
    self::assertStringContainsString(
      'npm run browser:validate -- tests/browser/mcp-service-1420.spec.mjs',
      $browserScript,
    );
    self::assertStringContainsString(
      'else',
      $browserScript,
    );
    self::assertStringContainsString(
      'export BROWSER_VALIDATION_CONTRACT='
        . 'tests/browser/contracts/public-blog.json',
      $browserScript,
    );
    self::assertStringContainsString(
      'npm run browser:validate -- tests/browser/public-blog.spec.mjs',
      $browserScript,
    );
    self::assertSame(
      2,
      substr_count($browserScript, 'npm run browser:validate'),
    );
    self::assertStringNotContainsString(
      'production-editorial-article.spec.mjs',
      $workflow,
    );
    self::assertStringContainsString(
      "context='agency/browser-validation'",
      $workflow,
    );
  }

}

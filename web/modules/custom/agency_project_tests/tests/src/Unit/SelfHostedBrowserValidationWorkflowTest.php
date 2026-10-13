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
    $canonicalGate = strpos(
      $workflow,
      'if [[ ! "$PR_NUMBER" =~ ^(0|[1-9][0-9]*)$ ]]; then',
    );
    $firstGithubApi = strpos($workflow, 'gh api "repos/$GITHUB_REPOSITORY');
    $githubPrLookup = strpos(
      $workflow,
      'pr_json="$(gh api "repos/$GITHUB_REPOSITORY/pulls/$PR_NUMBER")"',
    );
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
    self::assertIsInt($canonicalGate);
    self::assertIsInt($firstGithubApi);
    self::assertIsInt($githubPrLookup);
    self::assertLessThan($firstGithubApi, $canonicalGate);
    self::assertLessThan($githubPrLookup, $firstGithubApi);
    self::assertLessThan($mcpHostedGate, $githubPrLookup);
    self::assertStringContainsString(
      'pr_number must be canonical.',
      $workflow,
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
      "MCP_BROWSER_PROOF: \${{ inputs.pr_number == '1421' }}",
      $workflow,
    );
    self::assertStringContainsString(
      "if: \${{ inputs.pr_number == '1421' }}",
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

  /**
   * Tests actual workflow canonical input before source selection.
   */
  public function testPrNumberCanonicalizationFromTrustedWorkflow(): void {
    $path = dirname(DRUPAL_ROOT)
      . '/.github/workflows/self-hosted-browser-validation.yml';
    self::assertFileExists($path);
    $workflow = (string) file_get_contents($path);
    $matches = [];
    self::assertSame(1, preg_match_all(
      '#^\s*if \[\[ ! "\$PR_NUMBER" =~ (\S+) \]\]; then$#m',
      $workflow,
      $matches,
    ));
    // The tested expression comes from the versioned workflow itself.
    $actualPattern = $matches[1][0];
    self::assertSame('^(0|[1-9][0-9]*)$', $actualPattern);

    $cases = [
      ['0', TRUE],
      ['1421', TRUE],
      ['1422', TRUE],
      ['01421', FALSE],
      ['001421', FALSE],
      ['00', FALSE],
      ['000', FALSE],
      ['+1421', FALSE],
      [' 1421', FALSE],
      ['1421 ', FALSE],
    ];
    foreach ($cases as [$input, $shouldAccept]) {
      $message = sprintf(
        'Unexpected canonical PR number decision for %s',
        var_export($input, TRUE),
      );
      self::assertSame(
        $shouldAccept,
        preg_match('~' . $actualPattern . '~D', $input) === 1,
        $message,
      );
    }

    self::assertStringContainsString(
      'if [[ "$PR_NUMBER" == "0" ]]; then',
      $workflow,
    );
    self::assertStringContainsString(
      'if [[ "$PR_NUMBER" == "1421" ]]; then',
      $workflow,
    );
    self::assertStringContainsString(
      "MCP_BROWSER_PROOF: \${{ inputs.pr_number == '1421' }}",
      $workflow,
    );
  }

  /**
   * Isolated DDEV router ports remain safe for the rootless Agency runner.
   */
  public function testIsolatedDdevRouterPortsAreRootlessSafe(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflowPath = $root
      . '/.github/workflows/self-hosted-browser-validation.yml';
    $workflow = (string) file_get_contents($workflowPath);
    self::assertIsArray(Yaml::parseFile($workflowPath));

    $startMarker = 'cat > .ddev/config.gate-browser-ci.yaml <<EOF';
    $start = strpos($workflow, $startMarker);
    self::assertIsInt($start);
    $bodyStart = $start + strlen($startMarker);
    $end = strpos($workflow, "\n          EOF", $bodyStart);
    self::assertIsInt($end);
    $body = substr($workflow, $bodyStart, $end - $bodyStart);
    $lines = array_values(array_map('trim', array_filter(
      explode("\n", $body),
      'strlen',
    )));
    self::assertSame([
      'name: agency-browser-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}',
      'router_http_port: "18180"',
      'router_https_port: "18543"',
    ], $lines);

    $ports = [];
    foreach (array_slice($lines, 1) as $line) {
      $matches = [];
      self::assertSame(1, preg_match(
        '/^(router_http_port|router_https_port): "([0-9]+)"$/',
        $line,
        $matches,
      ));
      $port = (int) $matches[2];
      self::assertGreaterThanOrEqual(1024, $port);
      self::assertLessThan(32768, $port);
      // Dedicated ports must not reuse known Agency router or direct ports.
      self::assertNotContains($port, [
        18080, 18443, 19025, 19080, 19180, 19306, 19443, 19444,
        21025, 21080, 21180, 21306, 21443, 21444,
      ]);
      self::assertArrayNotHasKey($matches[1], $ports);
      $ports[$matches[1]] = $port;
    }
    self::assertSame([
      'router_http_port' => 18180,
      'router_https_port' => 18543,
    ], $ports);
    self::assertNotSame(
      $ports['router_http_port'],
      $ports['router_https_port'],
    );

    $isolate = strpos($workflow, '- name: Isolate DDEV project for this run');
    $configCheck = strpos($workflow, 'ddev utility configyaml', $start);
    $ddevStart = strpos($workflow, 'ddev start -y', $start);
    $cleanup = strpos($workflow, 'rm -f .ddev/config.gate-browser-ci.yaml');
    self::assertIsInt($isolate);
    self::assertIsInt($configCheck);
    self::assertIsInt($ddevStart);
    self::assertIsInt($cleanup);
    self::assertTrue($isolate < $start);
    self::assertTrue($end < $configCheck);
    self::assertTrue($configCheck < $ddevStart);
    self::assertTrue($ddevStart < $cleanup);

    foreach ([
      'router_bind_all_interfaces',
      'sysctl',
      'RootlessKit',
      'CAP_NET_BIND_SERVICE',
      'sudo',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
    self::assertStringContainsString(
      'ddev delete --omit-snapshot --yes',
      $workflow,
    );
    self::assertStringContainsString('git diff --check', $workflow);
    self::assertStringContainsString('git status --porcelain', $workflow);

    $browserScript = (string) file_get_contents(
      $root . '/scripts/run-browser-validation.mjs',
    );
    self::assertStringContainsString(
      "execFileSync('ddev', ['describe', '-j']",
      $browserScript,
    );
    self::assertStringContainsString(
      'const url = findUrl(parsed);',
      $browserScript,
    );
  }

}

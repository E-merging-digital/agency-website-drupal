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

    self::assertStringContainsString(
      'bash scripts/runner/verify-browser-config-canonical-canvas-1420.sh',
      $workflow,
    );
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
      static fn (string $line): bool => $line !== '',
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

  /**
   * All three phases invoke one pinned trusted config gate.
   */
  public function testCanvasCanonicalGateAtThreeCheckpoints(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/self-hosted-browser-validation.yml';
    $workflow = (string) file_get_contents($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    $steps = $parsed['jobs']['browser-validation']['steps'];
    self::assertIsArray($steps);

    $byName = [];
    foreach ($steps as $step) {
      $byName[$step['name']] = $step;
    }

    $rebuild = $byName['Rebuild Drupal from repository-owned state'];
    $fixture = $byName['Prepare exact MCP fixture in isolated Drupal'];
    $expectedInput = "\${{ inputs.pr_number }}";
    self::assertSame(
      $expectedInput,
      $rebuild['env']['CANVAS_BROWSER_PR_NUMBER'],
    );
    self::assertSame(
      $expectedInput,
      $fixture['env']['CANVAS_BROWSER_PR_NUMBER'],
    );

    $call = 'bash scripts/runner/'
      . 'verify-browser-config-canonical-canvas-1420.sh';
    self::assertSame(2, substr_count($rebuild['run'], $call));
    self::assertSame(1, substr_count($fixture['run'], $call));
    self::assertSame(3, substr_count($workflow, $call));

    $first = strpos($rebuild['run'], $call);
    $second = strpos($rebuild['run'], $call, $first + strlen($call));
    self::assertIsInt($first);
    self::assertIsInt($second);
    self::assertLessThan($first, strpos($rebuild['run'], 'ddev drush cr'));
    self::assertGreaterThan(
      strpos($rebuild['run'], 'ddev drush cim -y'),
      $first,
    );
    self::assertGreaterThan(
      strpos($rebuild['run'], 'ddev drush emerging:governed-content --all'),
      $second,
    );
    self::assertGreaterThan(
      strpos($fixture['run'], 'ddev drush cr'),
      strpos($fixture['run'], $call),
    );
    self::assertStringContainsString(
      'test "$(git hash-object '
        . 'scripts/runner/verify-browser-config-canonical-canvas-1420.sh)"',
      $workflow,
    );
    self::assertStringNotContainsString('verify_config_clean()', $workflow);
    self::assertStringNotContainsString(
      'grep -Fq ' . "'No differences'",
      $workflow,
    );
  }

  /**
   * Clean and known Canvas paths retain distinct fail-closed admissions.
   */
  public function testCanvasCanonicalGateStrictAndKnownDriftPolicy(): void {
    $root = dirname(DRUPAL_ROOT);
    $helperPath = $root
      . '/scripts/runner/verify-browser-config-canonical-canvas-1420.sh';
    self::assertFileExists($helperPath);
    $helper = (string) file_get_contents($helperPath);

    // Zero actual config status rows is the only unconditional PASS.
    self::assertStringContainsString(
      'ddev drush config:status --format=json',
      $helper,
    );
    self::assertStringContainsString(
      'php scripts/runner/filter-config-status-metadata.php PREPROD',
      $helper,
    );
    self::assertStringContainsString('[[ "$count" == "0" ]]', $helper);
    self::assertStringContainsString(
      'if [[ "$CANVAS_BROWSER_PR_NUMBER" != "0" && ' .
        '"$CANVAS_BROWSER_PR_NUMBER" != "1421" ]]; then',
      $helper,
    );
    self::assertStringContainsString('fail_closed', $helper);

    // Existing gate, helper and comparator are used without a second engine.
    foreach ([
      'canvas-runtime-diff-paths-995.php|'
      . 'ef50d3cae1b497b5931789e6a109a9f67f9c6a5f',
      'canvas-ai-provider-config-drift-gate-1373.php|'
      . '92f1bc7def35e2c632d1a0e70d95579fe7365dcf',
      'run-canvas-ai-provider-config-drift-gate-1375.sh|'
      . 'c514da23bc6f87e0ce27c86bbb2c8761d0a9e67c',
      'filter-config-status-metadata.php|'
      . '2c4efe9742fcca89d8169f2edcdc0f2dad99e579',
      'AGENCY_CANVAS_1373_EXECUTE=1',
      'AGENCY_CANVAS_1375_CODE_B64=',
      'AGENCY_CANVAS_1373_STATUS_B64=',
      'ddev exec env',
    ] as $required) {
      self::assertStringContainsString($required, $helper);
    }

    // The #1373 probe rejects any extra/missing/non-Different name, and #995
    // rejects unknown business paths, invalid shapes and dynamic version keys.
    // The final verdict must require every real comparator result to be known.
    self::assertStringContainsString(
      '(.items | type == "array" and length == 19)',
      $helper,
    );
    self::assertStringContainsString(
      '.classification == "KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN"',
      $helper,
    );
    self::assertStringContainsString(
      '.summary == {"total":19,"known":19,"unexpected":0}',
      $helper,
    );
    self::assertStringContainsString(
      'git diff --binary -- config/sync',
      $helper,
    );
    self::assertStringNotContainsString('raw_yaml', $helper);
    self::assertStringNotContainsString(
      'CANVAS_CONFIG_DRIFT_GATE=PASS',
      $helper,
    );
  }

  /**
   * The clean path stays admitted, but all nonzero drift goes through one gate.
   *
   * This is a source contract; real active/sync admission is proven at runtime.
   */
  public function testCanvasTrustedMainUsesExistingPinnedStrictGate(): void {
    $root = dirname(DRUPAL_ROOT);
    $helper = (string) file_get_contents($root .
      '/scripts/runner/verify-browser-config-canonical-canvas-1420.sh');
    $workflow = (string) file_get_contents($root .
      '/.github/workflows/self-hosted-browser-validation.yml');

    $clean = strpos($helper, '[[ "$count" == "0" ]]');
    $restricted = strpos(
      $helper,
      'if [[ "$CANVAS_BROWSER_PR_NUMBER" != "0" && '
        . '"$CANVAS_BROWSER_PR_NUMBER" != "1421" ]]; then',
    );
    self::assertIsInt($clean);
    self::assertIsInt($restricted);
    self::assertLessThan($restricted, $clean);
    self::assertStringContainsString(
      "CANVAS_BROWSER_CONFIG_GATE=PASS_CLEAN",
      $helper,
    );

    // Only canonical main 0 or exact MCP 1421 reaches the strict nonzero gate.
    // Every other canonical PR number still fails closed on any drift.
    self::assertSame(
      1,
      substr_count(
        $helper,
        'if [[ "$CANVAS_BROWSER_PR_NUMBER" != "0" && '
          . '"$CANVAS_BROWSER_PR_NUMBER" != "1421" ]]; then',
      ),
    );
    self::assertStringContainsString(
      '[[ "$CANVAS_BROWSER_PR_NUMBER" =~ ^(0|[1-9][0-9]*)$ ]]',
      $helper,
    );
    self::assertStringContainsString(
      'agency_canvas_1373',
      $helper,
    );
    self::assertStringContainsString(
      '(.items | type == "array" and length == 19)',
      $helper,
    );
    self::assertStringContainsString(
      '.classification == "KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN"',
      $helper,
    );
    self::assertStringContainsString(
      '.summary == {"total":19,"known":19,"unexpected":0}',
      $helper,
    );
    self::assertStringContainsString('fail_closed', $helper);

    // The workflow must pin the EXACT Git blob of this helper, not a label or
    // mutable branch. Every invocation still uses the same pinned script.
    $actualBlob = sha1('blob ' . strlen($helper) . "\0" . $helper);
    self::assertStringContainsString(
      'test "$(git hash-object scripts/runner/'
        . 'verify-browser-config-canonical-canvas-1420.sh)" = "'
        . $actualBlob . '"',
      $workflow,
    );
    self::assertSame(
      3,
      substr_count(
        $workflow,
        'bash scripts/runner/verify-browser-config-canonical-canvas-1420.sh',
      ),
    );
  }

  /**
   * Exercises existing #1373/#995 checks with synthetic data only.
   *
   * Synthetic acceptance never proves real runtime drift.
   */
  public function testCanvasExactCohortKnownAndUnexpectedPathsFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    require_once $root . '/scripts/runner/canvas-ai-provider-config-drift-gate-1373.php';

    // @phpstan-ignore-next-line
    $names = \agency_canvas_1373_allowed_names();
    self::assertCount(19, $names);
    $metadata = ['items' => []];
    $dataset = [];
    foreach ($names as $name) {
      $metadata['items'][] = ['config_name' => $name, 'state' => 'Different'];
      $base = [
        'active_version' => 'aaaaaaaaaaaaaaaa',
        'versioned_properties' => ['active' => []],
        'label' => 'synthetic sync',
      ];
      $active = $base;
      $active['label'] = 'synthetic active';
      $dataset[$name] = ['active' => $active, 'sync' => $base];
    }

    // Trusted main (0) and MCP #1421 both use this exact same 19/19 gate.
    // @phpstan-ignore-next-line
    $known = \agency_canvas_1373_analyze_dataset($metadata, $dataset);
    self::assertSame(['total' => 19, 'known' => 19, 'unexpected' => 0],
      $known['summary']);
    self::assertCount(19, $known['items']);
    foreach ($known['items'] as $item) {
      self::assertSame('KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN',
        $item['classification']);
      self::assertSame(['label'], $item['differing_paths']);
    }

    $unexpected = $dataset;
    $unexpected[$names[0]]['active']['business_ref'] = 'synthetic';
    // @phpstan-ignore-next-line
    $rejected = \agency_canvas_1373_analyze_dataset($metadata, $unexpected);
    self::assertSame(['total' => 19, 'known' => 18, 'unexpected' => 1],
      $rejected['summary']);
    self::assertSame('UNEXPECTED_CANVAS_BUSINESS_PATH_REVIEW_REQUIRED',
      $rejected['items'][0]['classification']);

    // Unknown name/state, missing item and unsupported active/sync shape
    // must never produce an admissible 19/19 summary.
    foreach (['name', 'state', 'missing'] as $invalid) {
      $bad = $metadata;
      if ($invalid === 'name') {
        $bad['items'][0]['config_name'] = 'canvas.component.block.other';
      }
      elseif ($invalid === 'state') {
        $bad['items'][0]['state'] = 'Only in DB';
      }
      else {
        array_pop($bad['items']);
      }
      try {
        // @phpstan-ignore-next-line
        \agency_canvas_1373_analyze_dataset($bad, $dataset);
        self::fail('Unsupported Canvas status was accepted: ' . $invalid);
      }
      catch (\RuntimeException $exception) {
        self::assertNotSame('', $exception->getMessage());
      }
    }
    $badShape = $dataset;
    $badShape[$names[0]]['active']['versioned_properties'] = 'wrong';
    try {
      // @phpstan-ignore-next-line
      \agency_canvas_1373_analyze_dataset($metadata, $badShape);
      self::fail('Unsupported Canvas storage shape was accepted.');
    }
    catch (\RuntimeException $exception) {
      self::assertNotSame('', $exception->getMessage());
    }
  }

}

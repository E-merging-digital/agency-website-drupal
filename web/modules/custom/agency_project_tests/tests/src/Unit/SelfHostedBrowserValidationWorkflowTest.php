<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
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
    // Blog remains default: only #1421 selects the MCP contract.
    // #1458 uses the default lane with scoped homepage assertions.
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
      3,
      substr_count($workflow, 'test "$changed_files" = "$expected_files"'),
    );
    // #1458 may reach this classifier only with its reviewed branch and
    // exact five-file candidate. The original fork/exact SHA checks remain.
    self::assertStringContainsString(
      'if [[ "$PR_NUMBER" == "1458" ]]; then',
      $workflow,
    );
    self::assertStringContainsString(
      '"feature/1418-home-bplus-kinetic"',
      $workflow,
    );
    foreach ([
      'tests/browser/public-blog.spec.mjs',
      'web/themes/custom/emerging_digital/css/home-kinetic.css',
      'web/themes/custom/emerging_digital/emerging_digital.libraries.yml',
      'web/themes/custom/emerging_digital/js/home-kinetic.js',
      'web/themes/custom/emerging_digital/templates/layout/page--front.html.twig',
    ] as $reviewedFile) {
      self::assertStringContainsString("'" . $reviewedFile . "'", $workflow);
    }
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
   * Isolated DDEV router and direct host ports stay safe for rootless Agency.
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
      'host_webserver_port: "18280"',
      'host_https_port: "18644"',
      'host_db_port: "18281"',
      'host_mailpit_port: "18282"',
    ], $lines);

    // Stable loopback ports avoid the shared host's occupied ephemeral
    // 32768-60999 range; no global Docker or DDEV change is required.
    $ports = [];
    foreach (array_slice($lines, 1) as $line) {
      $matches = [];
      self::assertSame(1, preg_match(
        '/^(router_http_port|router_https_port|host_webserver_port|host_https_port|host_db_port|host_mailpit_port): "([0-9]+)"$/',
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
      'host_webserver_port' => 18280,
      'host_https_port' => 18644,
      'host_db_port' => 18281,
      'host_mailpit_port' => 18282,
    ], $ports);
    self::assertCount(count($ports), array_unique(array_values($ports)));

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
   * Simulates the existing rebuild's DDEV start without invoking DDEV.
   *
   * Only one RootlessKit bind race may retry, after owned cleanup succeeds.
   */
  public function testRootlessPortCollisionRetryIsOwnedAndBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $yaml = Yaml::parseFile(
      $root . '/.github/workflows/self-hosted-browser-validation.yml',
    );
    self::assertIsArray($yaml);
    $rebuild = '';
    foreach ($yaml['jobs']['browser-validation']['steps'] as $step) {
      if ($step['name'] === 'Rebuild Drupal from repository-owned state') {
        $rebuild = $step['run'];
        break;
      }
    }
    self::assertNotSame('', $rebuild);
    $parts = explode('ddev composer install --no-interaction', $rebuild, 2);
    self::assertCount(2, $parts);
    $source = $parts[0];
    self::assertSame(2, substr_count($source, 'ddev start -y'));
    self::assertSame(1, substr_count($source, 'ddev delete --omit-snapshot --yes'));
    $fake = <<<'BASH'
    calls=0
    ddev() {
      case "$1" in
        start)
          printf 'start\n' >> "$FAKE_TRACE"
          calls=$((calls + 1))
          if [[ "$calls" == "1" && "$FAKE_FIRST" != "ok" ]]; then
            printf '%s\n' "$FAKE_FIRST" >&2
            return 1
          fi
          if [[ "$calls" == "2" && "$FAKE_SECOND" != "ok" ]]; then
            echo 'synthetic second start failed' >&2
            return 1
          fi
          ;;
        utility)
          [[ "$2" == "configyaml" ]] || return 2
          printf 'name: %s\n' "$FAKE_PROJECT"
          ;;
        delete)
          printf 'delete\n' >> "$FAKE_TRACE"
          [[ "$2" == "--omit-snapshot" && "$3" == "--yes" && "$FAKE_DELETE" == "ok" ]]
          ;;
        *)
          return 2
          ;;
      esac
    }
    BASH;
    $collision = 'RootlessKit PortManager.AddPort(): '
      . 'listen tcp4 127.0.0.1:32777: bind: address already in use';
    $project = 'agency-browser-987654321-1';
    $cases = [
      ['normal', 'ok', 'ok', 'ok', $project, 0, "start\n"],
      ['collision recovered', $collision, 'ok', 'ok', $project, 0,
        "start\ndelete\nstart\n",
      ],
      ['unrelated failure', 'connection refused', 'ok', 'ok', $project, 1,
        "start\n",
      ],
      ['rootless without bind', 'RootlessKit PortManager.AddPort(): denied',
        'ok', 'ok', $project, 1, "start\n",
      ],
      ['bind without rootless', 'bind: address already in use',
        'ok', 'ok', $project, 1, "start\n",
      ],
      ['second failure', $collision, 'fail', 'ok', $project, 1,
        "start\ndelete\nstart\n",
      ],
      ['wrong identity', $collision, 'ok', 'ok', 'unrelated-project', 1,
        "start\n",
      ],
      ['cleanup failed', $collision, 'ok', 'fail', $project, 1,
        "start\ndelete\n",
      ],
    ];
    foreach ($cases as [$name, $first, $second, $delete, $identity, $code, $events]) {
      $trace = tempnam(sys_get_temp_dir(), 'agency-rootless-');
      self::assertNotFalse($trace);
      $process = new Process(['bash', '-c', $fake . "\n" . $source], $root, [
        'RUNNER_TEMP' => sys_get_temp_dir(),
        'GITHUB_RUN_ID' => '987654321',
        'GITHUB_RUN_ATTEMPT' => '1',
        'FAKE_TRACE' => $trace,
        'FAKE_FIRST' => $first,
        'FAKE_SECOND' => $second,
        'FAKE_DELETE' => $delete,
        'FAKE_PROJECT' => $identity,
      ]);
      $process->setTimeout(10);
      $process->run();
      $actualEvents = (string) file_get_contents($trace);
      unlink($trace);
      self::assertSame($code, $process->getExitCode(),
        $name . ': ' . $process->getErrorOutput());
      self::assertSame($events, $actualEvents, $name);
    }
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
        '"$CANVAS_BROWSER_PR_NUMBER" != "1421" && ' .
        '"$CANVAS_BROWSER_PR_NUMBER" != "1458" ]]; then',
      $helper,
    );
    self::assertStringContainsString('fail_closed', $helper);

    // Existing gate, helper and comparator are used without a second engine.
    foreach ([
      'canvas-runtime-diff-paths-995.php|'
      . 'bdc6016b208fdad332880e4b943aa2aa0af2e94b',
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
        . '"$CANVAS_BROWSER_PR_NUMBER" != "1421" && '
        . '"$CANVAS_BROWSER_PR_NUMBER" != "1458" ]]; then',
    );
    self::assertIsInt($clean);
    self::assertIsInt($restricted);
    self::assertLessThan($restricted, $clean);
    self::assertStringContainsString(
      "CANVAS_BROWSER_CONFIG_GATE=PASS_CLEAN",
      $helper,
    );

    // Only canonical main 0, exact MCP 1421 and reviewed Kinetic 1458
    // reach the existing strict nonzero gate; other PRs fail closed.
    self::assertSame(
      1,
      substr_count(
        $helper,
        'if [[ "$CANVAS_BROWSER_PR_NUMBER" != "0" && '
          . '"$CANVAS_BROWSER_PR_NUMBER" != "1421" && '
          . '"$CANVAS_BROWSER_PR_NUMBER" != "1458" ]]; then',
      ),
    );
    self::assertStringContainsString(
      '[[ "$CANVAS_BROWSER_PR_NUMBER" =~ ^(0|[1-9][0-9]*)$ ]]',
      $helper,
    );
    self::assertStringContainsString(
      'canvas-ai-provider-config-drift-gate-1373.php',
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

    // Main 0, MCP #1421 and Kinetic #1458 share the exact 19/19 gate.
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

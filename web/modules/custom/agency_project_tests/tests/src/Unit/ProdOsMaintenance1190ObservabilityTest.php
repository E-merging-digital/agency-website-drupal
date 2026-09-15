<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects #1190 PLAN fail observability and public-home semantics.
 *
 * @group agency_project_tests
 */
final class ProdOsMaintenance1190ObservabilityTest extends TestCase {

  private const PLAN = 'scripts/production-maintenance-1183/remote-plan.sh';
  private const WORKFLOW = '.github/workflows/prod-os-maintenance-1183.yml';
  private const MARKETING_H1 = 'Agence web senior pour sites professionnels, IA et PHP durable';

  /**
   * Root redirect-follow must terminate on the expected FR homepage surface.
   */
  public function testCanonicalPublicHomeRedirectProbe(): void {
    $source = $this->source(self::PLAN);
    self::assertStringContainsString('--location --max-redirs 3', $source);
    self::assertStringContainsString('"$PROD_URL/"', $source);
    self::assertStringContainsString('/images/branding/emerging-digital-mark.svg', $source);
    self::assertStringNotContainsString('Créer, améliorer ou moderniser votre plateforme web', $source);

    self::assertSame(
      ['PASS', '200', '/fr'],
      $this->runPublicHomeProbe('200|https://emergingdigital.be/fr', self::MARKETING_H1),
    );
    self::assertSame(
      ['PASS', '200', '/fr/'],
      $this->runPublicHomeProbe('200|https://emergingdigital.be/fr/', self::MARKETING_H1),
    );
    self::assertSame(
      ['FAIL', '200', 'UNKNOWN'],
      $this->runPublicHomeProbe('200|https://example.invalid/fr', self::MARKETING_H1),
    );
    self::assertSame(
      ['FAIL', '503', '/fr'],
      $this->runPublicHomeProbe('503|https://emergingdigital.be/fr', self::MARKETING_H1),
    );
    self::assertSame(
      ['PASS', '200', '/fr'],
      $this->runPublicHomeProbe('200|https://emergingdigital.be/fr', 'Changed campaign copy'),
    );
  }

  /**
   * A multi-gate safety failure yields bounded, non-approvable evidence.
   */
  public function testSafetyFailureProducesBoundedReceipt(): void {
    $result = $this->executePlan(13696200, [
      'MAX_ALLOWED_PACKET' => '16777216',
      'PUBLIC_HOME' => 'FAIL',
      'PUBLIC_HOME_HTTP_CODE' => '503',
      'PUBLIC_HOME_EFFECTIVE_PATH' => '/fr',
      'RECENT_NGINX_PHP_ERRORS' => 'PRESENT_MATERIAL',
      'NGINX_RECENT_ERROR_COUNT' => '2',
      'PHP_FPM_RECENT_ERROR_COUNT' => '1',
    ]);
    self::assertNotSame(0, $result['status']);
    $receipt = $this->decodeReceipt($result['stdout']);
    self::assertSame('FAIL', $receipt['STATUS']);
    self::assertSame('FAIL', $receipt['SAFETY_GATE']);
    self::assertNull($receipt['PLAN_DIGEST']);
    self::assertSame('YES', $receipt['CANNOT_BE_APPROVED']);
    self::assertSame('NONE', $receipt['REAL_PROD_MUTATION']);
    self::assertSame('16777216', $receipt['MAX_ALLOWED_PACKET']);
    self::assertSame(503, $receipt['PUBLIC_HOME_HTTP_CODE']);
    self::assertSame('/fr', $receipt['PUBLIC_HOME_EFFECTIVE_PATH']);
    self::assertSame(2, $receipt['NGINX_RECENT_ERROR_COUNT']);
    self::assertSame(1, $receipt['PHP_FPM_RECENT_ERROR_COUNT']);
    self::assertSame('PRESENT_MATERIAL', $receipt['RECENT_NGINX_PHP_ERRORS']);
    foreach ([
      'max_allowed_packet_64m',
      'public_home',
      'recent_nginx_php_errors',
    ] as $failedCheck) {
      self::assertContains($failedCheck, $receipt['FAILED_CHECKS']);
      self::assertStringContainsString($failedCheck, $result['stderr']);
    }
  }

  /**
   * Unknown max packet remains strict and observable without data leakage.
   */
  public function testUnknownMaxAllowedPacketFailsClosed(): void {
    $result = $this->executePlan(13696200, ['MAX_ALLOWED_PACKET' => 'UNKNOWN']);
    self::assertNotSame(0, $result['status']);
    $receipt = $this->decodeReceipt($result['stdout']);
    self::assertSame('UNKNOWN', $receipt['MAX_ALLOWED_PACKET']);
    self::assertContains('max_allowed_packet_64m', $receipt['FAILED_CHECKS']);
    self::assertNull($receipt['PLAN_DIGEST']);
  }

  /**
   * Zero recent service errors pass; non-zero counts remain blocking.
   */
  public function testRecentErrorCountsAreBoundedAndFailClosed(): void {
    $pass = $this->evaluatePlan(13696200);
    self::assertSame(0, $pass['NGINX_RECENT_ERROR_COUNT']);
    self::assertSame(0, $pass['PHP_FPM_RECENT_ERROR_COUNT']);
    self::assertSame('NONE_MATERIAL', $pass['RECENT_NGINX_PHP_ERRORS']);

    $result = $this->executePlan(13696200, [
      'RECENT_NGINX_PHP_ERRORS' => 'PRESENT_MATERIAL',
      'NGINX_RECENT_ERROR_COUNT' => '4',
      'PHP_FPM_RECENT_ERROR_COUNT' => '0',
    ]);
    self::assertNotSame(0, $result['status']);
    $receipt = $this->decodeReceipt($result['stdout']);
    self::assertSame(4, $receipt['NGINX_RECENT_ERROR_COUNT']);
    self::assertSame(0, $receipt['PHP_FPM_RECENT_ERROR_COUNT']);
    self::assertContains('recent_nginx_php_errors', $receipt['FAILED_CHECKS']);
  }

  /**
   * Exact APT set preserves the successful PLAN contract.
   */
  public function testAptExactSetPreservesPassSemantics(): void {
    $receipt = $this->evaluatePlan(13696200);
    self::assertSame('PASS', $receipt['APT_UPGRADE_SIMULATION']);
    self::assertSame([], $receipt['APT_SIMULATION_MISSING_UPGRADABLE']);
    self::assertSame([], $receipt['APT_SIMULATION_UNEXPECTED_UPGRADES']);
    self::assertNotContains('apt_policy_classification', $receipt['FAILED_CHECKS']);
  }

  /**
   * Missing upgradable packages become bounded fail evidence.
   */
  public function testAptMissingSetProducesBoundedFailReceipt(): void {
    $result = $this->executePlan(
      13696200,
      [],
      "Listing...\nnginx/noble-updates 1.24.0-2ubuntu7.18 amd64 [upgradable from: 1.24.0-2ubuntu7.17]\nkrb5-locales/noble-updates 1.20.1-6ubuntu2.6 all [upgradable from: 1.20.1-6ubuntu2.5]\n",
    );
    self::assertNotSame(0, $result['status']);
    $receipt = $this->decodeReceipt($result['stdout']);
    self::assertSame('FAIL', $receipt['STATUS']);
    self::assertSame('FAIL', $receipt['SAFETY_GATE']);
    self::assertSame('FAIL', $receipt['APT_UPGRADE_SIMULATION']);
    self::assertSame(['krb5-locales'], $receipt['APT_SIMULATION_MISSING_UPGRADABLE']);
    self::assertSame([], $receipt['APT_SIMULATION_UNEXPECTED_UPGRADES']);
    self::assertContains('apt_policy_classification', $receipt['FAILED_CHECKS']);
    self::assertNull($receipt['PLAN_DIGEST']);
    self::assertSame('YES', $receipt['CANNOT_BE_APPROVED']);
    self::assertSame('NONE', $receipt['REAL_PROD_MUTATION']);
    self::assertSame('67108864', $receipt['MAX_ALLOWED_PACKET']);
    self::assertSame(200, $receipt['PUBLIC_HOME_HTTP_CODE']);
    self::assertSame('/fr', $receipt['PUBLIC_HOME_EFFECTIVE_PATH']);
    self::assertSame(0, $receipt['NGINX_RECENT_ERROR_COUNT']);
    self::assertSame(0, $receipt['PHP_FPM_RECENT_ERROR_COUNT']);
    self::assertSame('NONE_MATERIAL', $receipt['RECENT_NGINX_PHP_ERRORS']);
  }

  /**
   * Unexpected simulated upgrades are exposed as package names only.
   */
  public function testAptUnexpectedSetProducesBoundedFailReceipt(): void {
    $simulation = "Inst nginx [1.24.0-2ubuntu7.17] (1.24.0-2ubuntu7.18 Ubuntu:24.04/noble-updates [amd64])\n"
      . "Inst curl [8.5.0-2ubuntu10.5] (8.5.0-2ubuntu10.6 Ubuntu:24.04/noble-updates [amd64])\n";
    $result = $this->executePlan(13696200, [], NULL, $simulation);
    self::assertNotSame(0, $result['status']);
    $receipt = $this->decodeReceipt($result['stdout']);
    self::assertSame('FAIL', $receipt['APT_UPGRADE_SIMULATION']);
    self::assertSame([], $receipt['APT_SIMULATION_MISSING_UPGRADABLE']);
    self::assertSame(['curl'], $receipt['APT_SIMULATION_UNEXPECTED_UPGRADES']);
    self::assertContains('apt_unexpected_empty', $receipt['FAILED_CHECKS']);
    self::assertNull($receipt['PLAN_DIGEST']);
    self::assertStringNotContainsString('Ubuntu:24.04', json_encode([
      $receipt['APT_SIMULATION_MISSING_UPGRADABLE'],
      $receipt['APT_SIMULATION_UNEXPECTED_UPGRADES'],
    ], JSON_THROW_ON_ERROR));
  }

  /**
   * APT mismatch is additive with independent safety failures.
   */
  public function testAptMismatchCoexistsWithOtherSafetyFailures(): void {
    $result = $this->executePlan(
      13696200,
      ['MAX_ALLOWED_PACKET' => '16777216'],
      "Listing...\nnginx/noble-updates 1.24.0-2ubuntu7.18 amd64 [upgradable from: 1.24.0-2ubuntu7.17]\nlibnetplan1/noble-updates 1.1.2-2~ubuntu24.04.2 amd64 [upgradable from: 1.1.2-2~ubuntu24.04.1]\n",
    );
    $receipt = $this->decodeReceipt($result['stdout']);
    self::assertContains('apt_policy_classification', $receipt['FAILED_CHECKS']);
    self::assertContains('max_allowed_packet_64m', $receipt['FAILED_CHECKS']);
    self::assertSame(['libnetplan1'], $receipt['APT_SIMULATION_MISSING_UPGRADABLE']);
    self::assertSame('16777216', $receipt['MAX_ALLOWED_PACKET']);
    self::assertNull($receipt['PLAN_DIGEST']);
  }

  /**
   * PASS digest keeps #1187 semantics for healthy free-disk drift.
   */
  public function testPassDigestRemainsStableAcrossHealthyDiskDrift(): void {
    $first = $this->evaluatePlan(13696200);
    $second = $this->evaluatePlan(13695000);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['PLAN_DIGEST']);
    self::assertSame($first['PLAN_DIGEST'], $second['PLAN_DIGEST']);
    self::assertSame('PASS', $first['SAFETY_GATE']);
    self::assertSame('67108864', $first['MAX_ALLOWED_PACKET']);
    self::assertSame([], $first['FAILED_CHECKS']);
    self::assertSame('NO', $first['CANNOT_BE_APPROVED']);
  }

  /**
   * Failed PLANs stay failed, upload evidence, and cannot enter APPLY.
   */
  public function testFailedPlanWorkflowRemainsFailureAndIsNotApplyable(): void {
    $workflow = $this->source(self::WORKFLOW);
    foreach ([
      'set +e',
      'plan_rc=$?',
      '.STATUS == "FAIL"',
      '.SAFETY_GATE == "FAIL"',
      '.PLAN_DIGEST == null',
      '.CANNOT_BE_APPROVED == "YES"',
      'exit "$plan_rc"',
      'if: ${{ always() }}',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
    foreach ([
      'run_json="$(gh api "repos/$GITHUB_REPOSITORY/actions/runs/$PLAN_RUN")"',
      "'.conclusion'",
      "= 'success'",
      '.STATUS == "PASS"',
      '.SAFETY_GATE == "PASS"',
      '.PLAN_DIGEST == $digest',
    ] as $applyRequirement) {
      self::assertStringContainsString($applyRequirement, $workflow);
    }
  }

  /**
   * PLAN never publishes raw service log lines, only bounded event counts.
   */
  public function testRecentServiceEvidenceIsCountOnly(): void {
    $plan = $this->source(self::PLAN);
    self::assertStringContainsString('--output=json', $plan);
    self::assertStringContainsString("awk 'NF {count++} END {print count + 0}'", $plan);
    self::assertStringContainsString('NGINX_RECENT_ERROR_COUNT', $plan);
    self::assertStringContainsString('PHP_FPM_RECENT_ERROR_COUNT', $plan);
    self::assertStringNotContainsString('--output=cat', $plan);
    self::assertStringNotContainsString('php_errors=', $plan);
    self::assertStringNotContainsString('nginx_errors=', $plan);
    self::assertStringNotContainsString('NGINX_RECENT_ERROR_LINES', $plan);
    self::assertStringNotContainsString('PHP_FPM_RECENT_ERROR_LINES', $plan);
  }

  /**
   * Evaluates the embedded PLAN receipt builder on synthetic observations.
   */
  private function evaluatePlan(int $diskAvailableKb, array $overrides = []): array {
    $result = $this->executePlan($diskAvailableKb, $overrides);
    self::assertSame(0, $result['status'], $result['stderr']);
    return $this->decodeReceipt($result['stdout']);
  }

  /**
   * Executes only the embedded deterministic Python evaluator.
   */
  private function executePlan(
    int $diskAvailableKb,
    array $overrides = [],
    ?string $upgradableRaw = NULL,
    ?string $upgradeSimulationRaw = NULL,
    ?string $phasedSimulationRaw = NULL,
  ): array {
    $source = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match("/python3 - <<'PY'\\n(.*?)\\nPY\\n/s", $source, $matches),
    );
    $directory = sys_get_temp_dir() . '/agency-1190-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      file_put_contents(
        $directory . '/upgradable.raw',
        $upgradableRaw ?? "Listing...\nnginx/noble-updates 1.24.0-2ubuntu7.18 amd64 [upgradable from: 1.24.0-2ubuntu7.17]\n",
      );
      file_put_contents(
        $directory . '/upgrade-sim.raw',
        $upgradeSimulationRaw ?? "Inst nginx [1.24.0-2ubuntu7.17] (1.24.0-2ubuntu7.18 Ubuntu:24.04/noble-updates [amd64])\n",
      );
      file_put_contents(
        $directory . '/upgrade-sim-phased.raw',
        $phasedSimulationRaw ?? $upgradeSimulationRaw ?? "Inst nginx [1.24.0-2ubuntu7.17] (1.24.0-2ubuntu7.18 Ubuntu:24.04/noble-updates [amd64])\n",
      );
      file_put_contents($directory . '/held.raw', '');
      file_put_contents($directory . '/failed.raw', '');
      $script = $directory . '/plan.py';
      file_put_contents($script, $matches[1] . "\n");
      $stdout = $directory . '/stdout.json';
      $stderr = $directory . '/stderr.txt';
      $environment = array_replace([
        'WORK_ROOT' => $directory,
        'MAIN_SHA' => str_repeat('a', 40),
        'PLAN_ID' => 'plan-1183-observability-fixture-r1',
        'ISSUE' => '1183',
        'TARGET' => 'PROD',
        'MODE' => 'PLAN',
        'TARGET_KERNEL' => '6.8.0-139-generic',
        'OS_PRETTY_NAME' => 'Ubuntu 24.04.5 LTS',
        'VERSION_ID' => '24.04',
        'KERNEL_RUNNING' => '6.8.0-138-generic',
        'KERNEL_INSTALLED_LATEST' => '6.8.0-139-generic',
        'REBOOT_REQUIRED' => 'YES',
        'PHP_BRANCH' => '8.4',
        'MARIADB_BRANCH' => '11.8',
        'NGINX_SERVICE' => 'active',
        'PHP_FPM_SERVICE' => 'active',
        'MARIADB_SERVICE' => 'active',
        'DRUPAL_HEALTH' => 'PASS',
        'PUBLIC_HEALTH' => 'PASS',
        'MAINTENANCE_MODE' => '0',
        'CONFIG_STATUS' => 'DIFFERENT',
        'MAX_ALLOWED_PACKET' => '67108864',
        'MAX_ALLOWED_PACKET_SOURCE' => 'DRUPAL_DB_API',
        'PUBLIC_HOME' => 'PASS',
        'PUBLIC_HOME_HTTP_CODE' => '200',
        'PUBLIC_HOME_EFFECTIVE_PATH' => '/fr',
        'CONTACT_FORM_SURFACE' => 'PASS',
        'RECENT_ERROR_READ_CAPABILITY' => 'PASS',
        'RECENT_NGINX_PHP_ERRORS' => 'NONE_MATERIAL',
        'NGINX_RECENT_ERROR_COUNT' => '0',
        'PHP_FPM_RECENT_ERROR_COUNT' => '0',
        'DISK_AVAILABLE_KB' => (string) $diskAvailableKb,
      ], $overrides);
      $assignments = [];
      foreach ($environment as $name => $value) {
        $assignments[] = $name . '=' . escapeshellarg((string) $value);
      }
      $command = 'env ' . implode(' ', $assignments)
        . ' python3 ' . escapeshellarg($script)
        . ' >' . escapeshellarg($stdout)
        . ' 2>' . escapeshellarg($stderr);
      $output = [];
      $status = 1;
      exec($command, $output, $status);
      return [
        'status' => $status,
        'stdout' => trim((string) file_get_contents($stdout)),
        'stderr' => trim((string) file_get_contents($stderr)),
      ];
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($directory);
    }
  }

  /**
   * Runs only the bounded public-home probe with a fake local curl binary.
   */
  private function runPublicHomeProbe(string $meta, string $h1): array {
    $source = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match('/# BEGIN #1190 PUBLIC HOME PROBE\n(.*?)# END #1190 PUBLIC HOME PROBE/s', $source, $matches),
    );
    $directory = sys_get_temp_dir() . '/agency-1190-home-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $curl = $directory . '/curl';
      file_put_contents($curl, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
output=''
while (( $# > 0 )); do
  case "$1" in
    --output) output="$2"; shift 2 ;;
    *) shift ;;
  esac
done
printf '<html><body><img src="/themes/custom/emerging_digital/images/branding/emerging-digital-mark.svg" alt="E-merging Digital"><h1>%s</h1></body></html>' "$FAKE_H1" > "$output"
printf '%s' "$FAKE_META"
SH
      );
      chmod($curl, 0700);
      $runner = $directory . '/probe.sh';
      file_put_contents(
        $runner,
        "#!/usr/bin/env bash\nset -euo pipefail\nPROD_URL='https://emergingdigital.be'\n"
        . $matches[1]
        . "\nprobe_public_home \"$directory/home.html\"\n"
        . "printf '%s|%s|%s\\n' \"\$PUBLIC_HOME\" \"\$PUBLIC_HOME_HTTP_CODE\" \"\$PUBLIC_HOME_EFFECTIVE_PATH\"\n",
      );
      chmod($runner, 0700);
      $command = 'env PATH=' . escapeshellarg($directory . ':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin')
        . ' FAKE_META=' . escapeshellarg($meta)
        . ' FAKE_H1=' . escapeshellarg($h1)
        . ' bash ' . escapeshellarg($runner) . ' 2>&1';
      $output = [];
      $status = 1;
      exec($command, $output, $status);
      self::assertSame(0, $status, implode("\n", $output));
      return explode('|', trim(implode("\n", $output)));
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($directory);
    }
  }

  /**
   * Decodes exactly one bounded receipt object.
   */
  private function decodeReceipt(string $json): array {
    $receipt = json_decode($json, TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($receipt);
    return $receipt;
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
  }

}

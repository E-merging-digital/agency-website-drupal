<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects #1194 PROD PLAN decidability without weakening safety.
 *
 * @group agency_project_tests
 */
final class ProdOsMaintenance1194DecidabilityTest extends TestCase {

  private const PLAN = 'scripts/production-maintenance-1183/remote-plan.sh';
  private const APPLY = 'scripts/production-maintenance-1183/remote-apply.sh';
  private const WORKFLOW = '.github/workflows/prod-os-maintenance-1183.yml';

  /**
   * Exact normal-policy selection keeps the successful PLAN semantics.
   */
  public function testExactNormalPolicySetPasses(): void {
    $receipt = $this->evaluatePlan();
    self::assertSame('PASS', $receipt['APT_POLICY_CLASSIFICATION']);
    self::assertSame($receipt['PACKAGE_UPGRADES'], $receipt['APT_NORMAL_SELECTED_UPGRADES']);
    self::assertSame([], $receipt['APT_PHASED_DEFERRED_PACKAGES']);
    self::assertSame([], $receipt['APT_UNCLASSIFIED_MISSING_UPGRADABLE']);
    self::assertSame([], $receipt['APT_SIMULATION_UNEXPECTED_UPGRADES']);
  }

  /**
   * Forced phasing may classify a non-security normal-policy omission only.
   */
  public function testPhasedDeferredClassificationIsObservationOnly(): void {
    $upgradable = $this->defaultUpgradable()
      . "krb5-locales/noble-updates 1.20.1-6ubuntu2.6 all [upgradable from: 1.20.1-6ubuntu2.5]\n";
    $phased = $this->defaultSimulation()
      . "Inst krb5-locales [1.20.1-6ubuntu2.5] (1.20.1-6ubuntu2.6 Ubuntu:24.04/noble-updates [all])\n";
    $receipt = $this->evaluatePlan([], $upgradable, $this->defaultSimulation(), $phased);

    self::assertSame('PASS', $receipt['APT_POLICY_CLASSIFICATION']);
    self::assertSame([
      ['from' => '1.20.1-6ubuntu2.5', 'name' => 'krb5-locales', 'to' => '1.20.1-6ubuntu2.6'],
    ], $receipt['APT_PHASED_DEFERRED_PACKAGES']);
    self::assertSame([], $receipt['APT_UNCLASSIFIED_MISSING_UPGRADABLE']);
    self::assertSame([], $receipt['APT_PHASED_DEFERRED_SECURITY']);
    self::assertNotContains('krb5-locales', array_column($receipt['PACKAGE_UPGRADES'], 'name'));
  }

  /**
   * APPLY never forces phased updates and uses only the approved normal tuples.
   */
  public function testApplyNeverForcesPhasedUpdates(): void {
    $apply = $this->source(self::APPLY);
    self::assertStringNotContainsString('Always-Include-Phased-Updates', $apply);
    self::assertStringContainsString('PACKAGE_UPGRADES', $apply);
  }

  /**
   * Missing packages not proven phased remain blocking.
   */
  public function testUnclassifiedMissingRemainsFailClosed(): void {
    $upgradable = $this->defaultUpgradable()
      . "libnetplan1/noble-updates 1.1.2-2~ubuntu24.04.2 amd64 [upgradable from: 1.1.2-2~ubuntu24.04.1]\n";
    $result = $this->executePlan([], $upgradable, $this->defaultSimulation(), $this->defaultSimulation());
    $receipt = $this->failReceipt($result);

    self::assertSame('FAIL', $receipt['APT_POLICY_CLASSIFICATION']);
    self::assertSame(['libnetplan1'], $receipt['APT_UNCLASSIFIED_MISSING_UPGRADABLE']);
    self::assertContains('apt_policy_classification', $receipt['FAILED_CHECKS']);
    self::assertNull($receipt['PLAN_DIGEST']);
  }

  /**
   * Unexpected normal-policy upgrades remain blocking.
   */
  public function testUnexpectedNormalUpgradeRemainsFailClosed(): void {
    $normal = $this->defaultSimulation()
      . "Inst curl [8.5.0-2ubuntu10.5] (8.5.0-2ubuntu10.6 Ubuntu:24.04/noble-updates [amd64])\n";
    $result = $this->executePlan([], NULL, $normal, $normal);
    $receipt = $this->failReceipt($result);

    self::assertSame(['curl'], $receipt['APT_SIMULATION_UNEXPECTED_UPGRADES']);
    self::assertContains('apt_unexpected_empty', $receipt['FAILED_CHECKS']);
  }

  /**
   * Security updates can never be accepted as phased-deferred.
   */
  public function testSecurityPhasedDeferredIsForbidden(): void {
    $upgradable = $this->defaultUpgradable()
      . "libssl3t64/noble-security 3.0.13-0ubuntu3.6 amd64 [upgradable from: 3.0.13-0ubuntu3.5]\n";
    $phased = $this->defaultSimulation()
      . "Inst libssl3t64 [3.0.13-0ubuntu3.5] (3.0.13-0ubuntu3.6 Ubuntu:24.04/noble-security [amd64])\n";
    $result = $this->executePlan([], $upgradable, $this->defaultSimulation(), $phased);
    $receipt = $this->failReceipt($result);

    self::assertSame(['libssl3t64'], $receipt['APT_PHASED_DEFERRED_SECURITY']);
    self::assertContains('apt_phased_deferred_security_empty', $receipt['FAILED_CHECKS']);
    self::assertSame('FAIL', $receipt['APT_POLICY_CLASSIFICATION']);
  }

  /**
   * Receipt exposes bounded classification, never raw apt repository lines.
   */
  public function testAptReceiptDoesNotExposeRawMetadata(): void {
    $receipt = $this->evaluatePlan();
    $json = json_encode($receipt, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('Ubuntu:24.04/noble-updates', $json);
    self::assertStringNotContainsString('Listing...', $json);
    self::assertStringNotContainsString('http://', $json);
  }

  /**
   * Homepage stays strict while allowing marketing copy to evolve.
   */
  public function testPublicHomeUsesStableStructureAndBrandIdentity(): void {
    $body = '<html><body><img src="/themes/custom/emerging_digital/images/branding/emerging-digital-mark.svg" alt="E-merging Digital"><h1>Nouvelle proposition de valeur</h1></body></html>';
    self::assertSame(['PASS', '200', '/fr'], $this->runPublicHomeProbe('200|https://emergingdigital.be/fr', $body));
    self::assertSame(['FAIL', '200', 'UNKNOWN'], $this->runPublicHomeProbe('200|https://example.invalid/fr', $body));
    self::assertSame(['FAIL', '503', '/fr'], $this->runPublicHomeProbe('503|https://emergingdigital.be/fr', $body));
    self::assertSame(['FAIL', '200', '/fr'], $this->runPublicHomeProbe('200|https://emergingdigital.be/fr', '<html><body><h1>Other site</h1></body></html>'));

    $source = $this->source(self::PLAN);
    self::assertStringNotContainsString('Créer, améliorer ou moderniser votre plateforme web', $source);
    self::assertStringContainsString('/images/branding/emerging-digital-mark.svg', $source);
  }

  /**
   * MAX_ALLOWED_PACKET stays strict with a bounded observation source.
   */
  public function testMaxAllowedPacketValueAndSourceRemainStrict(): void {
    $pass = $this->evaluatePlan();
    self::assertSame('67108864', $pass['MAX_ALLOWED_PACKET']);
    self::assertSame('DRUPAL_DB_API', $pass['MAX_ALLOWED_PACKET_SOURCE']);
    $sudo = $this->evaluatePlan(['MAX_ALLOWED_PACKET_SOURCE' => 'SUDO_MARIADB']);
    self::assertSame('SUDO_MARIADB', $sudo['MAX_ALLOWED_PACKET_SOURCE']);

    foreach ([
      ['MAX_ALLOWED_PACKET' => '16777216'],
      ['MAX_ALLOWED_PACKET' => 'UNKNOWN', 'MAX_ALLOWED_PACKET_SOURCE' => 'UNKNOWN'],
    ] as $override) {
      $receipt = $this->failReceipt($this->executePlan($override));
      self::assertContains('max_allowed_packet_64m', $receipt['FAILED_CHECKS']);
    }
  }

  /**
   * Source contract prefers Drupal DB API and bounds the sudo fallback.
   */
  public function testMaxAllowedPacketReadPathsAreReadOnlyAndBounded(): void {
    $source = $this->source(self::PLAN);
    self::assertStringContainsString("vendor/bin/drush php:eval", $source);
    self::assertStringContainsString('\\Drupal::database()->query', $source);
    self::assertStringContainsString("max_allowed_packet_source='DRUPAL_DB_API'", $source);
    self::assertStringContainsString("sudo -n mariadb -NBe 'SELECT @@global.max_allowed_packet;'", $source);
    self::assertStringContainsString("max_allowed_packet_source='SUDO_MARIADB'", $source);
    self::assertStringNotContainsString('/etc/sudoers', $source);
  }

  /**
   * Recent-error read capability never maps UNKNOWN to zero.
   */
  public function testRecentErrorCapabilityIsExplicitAndFailClosed(): void {
    $pass = $this->evaluatePlan();
    self::assertSame('PASS', $pass['RECENT_ERROR_READ_CAPABILITY']);
    self::assertSame(0, $pass['NGINX_RECENT_ERROR_COUNT']);
    self::assertSame(0, $pass['PHP_FPM_RECENT_ERROR_COUNT']);

    $nonzero = $this->failReceipt($this->executePlan([
      'RECENT_NGINX_PHP_ERRORS' => 'PRESENT_MATERIAL',
      'NGINX_RECENT_ERROR_COUNT' => '2',
      'PHP_FPM_RECENT_ERROR_COUNT' => '0',
    ]));
    self::assertContains('recent_nginx_php_errors', $nonzero['FAILED_CHECKS']);

    $unreadable = $this->failReceipt($this->executePlan([
      'RECENT_ERROR_READ_CAPABILITY' => 'FAIL',
      'NGINX_RECENT_ERROR_COUNT' => 'UNKNOWN',
      'PHP_FPM_RECENT_ERROR_COUNT' => 'UNKNOWN',
      'RECENT_NGINX_PHP_ERRORS' => 'UNKNOWN',
    ]));
    self::assertContains('recent_error_read_capability', $unreadable['FAILED_CHECKS']);
    self::assertSame('UNKNOWN', $unreadable['NGINX_RECENT_ERROR_COUNT']);
  }

  /**
   * Journal evidence remains count-only with no raw-log receipt surface.
   */
  public function testRecentErrorSourceNeverPublishesRawLogs(): void {
    $source = $this->source(self::PLAN);
    self::assertStringContainsString('--output=json', $source);
    self::assertStringContainsString("awk 'NF {count++} END {print count + 0}'", $source);
    self::assertStringContainsString("recent_error_read_capability='FAIL'", $source);
    self::assertStringNotContainsString('--output=cat', $source);
    self::assertStringNotContainsString('RECENT_ERROR_LINES', $source);
  }

  /**
   * Multi-gate failure remains complete and non-approvable.
   */
  public function testMultiGateFailureAndWorkflowContractRemainBounded(): void {
    $upgradable = $this->defaultUpgradable()
      . "libnetplan1/noble-updates 1.1.2-2~ubuntu24.04.2 amd64 [upgradable from: 1.1.2-2~ubuntu24.04.1]\n";
    $result = $this->executePlan([
      'MAX_ALLOWED_PACKET' => 'UNKNOWN',
      'MAX_ALLOWED_PACKET_SOURCE' => 'UNKNOWN',
      'RECENT_ERROR_READ_CAPABILITY' => 'FAIL',
      'RECENT_NGINX_PHP_ERRORS' => 'UNKNOWN',
      'NGINX_RECENT_ERROR_COUNT' => 'UNKNOWN',
      'PHP_FPM_RECENT_ERROR_COUNT' => 'UNKNOWN',
    ], $upgradable, $this->defaultSimulation(), $this->defaultSimulation());
    $receipt = $this->failReceipt($result);
    foreach (['apt_policy_classification', 'max_allowed_packet_64m', 'recent_error_read_capability'] as $check) {
      self::assertContains($check, $receipt['FAILED_CHECKS']);
    }
    self::assertNull($receipt['PLAN_DIGEST']);
    self::assertSame('YES', $receipt['CANNOT_BE_APPROVED']);
    self::assertSame('NONE', $receipt['REAL_PROD_MUTATION']);

    $workflow = $this->source(self::WORKFLOW);
    self::assertStringContainsString('if: ${{ always() }}', $workflow);
    self::assertStringContainsString('.STATUS == "PASS"', $workflow);
    self::assertStringContainsString('.SAFETY_GATE == "PASS"', $workflow);
  }

  /**
   * Healthy free-disk drift still does not perturb the PASS digest.
   */
  public function testHealthyDiskDriftKeepsPassDigestStable(): void {
    $first = $this->evaluatePlan([], NULL, NULL, NULL, 13696200);
    $second = $this->evaluatePlan([], NULL, NULL, NULL, 13695000);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $first['PLAN_DIGEST']);
    self::assertSame($first['PLAN_DIGEST'], $second['PLAN_DIGEST']);
  }

  /**
   * Evaluates one successful synthetic PLAN receipt.
   */
  private function evaluatePlan(
    array $overrides = [],
    ?string $upgradableRaw = NULL,
    ?string $normalSimulation = NULL,
    ?string $phasedSimulation = NULL,
    int $diskAvailableKb = 13696200,
  ): array {
    $result = $this->executePlan($overrides, $upgradableRaw, $normalSimulation, $phasedSimulation, $diskAvailableKb);
    self::assertSame(0, $result['status'], $result['stderr']);
    return $this->decodeReceipt($result['stdout']);
  }

  /**
   * Returns one bounded rejected receipt.
   */
  private function failReceipt(array $result): array {
    self::assertNotSame(0, $result['status']);
    $receipt = $this->decodeReceipt($result['stdout']);
    self::assertSame('FAIL', $receipt['STATUS']);
    self::assertSame('FAIL', $receipt['SAFETY_GATE']);
    self::assertNull($receipt['PLAN_DIGEST']);
    return $receipt;
  }

  /**
   * Executes only the embedded deterministic PLAN evaluator.
   */
  private function executePlan(
    array $overrides = [],
    ?string $upgradableRaw = NULL,
    ?string $normalSimulation = NULL,
    ?string $phasedSimulation = NULL,
    int $diskAvailableKb = 13696200,
  ): array {
    $source = $this->source(self::PLAN);
    self::assertSame(1, preg_match("/python3 - <<'PY'\\n(.*?)\\nPY\\n/s", $source, $matches));
    $directory = sys_get_temp_dir() . '/agency-1194-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $normalSimulation ??= $this->defaultSimulation();
      file_put_contents($directory . '/upgradable.raw', $upgradableRaw ?? $this->defaultUpgradable());
      file_put_contents($directory . '/upgrade-sim.raw', $normalSimulation);
      file_put_contents($directory . '/upgrade-sim-phased.raw', $phasedSimulation ?? $normalSimulation);
      file_put_contents($directory . '/held.raw', '');
      file_put_contents($directory . '/failed.raw', '');
      $script = $directory . '/plan.py';
      file_put_contents($script, $matches[1] . "\n");
      $stdout = $directory . '/stdout.json';
      $stderr = $directory . '/stderr.txt';
      $environment = array_replace([
        'WORK_ROOT' => $directory,
        'MAIN_SHA' => str_repeat('a', 40),
        'PLAN_ID' => 'plan-1183-decidability-fixture-r1',
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
   * Runs only the bounded public-home probe with local synthetic HTML.
   */
  private function runPublicHomeProbe(string $meta, string $body): array {
    $source = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match('/# BEGIN #1190 PUBLIC HOME PROBE\n(.*?)# END #1190 PUBLIC HOME PROBE/s', $source, $matches),
    );
    $directory = sys_get_temp_dir() . '/agency-1194-home-' . bin2hex(random_bytes(6));
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
printf '%s' "$FAKE_BODY" > "$output"
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
        . ' FAKE_BODY=' . escapeshellarg($body)
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
   * Default apt list fixture.
   */
  private function defaultUpgradable(): string {
    return "Listing...\nnginx/noble-updates 1.24.0-2ubuntu7.18 amd64 [upgradable from: 1.24.0-2ubuntu7.17]\n";
  }

  /**
   * Default normal-policy apt simulation fixture.
   */
  private function defaultSimulation(): string {
    return "Inst nginx [1.24.0-2ubuntu7.17] (1.24.0-2ubuntu7.18 Ubuntu:24.04/noble-updates [amd64])\n";
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

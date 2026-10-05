<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Proves deterministic stale-plan digest semantics for #1187.
 *
 * @group agency_project_tests
 */
final class PreprodOsMaintenance1187DigestTest extends TestCase {

  private const PLAN = 'scripts/preproduction-maintenance-1182/remote-plan.sh';

  /**
   * Healthy free-space fluctuations stay observable without changing identity.
   */
  public function testHealthyDiskVariationKeepsSameDigestAndReceiptValue(): void {
    $first = $this->evaluatePlan(13696200);
    $second = $this->evaluatePlan(13695728);

    self::assertSame(13696200, $first['DISK_AVAILABLE_KB']);
    self::assertSame(13695728, $second['DISK_AVAILABLE_KB']);
    self::assertSame('PASS', $first['SAFETY_GATE']);
    self::assertSame('PASS', $second['SAFETY_GATE']);
    self::assertSame($first['PLAN_DIGEST'], $second['PLAN_DIGEST']);
  }

  /**
   * A package candidate/version drift remains part of stale-plan identity.
   */
  public function testPackageVersionDriftChangesDigest(): void {
    $approved = $this->evaluatePlan(13696200, '1.24.0-2ubuntu7.18');
    $drifted = $this->evaluatePlan(13696200, '1.24.0-2ubuntu7.19');

    self::assertNotSame($approved['PLAN_DIGEST'], $drifted['PLAN_DIGEST']);
    self::assertNotSame(
      $approved['PACKAGE_UPGRADES'],
      $drifted['PACKAGE_UPGRADES'],
    );
  }

  /**
   * Runtime identity drift either changes identity or fails the safety gate.
   */
  public function testMaterialRuntimeDriftChangesDigestOrFailsClosed(): void {
    $approved = $this->evaluatePlan(13696200);
    $kernelDrift = $this->evaluatePlan(
      13696200,
      '1.24.0-2ubuntu7.18',
      ['KERNEL_RUNNING' => '6.8.0-137-generic'],
    );

    self::assertNotSame($approved['PLAN_DIGEST'], $kernelDrift['PLAN_DIGEST']);

    $serviceFailure = $this->executePlan(
      13696200,
      '1.24.0-2ubuntu7.18',
      ['NGINX_SERVICE' => 'inactive'],
    );
    self::assertNotSame(0, $serviceFailure['status']);
    self::assertStringContainsString('nginx_active', $serviceFailure['output']);
  }

  /**
   * Disk availability below two GiB still fails closed before receipt success.
   */
  public function testLowDiskFailsClosed(): void {
    $failure = $this->executePlan((2 * 1024 * 1024) - 1);

    self::assertNotSame(0, $failure['status']);
    self::assertStringContainsString(
      'disk_space_min_2gib',
      $failure['output'],
    );
  }

  /**
   * The source explicitly separates receipt observations from digest identity.
   */
  public function testSourceSeparatesDiskObservationFromMutationIdentity(): void {
    $source = $this->source(self::PLAN);

    self::assertStringContainsString("receipt = {", $source);
    self::assertStringContainsString(
      "'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB'])",
      $source,
    );
    self::assertStringContainsString(
      "'disk_space_min_2gib': int(os.environ['DISK_AVAILABLE_KB']) >= 2 * 1024 * 1024",
      $source,
    );
    self::assertStringContainsString('mutation_identity = {', $source);
    self::assertStringContainsString('mutation_identity_keys = (', $source);
    self::assertStringNotContainsString("    'DISK_AVAILABLE_KB',", $source);
    foreach ([
      "'MAIN_SHA'",
      "'PLAN_ID'",
      "'PACKAGE_UPGRADES'",
      "'PACKAGE_ADDITIONS'",
      "'PACKAGE_REMOVALS'",
      "'HELD_PACKAGES'",
      "'KERNEL_RUNNING'",
      "'NGINX_SERVICE'",
      "'DRUPAL_HEALTH'",
      "'PUBLIC_HEALTH'",
      "'SAFETY_GATE'",
    ] as $identityKey) {
      self::assertStringContainsString($identityKey, $source);
    }
    self::assertStringContainsString(
      'json.dumps(mutation_identity, sort_keys=True',
      $source,
    );
    self::assertStringContainsString(
      "receipt['PLAN_DIGEST'] = hashlib.sha256(canonical).hexdigest()",
      $source,
    );
  }

  /**
   * Executes the actual embedded PLAN evaluator and returns its receipt.
   */
  private function evaluatePlan(
    int $diskAvailableKb,
    string $candidate = '1.24.0-2ubuntu7.18',
    array $overrides = [],
  ): array {
    $result = $this->executePlan($diskAvailableKb, $candidate, $overrides);
    self::assertSame(0, $result['status'], $result['output']);
    $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($receipt);
    return $receipt;
  }

  /**
   * Executes the exact Python heredoc from remote-plan.sh on synthetic inputs.
   */
  private function executePlan(
    int $diskAvailableKb,
    string $candidate = '1.24.0-2ubuntu7.18',
    array $overrides = [],
  ): array {
    $source = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match("/python3 - <<'PY'\\n(.*?)\\nPY\\n/s", $source, $matches),
    );

    $directory = sys_get_temp_dir() . '/agency-1187-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      file_put_contents(
        $directory . '/upgradable.raw',
        "Listing...\nnginx/noble-updates {$candidate} amd64 [upgradable from: 1.24.0-2ubuntu7.17]\n",
      );
      file_put_contents(
        $directory . '/upgrade-sim.raw',
        "Inst nginx [1.24.0-2ubuntu7.17] ({$candidate} Ubuntu:24.04/noble-updates [amd64])\n",
      );
      file_put_contents($directory . '/held.raw', '');
      file_put_contents($directory . '/failed.raw', '');
      $script = $directory . '/plan.py';
      file_put_contents($script, $matches[1] . "\n");

      $environment = array_replace([
        'WORK_ROOT' => $directory,
        'MAIN_SHA' => str_repeat('a', 40),
        'PLAN_ID' => 'plan-1182-deterministic-fixture-r1',
        'ISSUE' => '1182',
        'TARGET' => 'PREPROD',
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
        'DISK_AVAILABLE_KB' => (string) $diskAvailableKb,
      ], $overrides);

      $assignments = [];
      foreach ($environment as $name => $value) {
        $assignments[] = $name . '=' . escapeshellarg((string) $value);
      }
      $command = 'env ' . implode(' ', $assignments)
        . ' python3 ' . escapeshellarg($script) . ' 2>&1';
      $output = [];
      $status = 1;
      exec($command, $output, $status);
      return [
        'status' => $status,
        'output' => implode("\n", $output),
      ];
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $path) {
        @unlink($path);
      }
      @rmdir($directory);
    }
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

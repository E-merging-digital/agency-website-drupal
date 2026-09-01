<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Covers the pull-only Development Seed source/static/synthetic contract.
 */
final class DevelopmentSeedContractTest extends TestCase {

  /**
   * Executes the data-free #873 proof under canonical PHPUnit CI.
   */
  public function testSyntheticDevelopmentSeedContract(): void {
    $root = dirname(DRUPAL_ROOT);
    $script = $root . '/scripts/development-seed/test-contract.php';
    self::assertFileExists($script);

    $descriptor = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $process = proc_open([PHP_BINARY, $script], $descriptor, $pipes, $root);
    self::assertIsResource($process);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    self::assertSame(0, $exitCode, (string) $stderr);
    self::assertIsString($stdout);
    foreach ([
      'EXISTING_CAPABILITY_AUDIT=COMPLETE',
      'SYNTHETIC_SEED_PROOF=PASS',
      'CORRUPT_HASH=FAIL_CLOSED',
      'UNSUPPORTED_DOWNGRADE=FAIL_CLOSED',
      'SIDE_EFFECT_ASSERTIONS=PASS',
      'PULL_ONLY_CONTRACT=PASS',
      'REAL_PROD_ACCESS=NONE',
      'REAL_PREPROD_DATA_READ=NONE',
      'REAL_SEED_GENERATION=NONE',
      'REAL_SEED_DISTRIBUTION=NONE',
    ] as $expected) {
      self::assertStringContainsString($expected, $stdout);
    }
  }

}

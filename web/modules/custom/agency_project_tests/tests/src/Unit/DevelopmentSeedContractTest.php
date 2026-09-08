<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Covers the Development Seed source/static/synthetic contract.
 */
final class DevelopmentSeedContractTest extends TestCase {

  /**
   * Proves #1111 host toolchain preconditions remain minimal and explicit.
   */
  public function testPublisherHostToolchainContract(): void {
    $root = dirname(DRUPAL_ROOT);
    $publisher = file_get_contents($root . '/scripts/development-seed/run-publish.sh');
    self::assertIsString($publisher);

    self::assertStringContainsString(
      'for command_name in ddev git jq openssl scp sha256sum ssh ssh-add ssh-agent ssh-keygen; do',
      $publisher,
    );
    self::assertStringNotContainsString(
      'for command_name in ddev git jq openssl php scp sha256sum ssh ssh-add ssh-agent ssh-keygen; do',
      $publisher,
    );
    self::assertStringContainsString(
      'MISSING_REQUIRED_COMMAND=%s',
      $publisher,
    );
    self::assertStringContainsString(
      'if ! command -v "$command_name" >/dev/null 2>&1; then',
      $publisher,
    );
    self::assertStringContainsString(
      'ssh_args=(ssh -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" -o ConnectTimeout=15)',
      $publisher,
    );
    self::assertStringContainsString(
      'scp_args=(scp -i "$PREPROD_SSH_KEY" -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" -o ConnectTimeout=15)',
      $publisher,
    );
    self::assertSame(
      3,
      substr_count($publisher, '"${ssh_args[@]}" "$remote_target"'),
      'Every SSH action must invoke the command vector that starts with ssh.',
    );
    self::assertSame(
      3,
      substr_count($publisher, '"${scp_args[@]}" -q --'),
      'Every SCP publication must invoke the command vector that starts with scp.',
    );
    self::assertStringContainsString(
      'ddev drush --quiet php:script scripts/preproduction-refresh/governed-successor/agency-sanitize.php',
      $publisher,
    );
    self::assertStringContainsString(
      'ddev drush --quiet php:script scripts/development-seed/agency-development-sanitize.php',
      $publisher,
    );
    self::assertStringContainsString(
      'ddev exec php scripts/development-seed/build-seed-metadata.php',
      $publisher,
    );
    self::assertStringContainsString(
      'ddev exec php scripts/development-seed/verify-seed.php',
      $publisher,
    );
  }

  /**
   * Executes the data-free #873/#1108 proof under canonical PHPUnit CI.
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
      'POST_SANITIZATION_SQL_ROUNDTRIP=REMOVED',
      'DDEV_NATIVE_SEED_SNAPSHOT=USED',
      'DDEV_NATIVE_EXPLICIT_RESET=USED',
      'EXTERNAL_SANITIZED_SNAPSHOT=DEFAULT',
      'SANITIZATION_BEFORE_SNAPSHOT=REQUIRED',
      'SEED_SHA256=VERIFIED',
      'DATABASE_COMPATIBILITY=mariadb:11.8/FAIL_CLOSED',
      'IMPLICIT_RESET=NONE',
      'RESET_DEFAULT_BACKUP=PRESERVED',
      'CORRUPT_HASH=FAIL_CLOSED',
      'UNSUPPORTED_DOWNGRADE=FAIL_CLOSED',
      'SIDE_EFFECT_ASSERTIONS=PASS',
      'REAL_PROD_ACCESS=NONE',
      'REAL_PREPROD_DATA_READ=NONE',
      'REAL_SEED_GENERATION=NONE',
      'REAL_SEED_DISTRIBUTION=NONE',
    ] as $expected) {
      self::assertStringContainsString($expected, $stdout);
    }
  }

}

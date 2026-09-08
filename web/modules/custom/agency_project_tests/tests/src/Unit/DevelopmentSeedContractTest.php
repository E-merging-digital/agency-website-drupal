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
   * Proves #1121 sanitize failures stay bounded and privacy-safe.
   */
  public function testPublisherSanitizeFailureDiagnosticContract(): void {
    $root = dirname(DRUPAL_ROOT);
    $publisher = file_get_contents($root . '/scripts/development-seed/run-publish.sh');
    $workflow = file_get_contents($root . '/.github/workflows/development-seed-publish.yml');
    self::assertIsString($publisher);
    self::assertIsString($workflow);

    self::assertStringContainsString(
      'sanitize_diagnostic="$temp_abs/$REQUEST_ID.sql-sanitize.diagnostic"',
      $publisher,
    );
    self::assertStringContainsString(
      '(umask 077; set -o noclobber; : > "$sanitize_diagnostic")',
      $publisher,
    );
    self::assertStringContainsString(
      '[[ "$(stat -c \'%a\' "$sanitize_diagnostic")" == 600 ]]',
      $publisher,
    );
    self::assertSame(1, substr_count($publisher, 'ddev drush sql:sanitize -y'));
    self::assertSame(1, substr_count($publisher, "--sanitize-email='user+%uid@example.invalid'"));
    self::assertSame(1, substr_count($publisher, '--sanitize-password="$seed_password"'));
    self::assertStringContainsString(') > "$sanitize_diagnostic" 2>&1; then', $publisher);
    self::assertSame(4, substr_count($publisher, 'LC_ALL=C grep -Eiq --'));
    foreach (['UNCLASSIFIED', 'COMMAND', 'BOOTSTRAP', 'SCHEMA', 'RUNTIME'] as $class) {
      self::assertStringContainsString("failure_class='$class'", $publisher);
    }
    foreach (['SANITIZE_FAILURE=YES', 'SANITIZE_FAILURE_CLASS=%s', 'SANITIZE_FAILURE_EXIT=%s'] as $key) {
      self::assertStringContainsString($key, $publisher);
    }
    self::assertStringContainsString(
      'rm -f -- "$raw" "$sanitize_diagnostic" "$known_hosts"',
      $publisher,
    );
    self::assertStringContainsString(
      '[[ ! -e "$raw" && ! -e "$sanitize_diagnostic"',
      $publisher,
    );
    foreach (['cat', 'head', 'tail', 'tee', 'cp', 'scp'] as $forbidden) {
      self::assertStringNotContainsString($forbidden . ' "$sanitize_diagnostic"', $publisher);
    }
    self::assertStringNotContainsString('sql-sanitize.diagnostic', $workflow);
    self::assertStringNotContainsString('sanitize_diagnostic', $workflow);
    $sqlSanitize = strpos($publisher, 'ddev drush sql:sanitize -y');
    $agencySanitize = strpos(
      $publisher,
      'ddev drush --quiet php:script scripts/preproduction-refresh/governed-successor/agency-sanitize.php',
    );
    $developmentSanitize = strpos(
      $publisher,
      'ddev drush --quiet php:script scripts/development-seed/agency-development-sanitize.php',
    );
    self::assertIsInt($sqlSanitize);
    self::assertIsInt($agencySanitize);
    self::assertIsInt($developmentSanitize);
    self::assertTrue($sqlSanitize < $agencySanitize);
    self::assertTrue($agencySanitize < $developmentSanitize);
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

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
   * Proves #1131 materializes Composer inside the fresh DDEV worktree.
   */
  public function testPublisherGenerationComposerMaterializationContract(): void {
    $root = dirname(DRUPAL_ROOT);
    $publisher = file_get_contents($root . '/scripts/development-seed/run-publish.sh');
    self::assertIsString($publisher);

    self::assertSame(1, substr_count($publisher, 'ddev composer install --no-interaction --no-progress --prefer-dist'));
    self::assertStringNotContainsString('ddev composer update', $publisher);
    self::assertStringContainsString(
      '[[ -f "$generation/composer.lock" && ! -L "$generation/composer.lock" ]]',
      $publisher,
    );

    $worktree = strpos($publisher, 'git worktree add --detach "$generation" "$REPOSITORY_SHA"');
    $lock = strpos(
      $publisher,
      '[[ -f "$generation/composer.lock" && ! -L "$generation/composer.lock" ]]',
      $worktree,
    );
    $start = strpos($publisher, 'ddev start -y >/dev/null', $lock);
    $composer = strpos($publisher, 'ddev composer install --no-interaction --no-progress --prefer-dist', $start);
    $import = strpos($publisher, 'ddev import-db --file="$raw" >/dev/null', $composer);
    $sanitize = strpos($publisher, 'ddev drush -vvv sql:sanitize -y', $import);
    self::assertIsInt($worktree);
    self::assertIsInt($lock);
    self::assertIsInt($start);
    self::assertIsInt($composer);
    self::assertIsInt($import);
    self::assertIsInt($sanitize);
    self::assertTrue($worktree < $lock);
    self::assertTrue($lock < $start);
    self::assertTrue($start < $composer);
    self::assertTrue($composer < $import);
    self::assertTrue($composer < $sanitize);
  }

  /**
   * Proves #1121/#1138 sanitize diagnostics stay bounded and privacy-safe.
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
    self::assertSame(1, substr_count($publisher, 'ddev drush -vvv sql:sanitize -y'));
    self::assertSame(1, substr_count($publisher, "--sanitize-email='user+%uid@example.invalid'"));
    self::assertSame(1, substr_count($publisher, '--sanitize-password="$seed_password"'));
    self::assertStringContainsString(') > "$sanitize_diagnostic" 2>&1; then', $publisher);
    self::assertSame(4, substr_count($publisher, 'LC_ALL=C grep -Eiq --'));
    foreach (['UNCLASSIFIED', 'COMMAND', 'BOOTSTRAP', 'SCHEMA', 'RUNTIME'] as $class) {
      self::assertStringContainsString("failure_class='$class'", $publisher);
    }
    foreach ([
      'SANITIZE_FAILURE=YES',
      'SANITIZE_FAILURE_CLASS=%s',
      'SANITIZE_FAILURE_COMPONENT=%s',
      'SANITIZE_FAILURE_EXIT=%s',
    ] as $key) {
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
    self::assertStringContainsString('SANITIZE_DIAGNOSTIC_CLEANUP=FAIL', $publisher);
    self::assertStringContainsString('sanitize plugin is using a deprecated API', $publisher);

    $agencySanitizer = file_get_contents(
      $root . '/scripts/preproduction-refresh/governed-successor/agency-sanitize.php',
    );
    self::assertIsString($agencySanitizer);
    self::assertStringContainsString(
      "name NOT REGEXP '^preprod-user-[0-9]+$'",
      $agencySanitizer,
    );
    self::assertStringContainsString(
      "mail NOT LIKE '%@example.invalid'",
      $agencySanitizer,
    );
    self::assertStringNotContainsString(
      "name NOT REGEXP '^preprod-user-[0-9]+$' OR mail NOT LIKE '%@example.invalid'",
      $agencySanitizer,
    );
    self::assertStringContainsString(
      'USER_SANITIZATION_ASSERTION_COMPONENT = {$component}',
      $agencySanitizer,
    );
    self::assertStringNotContainsString(
      'Drush/Agency user sanitization assertion failed.',
      $agencySanitizer,
    );
    self::assertStringContainsString(
      "->expression('name', \"CONCAT('preprod-user-', uid)\")",
      $agencySanitizer,
    );

    $classifierStart = strpos(
      $agencySanitizer,
      '$classifyUserSanitizationAssertion = static function',
    );
    $classifierEnd = strpos($agencySanitizer, "\n};", $classifierStart);
    self::assertIsInt($classifierStart);
    self::assertIsInt($classifierEnd);
    $classifierSource = substr(
      $agencySanitizer,
      $classifierStart,
      $classifierEnd - $classifierStart + 3,
    );
    $classifierRunner = <<<'PHP'
$source = $argv[1];
$nameFailed = $argv[2] === '1';
$mailFailed = $argv[3] === '1';
eval($source);
$result = $classifyUserSanitizationAssertion($nameFailed, $mailFailed);
fwrite(STDOUT, $result === NULL ? "PASS\n" : $result . "\n");
PHP;
    $classifierFixtures = [
      'USER_NAME' => [TRUE, FALSE],
      'USER_MAIL' => [FALSE, TRUE],
      'USER_NAME_AND_MAIL' => [TRUE, TRUE],
      'PASS' => [FALSE, FALSE],
    ];
    foreach ($classifierFixtures as $expectedComponent => [$nameFailed, $mailFailed]) {
      $process = proc_open(
        [
          PHP_BINARY,
          '-r',
          $classifierRunner,
          $classifierSource,
          $nameFailed ? '1' : '0',
          $mailFailed ? '1' : '0',
        ],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
      );
      self::assertIsResource($process);
      fclose($pipes[0]);
      $stdout = stream_get_contents($pipes[1]);
      $stderr = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      self::assertSame(0, proc_close($process), (string) $stderr);
      self::assertSame($expectedComponent . "\n", $stdout);
      self::assertSame('', $stderr);
    }
    foreach (['uid', 'username', 'email address', 'SELECT COUNT', 'fetchField'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $classifierSource);
    }

    $markerStart = strpos($publisher, 'drush_user_email_sanitizer_completed() {');
    $markerEnd = strpos(
      $publisher,
      "\n}\n\ndelete_sanitize_diagnostic() {",
      $markerStart,
    );
    self::assertIsInt($markerStart);
    self::assertIsInt($markerEnd);
    $markerFunction = substr($publisher, $markerStart, $markerEnd - $markerStart + 2);
    self::assertStringContainsString(
      "grep -Fxq -- 'User emails sanitized.'",
      $markerFunction,
    );
    foreach ([
      ["User emails sanitized.\n", "YES\n"],
      ["User email sanitized.\n", "NO\n"],
      ["prefix User emails sanitized. suffix\n", "NO\n"],
      ["opaque user@example.test secret=synthetic-only\n", "NO\n"],
    ] as [$rawDiagnostic, $expectedMarker]) {
      $diagnostic = tempnam(sys_get_temp_dir(), 'sanitize-user-marker-');
      self::assertIsString($diagnostic);
      self::assertNotFalse(file_put_contents($diagnostic, $rawDiagnostic));
      chmod($diagnostic, 0600);
      $script = $markerFunction . "\ndrush_user_email_sanitizer_completed \"\$1\"\n";
      $process = proc_open(
        ['bash', '-c', $script, 'marker-test', $diagnostic],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
      );
      self::assertIsResource($process);
      fclose($pipes[0]);
      $stdout = stream_get_contents($pipes[1]);
      $stderr = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $exitCode = proc_close($process);
      unlink($diagnostic);
      self::assertSame(0, $exitCode, (string) $stderr);
      self::assertSame($expectedMarker, $stdout);
      self::assertSame('', $stderr);
      self::assertStringNotContainsString('user@example.test', $stdout);
      self::assertStringNotContainsString('secret=synthetic-only', $stdout);
    }
    self::assertStringContainsString(
      "printf 'DRUSH_USER_EMAIL_SANITIZER_COMPLETED = %s\\n'",
      $publisher,
    );

    $cleanupStart = strpos($publisher, 'delete_sanitize_diagnostic() {');
    $cleanupEnd = strpos(
      $publisher,
      "\n}\n\nclassify_sanitize_failure() {",
      $cleanupStart,
    );
    self::assertIsInt($cleanupStart);
    self::assertIsInt($cleanupEnd);
    $cleanupFunction = substr($publisher, $cleanupStart, $cleanupEnd - $cleanupStart + 2);
    self::assertSame(2, substr_count($publisher, 'delete_sanitize_diagnostic "$sanitize_diagnostic"'));

    $cleanupFile = tempnam(sys_get_temp_dir(), 'sanitize-cleanup-success-');
    self::assertIsString($cleanupFile);
    chmod($cleanupFile, 0600);
    $cleanupScript = $cleanupFunction . "\ndelete_sanitize_diagnostic \"\$1\"\n";
    $process = proc_open(
      ['bash', '-c', $cleanupScript, 'cleanup-success', $cleanupFile],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $root,
    );
    self::assertIsResource($process);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    self::assertSame(0, proc_close($process), (string) $stderr);
    self::assertSame('', $stdout);
    self::assertSame('', $stderr);
    self::assertFileDoesNotExist($cleanupFile);

    $failureScript = "rm() { return 1; }\n" . $cleanupFunction
      . "\ndelete_sanitize_diagnostic \"\$1\"\n";
    $failurePath = sys_get_temp_dir() . '/sanitize-cleanup-synthetic';
    $process = proc_open(
      ['bash', '-c', $failureScript, 'cleanup-failure', $failurePath],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $root,
    );
    self::assertIsResource($process);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    self::assertSame(98, proc_close($process));
    self::assertSame('', $stdout);
    self::assertSame("SANITIZE_DIAGNOSTIC_CLEANUP=FAIL\n", $stderr);

    $componentStart = strpos($publisher, 'classify_sanitize_component() {');
    $componentEnd = strpos(
      $publisher,
      "\n}\n\nclassify_sanitize_metadata() {",
      $componentStart,
    );
    self::assertIsInt($componentStart);
    self::assertIsInt($componentEnd);
    $componentFunction = substr(
      $publisher,
      $componentStart,
      $componentEnd - $componentStart + 2,
    );
    $deprecatedNotice = static fn(string $handler): string =>
      "[notice] The {$handler} sanitize plugin is using a deprecated API.";
    $coreSanitize = 'Drush\\Commands\\sql\\sanitize\\';
    $deprecatedNotices = implode("\n", [
      $deprecatedNotice('Drupal\\webform\\Commands\\WebformSanitizeSubmissionsCommands::messages'),
      $deprecatedNotice($coreSanitize . 'SanitizeCommentsCommands::messages'),
      $deprecatedNotice($coreSanitize . 'SanitizeSessionsCommands::messages'),
      $deprecatedNotice($coreSanitize . 'SanitizeUserTableCommands::messages'),
      $deprecatedNotice($coreSanitize . 'SanitizeUserFieldsCommands::messages'),
    ]);
    $trace = static fn(string $frame): string =>
      "Exception trace:\n  {$frame} at /synthetic/trace.php:42";
    $fixtures = [
      [
        $trace('Drupal\\webform\\Commands\\WebformSanitizeSubmissionsCommands->sanitize()'),
        'UNCLASSIFIED',
        'WEBFORM_SUBMISSIONS',
      ],
      [$trace($coreSanitize . 'SanitizeCommentsCommands->sanitize()'), 'UNCLASSIFIED', 'COMMENTS'],
      [$trace($coreSanitize . 'SanitizeSessionsCommands->sanitize()'), 'UNCLASSIFIED', 'SESSIONS'],
      [$trace($coreSanitize . 'SanitizeUserTableCommands->sanitize()'), 'UNCLASSIFIED', 'USER_TABLE'],
      [$trace($coreSanitize . 'SanitizeUserFieldsCommands->sanitize()'), 'UNCLASSIFIED', 'USER_FIELDS'],
      [$trace('Drupal\\Core\\Database\\Connection->query()'), 'RUNTIME', 'DRUPAL_DATABASE'],
      [
        $deprecatedNotices . "\nException trace:\n  "
        . 'Drupal\\Core\\Database\\Connection->query() at /synthetic/database.php:42'
        . "\n  " . $coreSanitize . 'SanitizeUserFieldsCommands->sanitize() at /synthetic/trace.php:84',
        'UNCLASSIFIED',
        'USER_FIELDS',
      ],
      [
        $deprecatedNotices . "\nRuntimeException: synthetic-only\nException trace:\n  "
        . 'Consolidation\\AnnotatedCommand\\CommandProcessor->process() at /synthetic/trace.php:42',
        'RUNTIME',
        'UNKNOWN',
      ],
      ['opaque user@example.test secret=synthetic-only', 'UNCLASSIFIED', 'UNKNOWN'],
      ['command bootstrap sentinel', 'COMMAND', 'COMMAND_OR_BOOTSTRAP'],
    ];
    foreach ($fixtures as [$rawDiagnostic, $failureClass, $expectedComponent]) {
      $diagnostic = tempnam(sys_get_temp_dir(), 'sanitize-component-');
      self::assertIsString($diagnostic);
      self::assertNotFalse(file_put_contents($diagnostic, $rawDiagnostic));
      chmod($diagnostic, 0600);
      $script = $componentFunction . "\nclassify_sanitize_component \"\$1\" \"\$2\"\n";
      $process = proc_open(
        ['bash', '-c', $script, 'component-test', $diagnostic, $failureClass],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
      );
      self::assertIsResource($process);
      fclose($pipes[0]);
      $stdout = stream_get_contents($pipes[1]);
      $stderr = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $exitCode = proc_close($process);
      unlink($diagnostic);
      self::assertSame(0, $exitCode, (string) $stderr);
      self::assertSame($expectedComponent . "\n", $stdout);
      self::assertSame('', $stderr);
      self::assertStringNotContainsString($rawDiagnostic, $stdout);
    }

    foreach ([
      'SANITIZE_TRACE_PRESENT',
      'SANITIZE_ABNORMAL_TERMINATION',
      'SANITIZE_DRUPAL_ERROR_SIGNAL',
      'SANITIZE_CORE_COMMENTS_COMPLETED',
      'SANITIZE_CORE_SESSIONS_COMPLETED',
      'SANITIZE_CORE_USER_TABLE_COMPLETED',
      'SANITIZE_CORE_USER_FIELDS_ACTIVITY',
    ] as $metadataKey) {
      self::assertStringContainsString("printf '{$metadataKey}=%s\\n'", $publisher);
    }

    $metadataStart = strpos($publisher, 'classify_sanitize_metadata() {');
    $metadataEnd = strpos(
      $publisher,
      "\n}\n\nclassify_sanitize_failure() {",
      $metadataStart,
    );
    self::assertIsInt($metadataStart);
    self::assertIsInt($metadataEnd);
    $metadataFunction = substr(
      $publisher,
      $metadataStart,
      $metadataEnd - $metadataStart + 2,
    );
    $metadataKeys = [
      'SANITIZE_TRACE_PRESENT',
      'SANITIZE_ABNORMAL_TERMINATION',
      'SANITIZE_DRUPAL_ERROR_SIGNAL',
      'SANITIZE_CORE_COMMENTS_COMPLETED',
      'SANITIZE_CORE_SESSIONS_COMPLETED',
      'SANITIZE_CORE_USER_TABLE_COMPLETED',
      'SANITIZE_CORE_USER_FIELDS_ACTIVITY',
    ];
    $metadataFixtures = [
      ["Exception trace:\n  Opaque\\Synthetic->frame()", ['SANITIZE_TRACE_PRESENT']],
      ['Drush command terminated abnormally.', ['SANITIZE_ABNORMAL_TERMINATION']],
      [
        '[error] user@example.test secret=synthetic-only',
        ['SANITIZE_DRUPAL_ERROR_SIGNAL'],
      ],
      [
        'Comment display names and emails removed.',
        ['SANITIZE_CORE_COMMENTS_COMPLETED'],
      ],
      ['Sessions table truncated.', ['SANITIZE_CORE_SESSIONS_COMPLETED']],
      [
        "User passwords sanitized.\nUser emails sanitized.",
        ['SANITIZE_CORE_USER_TABLE_COMPLETED'],
      ],
      [
        '[success] user__private_phone table sanitized.',
        ['SANITIZE_CORE_USER_FIELDS_ACTIVITY'],
      ],
      ['opaque user@example.test secret=synthetic-only', []],
    ];
    foreach ($metadataFixtures as [$rawDiagnostic, $expectedYes]) {
      $diagnostic = tempnam(sys_get_temp_dir(), 'sanitize-metadata-');
      self::assertIsString($diagnostic);
      self::assertNotFalse(file_put_contents($diagnostic, $rawDiagnostic));
      chmod($diagnostic, 0600);
      $script = $metadataFunction . "\nclassify_sanitize_metadata \"\$1\"\n";
      $process = proc_open(
        ['bash', '-c', $script, 'metadata-test', $diagnostic],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
      );
      self::assertIsResource($process);
      fclose($pipes[0]);
      $stdout = stream_get_contents($pipes[1]);
      $stderr = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $exitCode = proc_close($process);
      unlink($diagnostic);
      self::assertSame(0, $exitCode, (string) $stderr);
      self::assertSame('', $stderr);
      $expected = '';
      foreach ($metadataKeys as $metadataKey) {
        $expected .= $metadataKey . '='
          . (in_array($metadataKey, $expectedYes, TRUE) ? 'YES' : 'NO') . "\n";
      }
      self::assertSame($expected, $stdout);
      foreach (['user@example.test', 'secret=synthetic-only', 'user__private_phone'] as $rawFragment) {
        self::assertStringNotContainsString($rawFragment, $stdout);
      }
    }

    $sqlSanitize = strpos($publisher, 'ddev drush -vvv sql:sanitize -y');
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
      'RESET_DEFAULT_BACKUP_PRESERVED',
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

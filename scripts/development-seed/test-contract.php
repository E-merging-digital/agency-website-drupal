<?php

declare(strict_types=1);

/**
 * Synthetic/static #873 contract proof. No PROD/PREPROD network or data access.
 */

function assert_true(bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
}

/**
 * @param list<string> $command
 * @return array{0:int,1:string,2:string}
 */
function run_command(array $command, string $cwd): array {
  $descriptor = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
  ];
  $process = proc_open($command, $descriptor, $pipes, $cwd);
  if (!is_resource($process)) {
    return [127, '', 'Unable to start process'];
  }
  fclose($pipes[0]);
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $code = proc_close($process);
  return [$code, is_string($stdout) ? $stdout : '', is_string($stderr) ? $stderr : ''];
}

$root = dirname(__DIR__, 2);
$provider = file_get_contents($root . '/.ddev/providers/agency.yaml');
$ddevConfig = file_get_contents($root . '/.ddev/config.development-seed.yaml');
$policyRaw = file_get_contents(__DIR__ . '/sanitization-policy.json');
$devSanitizer = file_get_contents(__DIR__ . '/agency-development-sanitize.php');
$localConverge = file_get_contents(__DIR__ . '/local-converge.php');
$docs = file_get_contents($root . '/docs/operations/development-seed.md');
foreach ([$provider, $ddevConfig, $policyRaw, $devSanitizer, $localConverge, $docs] as $content) {
  assert_true(is_string($content), 'Required #873 contract file is unreadable.');
}

assert_true(str_contains($provider, 'db_pull_command:'), 'DDEV native db_pull_command is missing.');
assert_true(str_contains($provider, 'verify-seed.php'), 'Seed verification is not wired before import.');
assert_true(str_contains($provider, 'scp -q -o BatchMode=yes'), 'Read-only authenticated transport contract is missing.');
assert_true(!preg_match('/^\s*(?:db|files)_push_command\s*:/m', $provider), 'DDEV push stanza is forbidden.');
assert_true(!str_contains($provider, 'PREPROD_SSH_PRIVATE_KEY'), 'PREPROD runtime credential must not be used.');
assert_true(!str_contains($provider, 'SERVER_HOST'), 'PROD credential surface must not be used.');
assert_true(str_contains($ddevConfig, 'ddev snapshot --name='), 'DDEV snapshot rollback is missing.');
assert_true(str_contains($ddevConfig, 'post-pull:'), 'DDEV post-pull convergence hook is missing.');

$policy = json_decode($policyRaw, true, flags: JSON_THROW_ON_ERROR);
assert_true($policy['policy_id'] === 'agency-development-seed-v1', 'Wrong development policy id.');
assert_true($policy['extends_existing']['preprod_policy_id'] === 'agency-preprod-refresh-v1', 'Existing PREPROD policy is not reused.');
assert_true($policy['extends_existing']['drush_sql_sanitize'] === 'REQUIRED', 'Drush generic sanitization is not required.');
assert_true($policy['scope']['public_files'] === 'NONE' && $policy['scope']['private_files'] === 'NEVER', 'Seed v1 must be database-only.');
assert_true(str_contains($devSanitizer, "['user__roles', 'user__user_picture', 'users_data', 'history']"), 'Development-specific user minimization is missing.');
assert_true(str_contains($devSanitizer, "condition('collection', 'state')"), 'Runtime state minimization is missing.');
assert_true(str_contains($localConverge, "config_split.config_split.preproduction"), 'Local PREPROD split assertion is missing.');
assert_true(str_contains($localConverge, "mailer_dsn"), 'Local mail safety assertion is missing.');
assert_true(str_contains($localConverge, "agency_external_ai_egress_enabled"), 'Local provider egress assertion is missing.');
assert_true(str_contains($docs, 'ddev pull agency'), 'Canonical developer UX is undocumented.');
assert_true(str_contains($docs, 'ddev snapshot restore'), 'Standard DDEV recovery route is undocumented.');

$tmp = sys_get_temp_dir() . '/agency-seed-873-' . bin2hex(random_bytes(6));
assert_true(mkdir($tmp, 0700, true), 'Unable to create synthetic proof directory.');
try {
  [$code] = run_command(['git', 'init', '-q'], $tmp);
  assert_true($code === 0, 'Unable to initialize synthetic Git repository.');
  foreach ([['user.email', 'seed-test@example.invalid'], ['user.name', 'Seed Test']] as [$key, $value]) {
    [$code] = run_command(['git', 'config', $key, $value], $tmp);
    assert_true($code === 0, 'Unable to configure synthetic Git repository.');
  }
  file_put_contents($tmp . '/schema.txt', "v1\n");
  run_command(['git', 'add', 'schema.txt'], $tmp);
  [$code] = run_command(['git', 'commit', '-q', '-m', 'seed release'], $tmp);
  assert_true($code === 0, 'Unable to commit synthetic seed release.');
  [$code, $seedSha] = run_command(['git', 'rev-parse', 'HEAD'], $tmp);
  $seedSha = trim($seedSha);
  assert_true($code === 0 && preg_match('/^[0-9a-f]{40}$/', $seedSha) === 1, 'Synthetic seed SHA invalid.');

  file_put_contents($tmp . '/schema.txt', "v2\n");
  run_command(['git', 'add', 'schema.txt'], $tmp);
  [$code] = run_command(['git', 'commit', '-q', '-m', 'newer checkout'], $tmp);
  assert_true($code === 0, 'Unable to commit synthetic newer checkout.');
  [$code, $checkoutSha] = run_command(['git', 'rev-parse', 'HEAD'], $tmp);
  $checkoutSha = trim($checkoutSha);
  assert_true($code === 0 && preg_match('/^[0-9a-f]{40}$/', $checkoutSha) === 1, 'Synthetic checkout SHA invalid.');

  $database = $tmp . '/database.sql.gz';
  file_put_contents($database, gzencode("-- synthetic #873 only\nCREATE TABLE example (id INT);\n", 9));
  $metadata = $tmp . '/seed.json';
  $metadata2 = $tmp . '/seed-2.json';
  $builder = __DIR__ . '/build-seed-metadata.php';
  $verifier = __DIR__ . '/verify-seed.php';
  $buildArgs = [
    PHP_BINARY, $builder,
    '--database=' . $database,
    '--seed-id=agency-development-seed-v1-synthetic-873',
    '--created-at=2026-09-01T00:00:00Z',
    '--source-refresh=synthetic-873',
    '--source-release=' . $seedSha,
  ];
  [$code] = run_command([...$buildArgs, '--output=' . $metadata], $root);
  assert_true($code === 0, 'Synthetic seed metadata generation failed.');
  [$code] = run_command([...$buildArgs, '--output=' . $metadata2], $root);
  assert_true($code === 0, 'Synthetic seed metadata reproducibility generation failed.');
  assert_true(hash_file('sha256', $metadata) === hash_file('sha256', $metadata2), 'Seed metadata is not reproducible for identical inputs.');

  [$code, $stdout] = run_command([
    PHP_BINARY, $verifier,
    '--metadata=' . $metadata,
    '--database=' . $database,
    '--repository=' . $tmp,
    '--checkout-ref=' . $checkoutSha,
  ], $root);
  assert_true($code === 0 && str_contains($stdout, 'SEED_HASH=PASS') && str_contains($stdout, 'SEED_COMPATIBILITY=SEED_OLDER_THAN_CHECKOUT'), 'Supported seed compatibility proof failed.');

  $corrupt = $tmp . '/database-corrupt.sql.gz';
  copy($database, $corrupt);
  file_put_contents($corrupt, 'corruption', FILE_APPEND);
  [$code] = run_command([
    PHP_BINARY, $verifier,
    '--metadata=' . $metadata,
    '--database=' . $corrupt,
    '--repository=' . $tmp,
    '--checkout-ref=' . $checkoutSha,
  ], $root);
  assert_true($code !== 0, 'Corrupted database hash was accepted.');

  $newerMetadata = $tmp . '/newer-seed.json';
  [$code] = run_command([
    PHP_BINARY, $builder,
    '--database=' . $database,
    '--seed-id=agency-development-seed-v1-synthetic-newer',
    '--created-at=2026-09-01T00:00:01Z',
    '--source-refresh=synthetic-873-newer',
    '--source-release=' . $checkoutSha,
    '--output=' . $newerMetadata,
  ], $root);
  assert_true($code === 0, 'Unable to build newer synthetic seed metadata.');
  [$code, , $stderr] = run_command([
    PHP_BINARY, $verifier,
    '--metadata=' . $newerMetadata,
    '--database=' . $database,
    '--repository=' . $tmp,
    '--checkout-ref=' . $seedSha,
  ], $root);
  assert_true($code !== 0 && str_contains($stderr, 'UNSUPPORTED_DOWNGRADE_OR_DIVERGENCE'), 'Unsupported downgrade did not fail closed.');

  [$code, $fixtureOut] = run_command([
    'python3',
    $root . '/scripts/preproduction-refresh/sanitize-staging-fixture.py',
    'PROVE',
  ], $root);
  assert_true($code === 0 && str_contains($fixtureOut, 'SYNTHETIC_FIXTURE_PROOF=PASS'), 'Existing #816 synthetic sanitization regression failed.');
} finally {
  $iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
  );
  foreach ($iterator as $item) {
    $path = $item->getPathname();
    $item->isDir() ? rmdir($path) : unlink($path);
  }
  @rmdir($tmp);
}

fwrite(STDOUT, "EXISTING_CAPABILITY_AUDIT=COMPLETE\n");
fwrite(STDOUT, "SYNTHETIC_SEED_PROOF=PASS\n");
fwrite(STDOUT, "CORRUPT_HASH=FAIL_CLOSED\n");
fwrite(STDOUT, "UNSUPPORTED_DOWNGRADE=FAIL_CLOSED\n");
fwrite(STDOUT, "SIDE_EFFECT_ASSERTIONS=PASS\n");
fwrite(STDOUT, "PULL_ONLY_CONTRACT=PASS\n");
fwrite(STDOUT, "PUBLIC_FILES=NONE\n");
fwrite(STDOUT, "PRIVATE_FILES=NONE\n");
fwrite(STDOUT, "REAL_PROD_ACCESS=NONE\n");
fwrite(STDOUT, "REAL_PREPROD_DATA_READ=NONE\n");
fwrite(STDOUT, "REAL_SEED_GENERATION=NONE\n");
fwrite(STDOUT, "REAL_SEED_DISTRIBUTION=NONE\n");

<?php

declare(strict_types=1);

/**
 * Static/synthetic #873/#956 contract proof. No PROD/PREPROD network or data.
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
$dispatcher = file_get_contents($root . '/.github/workflows/agency-command-dispatch.yml');
$workflow = file_get_contents($root . '/.github/workflows/development-seed-publish.yml');
$publisher = file_get_contents(__DIR__ . '/run-publish.sh');
$source = file_get_contents(__DIR__ . '/remote-readonly-preprod-source.sh');
$storage = file_get_contents(__DIR__ . '/remote-storage.sh');
$reader = file_get_contents(__DIR__ . '/remote-read-only-scp.sh');
$readerKey = file_get_contents(__DIR__ . '/remote-reader-key.sh');
foreach ([$provider, $ddevConfig, $policyRaw, $devSanitizer, $localConverge, $docs, $dispatcher, $workflow, $publisher, $source, $storage, $reader, $readerKey] as $content) {
  assert_true(is_string($content), 'Required Development Seed contract file is unreadable.');
}

// Existing #873 DDEV-native consumer remains authoritative.
assert_true(str_contains($provider, 'db_pull_command:'), 'DDEV native db_pull_command is missing.');
assert_true(str_contains($provider, 'verify-seed.php'), 'Seed verification is not wired before import.');
assert_true(str_contains($provider, 'scp -O -q'), 'Forced-command-compatible SCP read path is missing.');
assert_true(str_contains($provider, 'StrictHostKeyChecking=yes'), 'Pinned fail-closed host verification is missing.');
assert_true(str_contains($provider, '/var/www/agency-preprod/shared/development-seeds/current'), 'Fixed seed storage path is missing.');
assert_true(!str_contains($provider, 'AGENCY_SEED_REMOTE_DIR'), 'Caller-controlled seed directory is forbidden.');
assert_true(!preg_match('/^\s*(?:db|files)_push_command\s*:/m', $provider), 'DDEV push stanza is forbidden.');
assert_true(!str_contains($provider, 'PREPROD_SSH_PRIVATE_KEY'), 'PREPROD deploy credential must not be used by DDEV.');
assert_true(!str_contains($provider, 'SERVER_HOST'), 'PROD credential surface must not be used by DDEV.');
assert_true(str_contains($ddevConfig, 'ddev snapshot --name='), 'DDEV snapshot rollback is missing.');
assert_true(str_contains($ddevConfig, 'post-pull:'), 'DDEV post-pull convergence hook is missing.');

$policy = json_decode($policyRaw, true, flags: JSON_THROW_ON_ERROR);
assert_true($policy['policy_id'] === 'agency-development-seed-v1', 'Wrong development policy id.');
assert_true($policy['extends_existing']['preprod_policy_id'] === 'agency-preprod-refresh-v1', 'Existing PREPROD policy is not reused.');
assert_true($policy['extends_existing']['drush_sql_sanitize'] === 'REQUIRED', 'Drush generic sanitization is not required.');
assert_true($policy['scope']['public_files'] === 'NONE' && $policy['scope']['private_files'] === 'NEVER', 'Seed v1 must be database-only.');
assert_true(str_contains($devSanitizer, "['user__roles', 'user__user_picture', 'users_data', 'history']"), 'Development-specific user minimization is missing.');
assert_true(str_contains($devSanitizer, "condition('collection', 'state')"), 'Runtime state minimization is missing.');
assert_true(str_contains($localConverge, 'config_split.config_split.preproduction'), 'Local PREPROD split assertion is missing.');
assert_true(str_contains($localConverge, 'mailer_dsn'), 'Local mail safety assertion is missing.');
assert_true(str_contains($localConverge, 'agency_external_ai_egress_enabled'), 'Local provider egress assertion is missing.');

// #956 extends the single dispatcher and no other global listener.
assert_true(substr_count($dispatcher, "  issue_comment:\n") === 1, 'Dispatcher must remain the single issue_comment listener.');
assert_true(str_contains($dispatcher, '"route":"DEVELOPMENT_SEED"'), 'Development Seed route is missing.');
assert_true(str_contains($dispatcher, "'DEVELOPMENT_SEED': '956'"), 'Development Seed route is not bounded to #956.');
assert_true(str_contains($dispatcher, 'uses: ./.github/workflows/development-seed-publish.yml'), 'Development Seed reusable workflow is not routed.');
assert_true(str_contains($workflow, 'runs-on: [self-hosted, linux, x64, agency, ddev]'), 'Real seed bytes are not confined to the trusted DDEV runner.');
assert_true(!str_contains($workflow, 'actions/upload-artifact'), 'Development Seed workflow may not upload a database artifact.');
assert_true(str_contains($workflow, 'JIT revalidate authority before PREPROD secret materialization'), 'JIT-before-secret boundary is missing.');

// Source is fixed read-only PREPROD and runtime identity is resolved JIT.
assert_true(str_contains($source, "PROJECT_ROOT='/var/www/agency-preprod'"), 'Fixed PREPROD source root is missing.');
assert_true(str_contains($source, 'SHARED="$PROJECT_ROOT/shared"'), 'PREPROD shared root must derive from fixed PROJECT_ROOT.');
assert_true(str_contains($source, 'ARTIFACTS="$SHARED/artifacts"'), 'PREPROD application artifacts must derive from the fixed shared root.');
assert_true(str_contains($source, 'SANITIZED_DATABASE_ACTIVE_AND_VALIDATED'), 'Current committed refresh proof is not required.');
assert_true(str_contains($source, 'sql:dump'), 'Fixed read-only PREPROD dump is missing.');
foreach (['sql:drop', 'sql:query', 'updb', 'cim', 'maint:set', 'state:set', 'user:create'] as $forbidden) {
  assert_true(!str_contains($source, $forbidden), "PREPROD source contains forbidden mutation primitive: {$forbidden}");
}
assert_true(!str_contains($source, '/var/www/agency/'), 'Development Seed source must not contain a PROD runtime path.');

// Publisher reuses DDEV/Drush/#914/development sanitizer and proves cleanup.
foreach ([
  'ddev import-db --file="$raw"',
  'ddev drush sql:sanitize -y',
  'scripts/preproduction-refresh/governed-successor/agency-sanitize.php',
  'scripts/development-seed/agency-development-sanitize.php',
  'build-seed-metadata.php',
  'verify-seed.php',
  'ddev pull agency -y',
  'temporary_generation_material=ABSENT',
] as $required) {
  assert_true(str_contains($publisher, $required), "Publisher reuse/cleanup contract missing: {$required}");
}
assert_true(str_contains($publisher, "--sanitize-email='user+%uid@example.invalid'"), 'Drush email sanitization must satisfy the existing #914 assertion.');
assert_true(str_contains($publisher, '--sanitize-password="$seed_password"'), 'Drush passwords must be invalidated with non-persisted random material.');
assert_true(str_contains($publisher, 'RUNNER_ENVIRONMENT" == self-hosted'), 'Publisher must fail closed off the trusted self-hosted runner.');
assert_true(!str_contains($publisher, 'SOURCE_PROD'), 'Publisher must not expose a PROD source path.');
assert_true(!str_contains($publisher, 'SSH_PRIVATE_KEY'), 'Publisher must not consume the PROD SSH secret.');

// Fixed storage is immutable-by-contract and current switches only after proof.
assert_true(str_contains($storage, "ROOT='/var/www/agency-preprod/shared/development-seeds'"), 'Fixed storage root is missing.');
assert_true(str_contains($storage, 'immutable seed identity already exists'), 'Immutable seed replay guard is missing.');
assert_true(strpos($storage, 'database digest mismatch before publication') < strpos($storage, 'mv -Tf -- "$current_tmp" "$CURRENT"'), 'Current pointer may only switch after hash verification.');
assert_true(str_contains($storage, 'temporary_storage_material=ABSENT'), 'Storage cleanup absence proof is missing.');

// Reader key is a distinct forced identity with no write/general shell path.
assert_true(str_contains($readerKey, 'restrict,command='), 'Restricted reader authorized_keys contract is missing.');
assert_true(str_contains($readerKey, 'reader_seed_write=NONE'), 'Reader write prohibition is missing.');
assert_true(str_contains($readerKey, 'reader_general_shell=NONE'), 'Reader shell prohibition is missing.');
assert_true(str_contains($reader, 'exec /usr/bin/scp -f'), 'Reader does not use fixed server-side SCP read mode.');
assert_true(!str_contains($reader, 'scp -t'), 'Reader upload mode is forbidden.');
assert_true(str_contains($reader, '$CURRENT/seed.json') && str_contains($reader, '$CURRENT/database.sql.gz'), 'Reader scope is not limited to the two current seed files.');

assert_true(str_contains($docs, 'ddev pull agency'), 'Canonical developer UX is undocumented.');
assert_true(str_contains($docs, 'ddev snapshot restore'), 'Standard DDEV recovery route is undocumented.');
foreach ([
  '#816 is not yet terminally proven',
  'sanitized PREPROD (future source, only after terminal #816 proof)',
  'future real publisher must be separately authorized after #816',
] as $stale) {
  assert_true(!str_contains($docs, $stale), "Stale #816 claim remains: {$stale}");
}

$tmp = sys_get_temp_dir() . '/agency-seed-956-' . bin2hex(random_bytes(6));
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
  file_put_contents($database, gzencode("-- synthetic #956 only\nCREATE TABLE example (id INT);\n", 9));
  $metadata = $tmp . '/seed.json';
  $metadata2 = $tmp . '/seed-2.json';
  $builder = __DIR__ . '/build-seed-metadata.php';
  $verifier = __DIR__ . '/verify-seed.php';
  $buildArgs = [
    PHP_BINARY, $builder,
    '--database=' . $database,
    '--seed-id=agency-development-seed-v1-synthetic-956',
    '--created-at=2026-09-02T00:00:00Z',
    '--source-refresh=apply-953-synthetic-r1',
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
    '--created-at=2026-09-02T00:00:01Z',
    '--source-refresh=apply-953-synthetic-newer-r1',
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

  // Route-specific one-shot authority is proven without a generic framework.
  $authorityIssue = $tmp . '/issue.json';
  $authorityComments = $tmp . '/comments.json';
  $request = 'seed-956-synthetic-proof-r1';
  $refresh = 'apply-953-synthetic-r1';
  $comment = "/agency-development-seed publish {$request} {$checkoutSha} {$refresh} {$seedSha}";
  file_put_contents($authorityIssue, json_encode([
    'number' => 956,
    'state' => 'open',
    'user' => ['login' => 'E-merging-digital'],
    'labels' => [['name' => 'status:in-progress']],
    'body' => "Parent: #873\n",
  ], JSON_THROW_ON_ERROR));
  file_put_contents($authorityComments, json_encode([['body' => $comment]], JSON_THROW_ON_ERROR));
  $authorityArgs = [
    'python3', __DIR__ . '/validate-publish-authority.py',
    '--issue-json', $authorityIssue,
    '--comments-json', $authorityComments,
    '--authority-issue-number', '956',
    '--comment-body', $comment,
    '--comment-author', 'E-merging-digital',
    '--github-actor', 'E-merging-digital',
    '--event-name', 'issue_comment',
    '--event-action', 'created',
    '--run-attempt', '1',
    '--live-main', $checkoutSha,
  ];
  [$code, $authorityOut] = run_command($authorityArgs, $root);
  assert_true($code === 0 && str_contains($authorityOut, 'AUTHORITY=PASS') && str_contains($authorityOut, 'ONE_SHOT=PASS'), 'Bounded #956 authority proof failed.');
  file_put_contents($authorityComments, json_encode([['body' => $comment], ['body' => $comment]], JSON_THROW_ON_ERROR));
  [$code] = run_command($authorityArgs, $root);
  assert_true($code !== 0, 'Duplicate/reused seed request was accepted.');
  file_put_contents($authorityComments, json_encode([['body' => $comment]], JSON_THROW_ON_ERROR));
  $rerunArgs = $authorityArgs;
  $attemptIndex = array_search('--run-attempt', $rerunArgs, true);
  assert_true(is_int($attemptIndex), 'Synthetic authority args are invalid.');
  $rerunArgs[$attemptIndex + 1] = '2';
  [$code] = run_command($rerunArgs, $root);
  assert_true($code !== 0, 'Seed publication rerun was accepted.');

  [$code, $fixtureOut] = run_command([
    'python3',
    $root . '/scripts/preproduction-refresh/sanitize-staging-fixture.py',
    'PROVE',
  ], $root);
  assert_true($code === 0 && str_contains($fixtureOut, 'SYNTHETIC_FIXTURE_PROOF=PASS'), 'Existing #816 synthetic sanitization regression failed.');
}
finally {
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
fwrite(STDOUT, "PUBLISHER_STATIC_PROOF=PASS\n");
fwrite(STDOUT, "SOURCE_IDENTITY_JIT=FAIL_CLOSED\n");
fwrite(STDOUT, "READER_IDENTITY=RESTRICTED_READ_ONLY\n");
fwrite(STDOUT, "STORAGE_CONTRACT=FIXED_IMMUTABLE_CURRENT_AFTER_VERIFY\n");
fwrite(STDOUT, "CORRUPT_HASH=FAIL_CLOSED\n");
fwrite(STDOUT, "UNSUPPORTED_DOWNGRADE=FAIL_CLOSED\n");
fwrite(STDOUT, "REQUEST_REUSE=FAIL_CLOSED\n");
fwrite(STDOUT, "RERUN=FAIL_CLOSED\n");
fwrite(STDOUT, "SIDE_EFFECT_ASSERTIONS=PASS\n");
fwrite(STDOUT, "PULL_ONLY_CONTRACT=PASS\n");
fwrite(STDOUT, "PUBLIC_FILES=NONE\n");
fwrite(STDOUT, "PRIVATE_FILES=NONE\n");
fwrite(STDOUT, "REAL_PROD_ACCESS=NONE\n");
fwrite(STDOUT, "REAL_PREPROD_DATA_READ=NONE\n");
fwrite(STDOUT, "REAL_SEED_GENERATION=NONE\n");
fwrite(STDOUT, "REAL_SEED_DISTRIBUTION=NONE\n");

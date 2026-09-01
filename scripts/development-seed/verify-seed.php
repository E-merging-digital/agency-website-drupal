<?php

declare(strict_types=1);

/**
 * Fail-closed Development Seed metadata/hash/code compatibility guard.
 */

function fail(string $message): never {
  fwrite(STDERR, $message . PHP_EOL);
  exit(2);
}

/**
 * @param list<string> $command
 * @return array{0:int,1:string,2:string}
 */
function run_process(array $command, string $cwd): array {
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

/**
 * @param mixed $value
 */
function reject_sensitive_metadata_keys(mixed $value, string $path = ''): void {
  if (!is_array($value)) {
    return;
  }
  foreach ($value as $key => $nested) {
    if (is_string($key) && preg_match('/(?:password|secret|token|credential|private[_-]?key|api[_-]?key|webhook)/i', $key)) {
      fail("Sensitive metadata key is forbidden at {$path}{$key}");
    }
    reject_sensitive_metadata_keys($nested, $path . (is_string($key) ? $key . '.' : ''));
  }
}

$options = getopt('', ['metadata:', 'database:', 'repository:', 'checkout-ref::']);
foreach (['metadata', 'database', 'repository'] as $required) {
  if (!isset($options[$required]) || !is_string($options[$required]) || $options[$required] === '') {
    fail("Missing --{$required}");
  }
}
$metadataPath = $options['metadata'];
$databasePath = $options['database'];
$repository = $options['repository'];
$checkoutRef = isset($options['checkout-ref']) && is_string($options['checkout-ref']) && $options['checkout-ref'] !== ''
  ? $options['checkout-ref']
  : 'HEAD';

if ($checkoutRef !== 'HEAD' && !preg_match('/^[0-9a-f]{40}$/', strtolower($checkoutRef))) {
  fail('checkout-ref must be HEAD or a 40-hex commit SHA.');
}
if (!is_dir($repository . '/.git')) {
  fail('Repository is not a Git checkout.');
}
if (!is_readable($metadataPath) || !is_readable($databasePath)) {
  fail('Seed metadata or database is not readable.');
}

$raw = file_get_contents($metadataPath);
$metadata = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($metadata)) {
  fail('Seed metadata is invalid JSON.');
}
reject_sensitive_metadata_keys($metadata);

$required = [
  'schema_version', 'seed_id', 'created_at', 'source_preprod_refresh_identity',
  'source_preprod_application_release_sha', 'sanitization_policy',
  'database_byte_size', 'database_sha256', 'compatibility',
];
foreach ($required as $key) {
  if (!array_key_exists($key, $metadata)) {
    fail("Missing seed metadata key: {$key}");
  }
}
if ($metadata['schema_version'] !== 1) {
  fail('Unsupported seed metadata schema_version.');
}
if (!is_string($metadata['seed_id']) || !preg_match('/^agency-development-seed-v1-[A-Za-z0-9._-]+$/', $metadata['seed_id'])) {
  fail('Invalid seed_id.');
}
if (!is_string($metadata['created_at']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $metadata['created_at'])) {
  fail('Invalid created_at.');
}
if (!is_string($metadata['source_preprod_refresh_identity']) || !preg_match('/^[A-Za-z0-9._:-]+$/', $metadata['source_preprod_refresh_identity'])) {
  fail('Invalid source PREPROD refresh identity.');
}
$sourceRelease = is_string($metadata['source_preprod_application_release_sha'])
  ? strtolower($metadata['source_preprod_application_release_sha'])
  : '';
if (!preg_match('/^[0-9a-f]{40}$/', $sourceRelease)) {
  fail('Invalid source application release SHA.');
}
$policy = $metadata['sanitization_policy'];
if (!is_array($policy) || ($policy['id'] ?? null) !== 'agency-development-seed-v1' || (string) ($policy['version'] ?? '') !== '1') {
  fail('Unsupported development sanitization policy identity.');
}
$compatibility = $metadata['compatibility'];
if (!is_array($compatibility)
  || ($compatibility['strategy'] ?? null) !== 'SAME_OR_SEED_ANCESTOR'
  || ($compatibility['ddev_minimum_version'] ?? null) !== '1.25.3'
  || ($compatibility['database'] ?? null) !== 'mariadb:11.8'
  || ($compatibility['drush'] ?? null) !== '13.7.6') {
  fail('Unsupported seed compatibility metadata.');
}

$size = filesize($databasePath);
$hash = hash_file('sha256', $databasePath);
if (!is_int($size) || $size !== $metadata['database_byte_size']) {
  fail('Database byte size mismatch.');
}
if (!is_string($hash) || !is_string($metadata['database_sha256']) || !hash_equals(strtolower($metadata['database_sha256']), $hash)) {
  fail('Database SHA-256 mismatch.');
}

[$code, $stdout] = run_process(['git', 'rev-parse', '--verify', $checkoutRef . '^{commit}'], $repository);
$checkoutSha = trim($stdout);
if ($code !== 0 || !preg_match('/^[0-9a-f]{40}$/', $checkoutSha)) {
  fail('Unable to resolve checkout commit.');
}

$compatibilityVerdict = 'SAME_RELEASE';
if ($sourceRelease !== $checkoutSha) {
  [$seedExists] = run_process(['git', 'cat-file', '-e', $sourceRelease . '^{commit}'], $repository);
  if ($seedExists !== 0) {
    fail('Seed source release is not available in local Git history; fetch history before retrying.');
  }
  [$ancestor] = run_process(['git', 'merge-base', '--is-ancestor', $sourceRelease, $checkoutSha], $repository);
  if ($ancestor !== 0) {
    fail('UNSUPPORTED_DOWNGRADE_OR_DIVERGENCE');
  }
  $compatibilityVerdict = 'SEED_OLDER_THAN_CHECKOUT';
}

fwrite(STDOUT, "SEED_METADATA=PASS\n");
fwrite(STDOUT, "SEED_HASH=PASS\n");
fwrite(STDOUT, "SEED_COMPATIBILITY={$compatibilityVerdict}\n");
fwrite(STDOUT, "SEED_ID={$metadata['seed_id']}\n");

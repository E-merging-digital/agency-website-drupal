<?php

declare(strict_types=1);

/**
 * Build non-sensitive immutable Development Seed metadata for an already
 * sanitized database artifact. This script does not read PROD/PREPROD itself.
 */

function fail(string $message): never {
  fwrite(STDERR, $message . PHP_EOL);
  exit(2);
}

$options = getopt('', [
  'database:',
  'seed-id:',
  'created-at:',
  'source-refresh:',
  'source-release:',
  'output:',
]);

foreach (['database', 'seed-id', 'created-at', 'source-refresh', 'source-release', 'output'] as $required) {
  if (!isset($options[$required]) || !is_string($options[$required]) || $options[$required] === '') {
    fail("Missing --{$required}");
  }
}

$database = $options['database'];
$seedId = $options['seed-id'];
$createdAt = $options['created-at'];
$sourceRefresh = $options['source-refresh'];
$sourceRelease = strtolower($options['source-release']);
$output = $options['output'];

if (!is_file($database) || !is_readable($database)) {
  fail('Database artifact is not readable.');
}
if (!preg_match('/^agency-development-seed-v1-[A-Za-z0-9._-]+$/', $seedId)) {
  fail('Invalid seed_id.');
}
if (!preg_match('/^[0-9a-f]{40}$/', $sourceRelease)) {
  fail('source_preprod_application_release_sha must be a 40-hex Git SHA.');
}
if (!preg_match('/^[A-Za-z0-9._:-]+$/', $sourceRefresh)) {
  fail('Invalid source_preprod_refresh_identity.');
}
$date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $createdAt, new DateTimeZone('UTC'));
if (!$date || $date->format('Y-m-d\TH:i:s\Z') !== $createdAt) {
  fail('created_at must be UTC RFC3339 seconds (YYYY-MM-DDTHH:MM:SSZ).');
}

$policyPath = __DIR__ . '/sanitization-policy.json';
$policyRaw = file_get_contents($policyPath);
$policy = is_string($policyRaw) ? json_decode($policyRaw, true) : null;
if (!is_array($policy) || ($policy['policy_id'] ?? null) !== 'agency-development-seed-v1') {
  fail('Development sanitization policy is invalid.');
}

$size = filesize($database);
$hash = hash_file('sha256', $database);
if (!is_int($size) || !is_string($hash) || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
  fail('Unable to calculate database identity.');
}

$metadata = [
  'schema_version' => 1,
  'seed_id' => $seedId,
  'created_at' => $createdAt,
  'source_preprod_refresh_identity' => $sourceRefresh,
  'source_preprod_application_release_sha' => $sourceRelease,
  'sanitization_policy' => [
    'id' => $policy['policy_id'],
    'version' => (string) $policy['policy_version'],
  ],
  'database_byte_size' => $size,
  'database_sha256' => $hash,
  'compatibility' => [
    'strategy' => 'SAME_OR_SEED_ANCESTOR',
    'ddev_minimum_version' => '1.25.3',
    'database' => 'mariadb:11.8',
    'drush' => '13.7.6',
  ],
];

$json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
  fail('Unable to encode metadata.');
}
if (file_put_contents($output, $json . PHP_EOL, LOCK_EX) === false) {
  fail('Unable to write metadata.');
}
@chmod($output, 0600);

fwrite(STDOUT, "SEED_METADATA_CREATED=PASS\n");
fwrite(STDOUT, "SEED_ID={$seedId}\n");
fwrite(STDOUT, "DATABASE_SHA256={$hash}\n");

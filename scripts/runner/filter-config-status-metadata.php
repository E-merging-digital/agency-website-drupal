<?php

declare(strict_types=1);

/**
 * Reduces Drush config:status JSON to metadata-only drift evidence.
 *
 * Usage:
 *   drush config:status --format=json | php filter-config-status-metadata.php PREPROD
 */

$environment = $argv[1] ?? '';
if (!in_array($environment, ['PREPROD', 'PROD'], TRUE)) {
  fwrite(STDERR, "Environment must be PREPROD or PROD.\n");
  exit(2);
}

$raw = trim((string) stream_get_contents(STDIN));
if ($raw === '') {
  $decoded = [];
}
else {
  $decoded = json_decode($raw, TRUE);
  if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
    fwrite(STDERR, "Drush config:status output must be valid JSON rows.\n");
    exit(2);
  }
}

$rows = [];
foreach ($decoded as $key => $value) {
  if (is_array($value) && isset($value['name'], $value['state'])) {
    $rows[] = [
      'name' => $value['name'],
      'state' => $value['state'],
    ];
    continue;
  }

  // Consolidation output formatters may preserve the config name as the row key.
  if (is_string($key) && is_array($value) && isset($value['state'])) {
    $rows[] = [
      'name' => $key,
      'state' => $value['state'],
    ];
    continue;
  }

  // Accept the compact keyed representation only when the value is a state.
  if (is_string($key) && is_string($value)) {
    $rows[] = [
      'name' => $key,
      'state' => $value,
    ];
    continue;
  }

  fwrite(STDERR, "Unexpected Drush config:status row shape.\n");
  exit(2);
}

$stateMap = [
  'Only in sync dir' => [
    'operation' => 'CREATE',
    'classification' => 'EXPECTED_REPOSITORY_DEPLOY_DRIFT',
  ],
  'Only in DB' => [
    'operation' => 'DELETE',
    'classification' => 'UNEXPECTED_REVIEW_REQUIRED',
  ],
  'Different' => [
    'operation' => 'UPDATE',
    'classification' => 'UNEXPECTED_REVIEW_REQUIRED',
  ],
];

$items = [];
foreach ($rows as $row) {
  $name = $row['name'];
  $state = $row['state'];
  if (!is_string($name) || $name === '' || preg_match('/^[A-Za-z0-9_.:-]+$/D', $name) !== 1) {
    fwrite(STDERR, "Unexpected config name in Drush status metadata.\n");
    exit(2);
  }
  if (!is_string($state)) {
    fwrite(STDERR, "Unexpected config state in Drush status metadata.\n");
    exit(2);
  }

  // config:status defaults to non-identical rows. Ignore Identical defensively.
  if ($state === 'Identical') {
    continue;
  }
  if (!isset($stateMap[$state])) {
    fwrite(STDERR, "Unsupported Drush config state: {$state}\n");
    exit(2);
  }

  $items[] = [
    'environment' => $environment,
    'config_name' => $name,
    'state' => $state,
    'operation' => $stateMap[$state]['operation'],
    'classification' => $stateMap[$state]['classification'],
  ];
}

usort($items, static fn(array $left, array $right): int => $left['config_name'] <=> $right['config_name']);

$expected = 0;
$intentional = 0;
$review = 0;
foreach ($items as $item) {
  match ($item['classification']) {
    'EXPECTED_REPOSITORY_DEPLOY_DRIFT' => $expected++,
    'INTENTIONAL_RUNTIME_ONLY' => $intentional++,
    'UNEXPECTED_REVIEW_REQUIRED' => $review++,
    default => throw new RuntimeException('Unexpected classification.'),
  };
}

$result = [
  'schema_version' => 1,
  'environment' => $environment,
  'metadata_schema' => 'environment + config_name + operation/state',
  'config_values_exposed' => FALSE,
  'items' => $items,
  'summary' => [
    'total' => count($items),
    'expected_repository_deploy_drift' => $expected,
    'intentional_runtime_only' => $intentional,
    'unexpected_review_required' => $review,
    'persistent_language_lock_cim_safety' => $review === 0 ? 'YES' : 'REVIEW_REQUIRED',
  ],
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
fwrite(STDOUT, $json . "\n");

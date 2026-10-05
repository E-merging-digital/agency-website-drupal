<?php

declare(strict_types=1);

/**
 * @file
 * Compares the eight bounded Canvas component configs in active and sync storage.
 */

use Drupal\Core\Config\StorageInterface;

$names = [
  'canvas.component.block.help_block',
  'canvas.component.block.language_block.language_content',
  'canvas.component.block.local_actions_block',
  'canvas.component.block.page_title_block',
  'canvas.component.block.system_branding_block',
  'canvas.component.block.system_breadcrumb_block',
  'canvas.component.block.system_powered_by_block',
  'canvas.component.block.views_block.content_recent-block_1',
];

$activeStorage = \Drupal::service('config.storage');
$syncStorage = \Drupal::service('config.storage.sync');

if (!$activeStorage instanceof StorageInterface || !$syncStorage instanceof StorageInterface) {
  throw new RuntimeException('Expected Drupal active and sync configuration storage services.');
}

/**
 * Flattens a nested configuration value into JSON-path-like keys.
 *
 * @param mixed $value
 *   The value to flatten.
 * @param string $prefix
 *   Current path prefix.
 *
 * @return array<string, mixed>
 *   Flattened path/value map.
 */
function agency_canvas_drift_flatten(mixed $value, string $prefix = '$'): array {
  if (!is_array($value)) {
    return [$prefix => $value];
  }

  if ($value === []) {
    return [$prefix => []];
  }

  $flattened = [];
  foreach ($value as $key => $child) {
    $encodedKey = json_encode((string) $key, JSON_THROW_ON_ERROR);
    $childPrefix = $prefix . '[' . $encodedKey . ']';
    $flattened += agency_canvas_drift_flatten($child, $childPrefix);
  }
  return $flattened;
}

/**
 * Returns exact differing paths between active and sync data.
 *
 * @param array<string, mixed> $active
 *   Active configuration data.
 * @param array<string, mixed> $sync
 *   Sync configuration data.
 *
 * @return array<int, array<string, mixed>>
 *   Differences keyed by path.
 */
function agency_canvas_drift_diff(array $active, array $sync): array {
  $activeFlat = agency_canvas_drift_flatten($active);
  $syncFlat = agency_canvas_drift_flatten($sync);
  $paths = array_values(array_unique(array_merge(array_keys($activeFlat), array_keys($syncFlat))));
  sort($paths, SORT_STRING);

  $differences = [];
  foreach ($paths as $path) {
    $activeExists = array_key_exists($path, $activeFlat);
    $syncExists = array_key_exists($path, $syncFlat);
    $activeValue = $activeExists ? $activeFlat[$path] : NULL;
    $syncValue = $syncExists ? $syncFlat[$path] : NULL;
    if ($activeExists === $syncExists && $activeValue === $syncValue) {
      continue;
    }
    $differences[] = [
      'path' => $path,
      'active_exists' => $activeExists,
      'sync_exists' => $syncExists,
      'active' => $activeValue,
      'sync' => $syncValue,
    ];
  }
  return $differences;
}

$items = [];
$differentNames = [];
$problemNames = [];

foreach ($names as $name) {
  $active = $activeStorage->read($name);
  $sync = $syncStorage->read($name);
  if (!is_array($active) || !is_array($sync)) {
    $problemNames[] = $name;
    $items[] = [
      'name' => $name,
      'status' => 'MISSING',
      'active_exists' => is_array($active),
      'sync_exists' => is_array($sync),
    ];
    continue;
  }

  $differences = agency_canvas_drift_diff($active, $sync);
  if ($differences !== []) {
    $differentNames[] = $name;
  }

  $items[] = [
    'name' => $name,
    'status' => $differences === [] ? 'SAME' : 'DIFFERENT',
    'active' => [
      'langcode' => $active['langcode'] ?? NULL,
      'active_version' => $active['active_version'] ?? NULL,
      'label' => $active['label'] ?? NULL,
      'default_label' => $active['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL,
    ],
    'sync' => [
      'langcode' => $sync['langcode'] ?? NULL,
      'active_version' => $sync['active_version'] ?? NULL,
      'label' => $sync['label'] ?? NULL,
      'default_label' => $sync['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL,
    ],
    'difference_count' => count($differences),
    'differences' => $differences,
  ];
}

sort($differentNames, SORT_STRING);
sort($problemNames, SORT_STRING);

$result = [
  'status' => $problemNames === [] ? 'PASS' : 'FAIL',
  'expected_names' => $names,
  'different_names' => $differentNames,
  'problem_names' => $problemNames,
  'counts' => [
    'expected' => count($names),
    'different' => count($differentNames),
    'problems' => count($problemNames),
  ],
  'items' => $items,
  'constraints' => [
    'read_only_probe' => TRUE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_access_allowed' => FALSE,
  ],
];

print json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

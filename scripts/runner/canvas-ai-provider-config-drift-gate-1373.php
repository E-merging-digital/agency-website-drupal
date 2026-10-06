<?php

declare(strict_types=1);

if (!function_exists('agency_canvas_995_analyze_config')) {
  require_once __DIR__ . '/canvas-runtime-diff-paths-995.php';
}

/**
 * Returns the exact #1373 Canvas provider-proof drift cohort.
 *
 * @return list<string>
 *   Sorted configuration names.
 */
function agency_canvas_1373_allowed_names(): array {
  return [
    'canvas.component.block.announce_block',
    'canvas.component.block.emerging_digital_language_switcher',
    'canvas.component.block.help_block',
    'canvas.component.block.language_block.language_content',
    'canvas.component.block.language_block.language_interface',
    'canvas.component.block.local_actions_block',
    'canvas.component.block.local_tasks_block',
    'canvas.component.block.page_title_block',
    'canvas.component.block.shortcuts',
    'canvas.component.block.system_branding_block',
    'canvas.component.block.system_breadcrumb_block',
    'canvas.component.block.system_clear_cache_block',
    'canvas.component.block.system_menu_block.account',
    'canvas.component.block.system_menu_block.footer',
    'canvas.component.block.system_menu_block.main',
    'canvas.component.block.system_menu_block.tools',
    'canvas.component.block.system_powered_by_block',
    'canvas.component.block.user_login_block',
    'canvas.component.block.views_block.content_recent-block_1',
  ];
}

/**
 * Validates exact config:status metadata without accepting values.
 *
 * @param array<string, mixed> $metadata
 *   Metadata-only payload with config_name and state rows.
 *
 * @return list<string>
 *   Exact sorted config names.
 */
function agency_canvas_1373_validate_status_metadata(array $metadata): array {
  $root_keys = array_keys($metadata);
  sort($root_keys, SORT_STRING);
  if ($root_keys !== ['items'] || !is_array($metadata['items'] ?? NULL)) {
    throw new RuntimeException('Unknown #1373 config status metadata shape.');
  }

  $names = [];
  foreach ($metadata['items'] as $row) {
    if (!is_array($row)) {
      throw new RuntimeException('Unknown #1373 config status row shape.');
    }
    $row_keys = array_keys($row);
    sort($row_keys, SORT_STRING);
    if ($row_keys !== ['config_name', 'state']) {
      throw new RuntimeException('Unexpected #1373 config status fields.');
    }
    $name = $row['config_name'] ?? NULL;
    $state = $row['state'] ?? NULL;
    if (!is_string($name) || !is_string($state)) {
      throw new RuntimeException('Invalid #1373 config status metadata.');
    }
    if ($state !== 'Different') {
      throw new RuntimeException('Every #1373 Canvas config must be Different.');
    }
    $names[] = $name;
  }

  sort($names, SORT_STRING);
  $expected = agency_canvas_1373_allowed_names();
  if ($names !== $expected || count($names) !== 19) {
    throw new RuntimeException('Exact #1373 Canvas config cohort mismatch.');
  }

  return $names;
}

/**
 * Analyzes an exact #1373 dataset with the existing #995 comparator.
 *
 * @param array<string, mixed> $metadata
 *   Metadata-only config:status payload.
 * @param array<string, array{active: array<string, mixed>, sync: array<string, mixed>}> $dataset
 *   Exact active/sync dataset.
 *
 * @return array{
 *   items: list<array{
 *     config_name: string,
 *     differing_paths: list<string>,
 *     classification: string
 *   }>,
 *   summary: array{total: int, known: int, unexpected: int}
 * }
 *   Public path-only evidence.
 */
function agency_canvas_1373_analyze_dataset(array $metadata, array $dataset): array {
  $expected = agency_canvas_1373_validate_status_metadata($metadata);
  $actual = array_keys($dataset);
  sort($actual, SORT_STRING);
  if ($actual !== $expected || count($dataset) !== 19) {
    throw new RuntimeException('Exact #1373 Canvas dataset mismatch.');
  }

  $items = [];
  foreach ($expected as $name) {
    $pair = $dataset[$name] ?? NULL;
    if (!is_array($pair)
      || !is_array($pair['active'] ?? NULL)
      || !is_array($pair['sync'] ?? NULL)) {
      throw new RuntimeException('Missing active or sync #1373 Canvas config.');
    }

    $analysis = agency_canvas_995_analyze_config(
      $pair['active'],
      $pair['sync'],
    );
    if ($analysis['classification'] !== 'KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN') {
      throw new RuntimeException('Unknown #1373 Canvas drift path: ' . $name);
    }

    $items[] = [
      'config_name' => $name,
      'differing_paths' => $analysis['differing_paths'],
      'classification' => $analysis['classification'],
    ];
  }

  return [
    'items' => $items,
    'summary' => [
      'total' => 19,
      'known' => 19,
      'unexpected' => 0,
    ],
  ];
}

/**
 * Reads only the exact authorized #1373 active/sync config names.
 */
function agency_canvas_1373_probe(
  \Drupal\Core\Config\StorageInterface $active_storage,
  \Drupal\Core\Config\StorageInterface $sync_storage,
  array $metadata,
): array {
  $dataset = [];
  foreach (agency_canvas_1373_validate_status_metadata($metadata) as $name) {
    $active = $active_storage->read($name);
    $sync = $sync_storage->read($name);
    if (!is_array($active) || !is_array($sync)) {
      throw new RuntimeException('Missing active or sync #1373 Canvas config.');
    }
    $dataset[$name] = [
      'active' => $active,
      'sync' => $sync,
    ];
  }

  return agency_canvas_1373_analyze_dataset($metadata, $dataset);
}

if (getenv('AGENCY_CANVAS_1373_EXECUTE') === '1') {
  $encoded = getenv('AGENCY_CANVAS_1373_STATUS_B64');
  if (!is_string($encoded) || $encoded === '') {
    throw new RuntimeException('Missing #1373 config status metadata.');
  }
  $raw = base64_decode($encoded, TRUE);
  if (!is_string($raw)) {
    throw new RuntimeException('Invalid #1373 config status metadata encoding.');
  }
  $metadata = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
  if (!is_array($metadata)) {
    throw new RuntimeException('Invalid #1373 config status metadata JSON.');
  }

  $active_storage = \Drupal::service('config.storage');
  $sync_storage = \Drupal::service('config.storage.sync');
  if (!$active_storage instanceof \Drupal\Core\Config\StorageInterface
    || !$sync_storage instanceof \Drupal\Core\Config\StorageInterface) {
    throw new RuntimeException('Expected Drupal configuration storages.');
  }

  print json_encode(
    agency_canvas_1373_probe($active_storage, $sync_storage, $metadata),
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
  ) . PHP_EOL;
}

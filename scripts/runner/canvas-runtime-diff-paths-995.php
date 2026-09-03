<?php

declare(strict_types=1);

use Drupal\Core\Config\StorageInterface;

/**
 * Returns the exact #995 Canvas block cohort.
 *
 * @return list<string>
 *   Sorted configuration names.
 */
function agency_canvas_995_allowed_names(): array {
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
    'canvas.component.block.system_powered_by_block',
    'canvas.component.block.user_login_block',
    'canvas.component.block.views_block.content_recent-block_1',
  ];
}

/**
 * Joins one bounded path segment without exposing scalar values.
 */
function agency_canvas_995_join_path(string $prefix, string $segment): string {
  return $prefix === '' ? $segment : $prefix . '.' . $segment;
}

/**
 * Rejects path segments that could be unbounded user/business keys.
 */
function agency_canvas_995_assert_safe_segment(string $segment, string $prefix): void {
  if ($prefix === 'versioned_properties') {
    if ($segment === 'active' || preg_match('/^[0-9a-f]{16}$/D', $segment) === 1) {
      return;
    }
    throw new RuntimeException('Unsafe dynamic Canvas version key.');
  }

  if (in_array($prefix, ['third_party_settings', 'context_mapping'], TRUE)) {
    throw new RuntimeException('Unsafe dynamic Canvas map key.');
  }

  if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $segment) !== 1) {
    throw new RuntimeException('Unsafe Canvas configuration path segment.');
  }
}

/**
 * Recursively collects differing paths only.
 *
 * @param mixed $active
 *   Active value.
 * @param mixed $sync
 *   Sync value.
 * @param string $path
 *   Current public path.
 * @param string|null $sync_version
 *   Sync active_version used to prove the historical-version branch.
 * @param array<string, bool> $dynamic_version_facts
 *   Internal technical facts. Values never enter public evidence.
 *
 * @return list<string>
 *   Public path-only differences.
 */
function agency_canvas_995_diff_paths(
  mixed $active,
  mixed $sync,
  string $path,
  ?string $sync_version,
  array &$dynamic_version_facts,
): array {
  if (is_array($active) !== is_array($sync)) {
    throw new RuntimeException('Unknown Canvas storage shape at path: ' . ($path ?: '<root>'));
  }

  if (!is_array($active)) {
    return $active === $sync ? [] : [$path];
  }

  $active_is_list = array_is_list($active);
  $sync_is_list = array_is_list($sync);
  if ($active_is_list !== $sync_is_list) {
    throw new RuntimeException('Unknown Canvas array shape at path: ' . ($path ?: '<root>'));
  }
  if ($active_is_list) {
    return $active === $sync ? [] : [$path];
  }

  $keys = array_values(array_unique(array_merge(array_keys($active), array_keys($sync))));
  foreach ($keys as $key) {
    if (!is_string($key)) {
      throw new RuntimeException('Unknown Canvas map key type.');
    }
  }
  sort($keys, SORT_STRING);

  $paths = [];
  foreach ($keys as $key) {
    agency_canvas_995_assert_safe_segment($key, $path);
    $active_exists = array_key_exists($key, $active);
    $sync_exists = array_key_exists($key, $sync);

    $public_segment = $key;
    if ($path === 'versioned_properties' && preg_match('/^[0-9a-f]{16}$/D', $key) === 1) {
      $public_segment = '<version>';
      $fact_key = implode(':', [
        $key,
        $active_exists ? 'active' : 'no-active',
        $sync_exists ? 'sync' : 'no-sync',
        $sync_version === $key ? 'matches-sync-version' : 'other-version',
      ]);
      $dynamic_version_facts[$fact_key] = TRUE;
    }

    $child_path = agency_canvas_995_join_path($path, $public_segment);
    if (!$active_exists || !$sync_exists) {
      $paths[] = $child_path;
      continue;
    }

    $paths = array_merge(
      $paths,
      agency_canvas_995_diff_paths(
        $active[$key],
        $sync[$key],
        $child_path,
        $sync_version,
        $dynamic_version_facts,
      ),
    );
  }

  $paths = array_values(array_unique($paths));
  sort($paths, SORT_STRING);
  return $paths;
}

/**
 * Analyzes one Canvas config without returning values.
 *
 * @param array<string, mixed> $active
 *   Active configuration.
 * @param array<string, mixed> $sync
 *   Sync configuration.
 *
 * @return array{differing_paths: list<string>, classification: string}
 *   Path-only result.
 */
function agency_canvas_995_analyze_config(array $active, array $sync): array {
  foreach ([$active, $sync] as $config) {
    if (array_is_list($config)) {
      throw new RuntimeException('Unknown Canvas root storage shape.');
    }
    if (!is_string($config['active_version'] ?? NULL)
      || preg_match('/^[0-9a-f]{16}$/D', $config['active_version']) !== 1
      || !is_array($config['versioned_properties'] ?? NULL)
      || !is_array($config['versioned_properties']['active'] ?? NULL)) {
      throw new RuntimeException('Unsupported Canvas component storage structure.');
    }
  }

  $sync_version = $sync['active_version'];
  $dynamic_version_facts = [];
  $paths = agency_canvas_995_diff_paths(
    $active,
    $sync,
    '',
    $sync_version,
    $dynamic_version_facts,
  );

  if ($paths === []) {
    throw new RuntimeException('Expected #995 Canvas config to be Different.');
  }

  $known_paths = [
    'active_version',
    'label',
    'versioned_properties.<version>',
    'versioned_properties.active.settings.default_settings.label',
  ];

  $known = TRUE;
  foreach ($paths as $path) {
    if (!in_array($path, $known_paths, TRUE)) {
      $known = FALSE;
      break;
    }
  }

  if (in_array('versioned_properties.<version>', $paths, TRUE)) {
    $expected_fact = implode(':', [
      $sync_version,
      'active',
      'no-sync',
      'matches-sync-version',
    ]);
    if (array_keys($dynamic_version_facts) !== [$expected_fact]) {
      $known = FALSE;
    }
  }
  elseif ($dynamic_version_facts !== []) {
    $known = FALSE;
  }

  return [
    'differing_paths' => $paths,
    'classification' => $known
      ? 'KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN'
      : 'UNEXPECTED_CANVAS_BUSINESS_PATH_REVIEW_REQUIRED',
  ];
}

/**
 * Builds public evidence from an exact synthetic/runtime dataset.
 *
 * @param string $environment
 *   PREPROD or PROD.
 * @param array<string, array{active: array<string, mixed>, sync: array<string, mixed>}> $dataset
 *   Exact 15-name dataset.
 *
 * @return array<string, mixed>
 *   Public path-only evidence.
 */
function agency_canvas_995_analyze_dataset(string $environment, array $dataset): array {
  if (!in_array($environment, ['PREPROD', 'PROD'], TRUE)) {
    throw new RuntimeException('Unsupported #995 environment.');
  }

  $expected = agency_canvas_995_allowed_names();
  $actual = array_keys($dataset);
  sort($actual, SORT_STRING);
  if ($actual !== $expected || count($dataset) !== 15) {
    throw new RuntimeException('Exact #995 Canvas cohort mismatch.');
  }

  $items = [];
  $known_count = 0;
  $unexpected_count = 0;
  foreach ($expected as $name) {
    $pair = $dataset[$name] ?? NULL;
    if (!is_array($pair)
      || !is_array($pair['active'] ?? NULL)
      || !is_array($pair['sync'] ?? NULL)) {
      throw new RuntimeException('Unknown #995 Canvas dataset shape.');
    }

    $analysis = agency_canvas_995_analyze_config($pair['active'], $pair['sync']);
    $classification = $analysis['classification'];
    if ($classification === 'KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN') {
      $known_count++;
    }
    else {
      $unexpected_count++;
    }
    $items[] = [
      'environment' => $environment,
      'config_name' => $name,
      'differing_paths' => $analysis['differing_paths'],
      'classification' => $classification,
    ];
  }

  return [
    'schema_version' => 1,
    'environment' => $environment,
    'public_schema' => 'environment + config_name + differing_paths[] + classification',
    'config_values_exposed' => FALSE,
    'cohort_size' => 15,
    'items' => $items,
    'summary' => [
      'total' => 15,
      'known_canvas_deterministic_drift_pattern' => $known_count,
      'unexpected_canvas_business_path_review_required' => $unexpected_count,
    ],
  ];
}

/**
 * Reads the exact dataset from Drupal active and sync storage.
 */
function agency_canvas_995_probe(
  StorageInterface $active_storage,
  StorageInterface $sync_storage,
  string $environment,
): array {
  $dataset = [];
  foreach (agency_canvas_995_allowed_names() as $name) {
    $active = $active_storage->read($name);
    $sync = $sync_storage->read($name);
    if (!is_array($active) || !is_array($sync)) {
      throw new RuntimeException('Missing active or sync #995 Canvas config: ' . $name);
    }
    $dataset[$name] = [
      'active' => $active,
      'sync' => $sync,
    ];
  }
  return agency_canvas_995_analyze_dataset($environment, $dataset);
}

if (getenv('AGENCY_CANVAS_995_EXECUTE') === '1') {
  $environment = getenv('AGENCY_CANVAS_995_ENVIRONMENT');
  if (!is_string($environment)) {
    throw new RuntimeException('Missing #995 environment.');
  }

  $active_storage = \Drupal::service('config.storage');
  $sync_storage = \Drupal::service('config.storage.sync');
  if (!$active_storage instanceof StorageInterface || !$sync_storage instanceof StorageInterface) {
    throw new RuntimeException('Expected Drupal active and sync configuration storages.');
  }

  print json_encode(
    agency_canvas_995_probe($active_storage, $sync_storage, $environment),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
  ) . PHP_EOL;
}

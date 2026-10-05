<?php

declare(strict_types=1);

/**
 * @file
 * Stabilizes the exact eight Canvas component configs in disposable sync.
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
  throw new RuntimeException('Expected Drupal active and sync configuration storages.');
}

$normalize = NULL;
$normalize = static function (mixed $value) use (&$normalize): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (array_is_list($value)) {
    return array_map($normalize, $value);
  }
  ksort($value, SORT_STRING);
  foreach ($value as $key => $child) {
    $value[$key] = $normalize($child);
  }
  return $value;
};

$fingerprint = static function (mixed $value) use ($normalize): string {
  return hash('sha256', json_encode(
    $normalize($value),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  ));
};

$items = [];
$paths = [];
foreach ($names as $name) {
  $active = $activeStorage->read($name);
  $sync = $syncStorage->read($name);
  if (!is_array($active) || !is_array($sync)) {
    throw new RuntimeException("Missing active or sync Canvas component: $name");
  }
  if (($active['langcode'] ?? NULL) !== 'fr' || ($sync['langcode'] ?? NULL) !== 'fr') {
    throw new RuntimeException("Canvas stabilization must preserve FR langcode: $name");
  }

  $activeVersion = $active['active_version'] ?? NULL;
  $syncVersion = $sync['active_version'] ?? NULL;
  if (!is_string($activeVersion) || !is_string($syncVersion) || $activeVersion === $syncVersion) {
    throw new RuntimeException("Expected deterministic Canvas active_version drift: $name");
  }

  $activeLabel = $active['label'] ?? NULL;
  $syncLabel = $sync['label'] ?? NULL;
  $activeDefaultLabel = $active['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL;
  $syncDefaultLabel = $sync['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL;
  if (!is_string($activeLabel) || !is_string($syncLabel)
    || !is_string($activeDefaultLabel) || !is_string($syncDefaultLabel)
    || $activeLabel === $syncLabel || $activeDefaultLabel === $syncDefaultLabel) {
    throw new RuntimeException("Expected deterministic Canvas label drift: $name");
  }

  $historical = $active['versioned_properties'][$syncVersion] ?? NULL;
  $syncActive = $sync['versioned_properties']['active'] ?? NULL;
  if (!is_array($historical) || !is_array($syncActive) || $historical !== $syncActive) {
    throw new RuntimeException("Expected old sync version to be retained as Canvas history: $name");
  }

  $beforeFingerprint = $fingerprint($sync);
  $activeFingerprint = $fingerprint($active);
  if ($beforeFingerprint === $activeFingerprint) {
    throw new RuntimeException("Canvas component is unexpectedly already stable: $name");
  }

  if (!$syncStorage->write($name, $active)) {
    throw new RuntimeException("Unable to write stabilized Canvas sync config: $name");
  }
  $written = $syncStorage->read($name);
  if (!is_array($written) || $fingerprint($written) !== $activeFingerprint) {
    throw new RuntimeException("Canvas stabilization writeback mismatch: $name");
  }
  if (($written['langcode'] ?? NULL) !== 'fr') {
    throw new RuntimeException("Canvas stabilization changed langcode: $name");
  }

  $path = 'config/sync/' . $name . '.yml';
  $paths[] = $path;
  $items[] = [
    'name' => $name,
    'path' => $path,
    'langcode' => 'fr',
    'before_fingerprint' => $beforeFingerprint,
    'after_fingerprint' => $activeFingerprint,
    'sync_active_version_before' => $syncVersion,
    'active_version_after' => $activeVersion,
    'sync_label_before' => $syncLabel,
    'active_label_after' => $activeLabel,
    'sync_default_label_before' => $syncDefaultLabel,
    'active_default_label_after' => $activeDefaultLabel,
  ];
}

sort($paths, SORT_STRING);
$result = [
  'status' => 'PASS',
  'verdict' => 'EIGHT_CANVAS_COMPONENT_STABILIZATION_PATCH_PREPARED',
  'counts' => [
    'expected' => 8,
    'stabilized' => count($items),
    'paths_changed' => count($paths),
    'problem_count' => 0,
  ],
  'paths' => $paths,
  'items' => $items,
  'constraints' => [
    'exact_eight_only' => TRUE,
    'langcode_preserved_fr' => TRUE,
    'drupal_sync_storage_used' => TRUE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'repository_push_allowed' => FALSE,
    'production_access_allowed' => FALSE,
  ],
  'problems' => [],
];

print json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

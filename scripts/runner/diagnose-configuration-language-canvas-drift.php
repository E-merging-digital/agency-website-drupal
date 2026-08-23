<?php

declare(strict_types=1);

use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Language\LanguageInterface;

$expectedNames = [
  'canvas.component.block.help_block',
  'canvas.component.block.language_block.language_content',
  'canvas.component.block.local_actions_block',
  'canvas.component.block.page_title_block',
  'canvas.component.block.system_branding_block',
  'canvas.component.block.system_breadcrumb_block',
  'canvas.component.block.system_powered_by_block',
  'canvas.component.block.views_block.content_recent-block_1',
];

$syncStorage = \Drupal::service('config.storage.sync');
$activeStorage = \Drupal::service('config.storage');
$languageManager = \Drupal::languageManager();
if (!$syncStorage instanceof StorageInterface || !$activeStorage instanceof StorageInterface) {
  throw new RuntimeException('Configuration storages are unavailable.');
}

$syncEnglish = $syncStorage->createCollection('language.en');

$diffValues = NULL;
$diffValues = static function (
  mixed $sync,
  mixed $active,
  array $segments = [],
) use (&$diffValues): array {
  if (is_array($sync) && is_array($active)) {
    $differences = [];
    $keys = array_values(array_unique([...array_keys($sync), ...array_keys($active)]));
    sort($keys);
    foreach ($keys as $key) {
      $syncExists = array_key_exists($key, $sync);
      $activeExists = array_key_exists($key, $active);
      $path = [...$segments, $key];
      if (!$syncExists || !$activeExists) {
        $differences[] = [
          'path' => implode('.', array_map('strval', $path)),
          'sync_exists' => $syncExists,
          'active_exists' => $activeExists,
          'sync' => $syncExists ? $sync[$key] : NULL,
          'active' => $activeExists ? $active[$key] : NULL,
        ];
        continue;
      }
      foreach ($diffValues($sync[$key], $active[$key], $path) as $difference) {
        $differences[] = $difference;
      }
    }
    return $differences;
  }

  if ($sync === $active) {
    return [];
  }

  return [[
    'path' => implode('.', array_map('strval', $segments)),
    'sync_exists' => TRUE,
    'active_exists' => TRUE,
    'sync' => $sync,
    'active' => $active,
  ]];
};

$items = [];
$differentNames = [];
foreach ($expectedNames as $name) {
  $sync = $syncStorage->read($name);
  $active = $activeStorage->read($name);
  if (!is_array($sync) || !is_array($active)) {
    throw new RuntimeException("Missing sync or active Canvas component: $name");
  }

  $differences = $diffValues($sync, $active);
  if ($differences !== []) {
    $differentNames[] = $name;
  }

  $items[] = [
    'name' => $name,
    'has_sync_en_override' => $syncEnglish->exists($name),
    'sync_langcode' => $sync['langcode'] ?? NULL,
    'active_langcode' => $active['langcode'] ?? NULL,
    'sync_active_version' => $sync['active_version'] ?? NULL,
    'active_active_version' => $active['active_version'] ?? NULL,
    'sync_label' => $sync['label'] ?? NULL,
    'active_label' => $active['label'] ?? NULL,
    'sync_default_label' => $sync['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL,
    'active_default_label' => $active['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL,
    'difference_count' => count($differences),
    'differences' => $differences,
  ];
}

sort($differentNames, SORT_STRING);
$expectedSorted = $expectedNames;
sort($expectedSorted, SORT_STRING);

$configOverrideLanguage = NULL;
if (method_exists($languageManager, 'getConfigOverrideLanguage')) {
  $configOverrideLanguage = $languageManager->getConfigOverrideLanguage()->getId();
}

$result = [
  'status' => $differentNames === $expectedSorted ? 'PASS' : 'FAIL',
  'verdict' => $differentNames === $expectedSorted
    ? 'EIGHT_CANVAS_COMPONENT_LANGUAGE_DRIFTS_DIAGNOSED'
    : 'CANVAS_COMPONENT_DRIFT_SET_UNEXPECTED',
  'expected_names' => $expectedSorted,
  'different_names' => $differentNames,
  'counts' => [
    'expected' => count($expectedSorted),
    'different' => count($differentNames),
    'problem_count' => $differentNames === $expectedSorted ? 0 : 1,
  ],
  'languages' => [
    'system_site_default_langcode' => \Drupal::config('system.site')->get('default_langcode'),
    'manager_default' => $languageManager->getDefaultLanguage()->getId(),
    'current_interface' => $languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId(),
    'current_content' => $languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId(),
    'current_url' => $languageManager->getCurrentLanguage(LanguageInterface::TYPE_URL)->getId(),
    'config_override' => $configOverrideLanguage,
  ],
  'items' => $items,
  'constraints' => [
    'read_only' => TRUE,
    'repository_mutation_allowed' => FALSE,
    'config_write_allowed' => FALSE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_access_allowed' => FALSE,
  ],
];

print json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

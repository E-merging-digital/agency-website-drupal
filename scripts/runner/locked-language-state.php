<?php

declare(strict_types=1);

/**
 * Emits the active state of Drupal's two locked semantic languages.
 *
 * This helper is intentionally read-only. It is executed through Drush only
 * inside the isolated DDEV proof for configuration-language governance.
 */

$storage = \Drupal::service('config.storage');

$languages = [];
foreach (['und', 'zxx'] as $expectedId) {
  $name = 'language.entity.' . $expectedId;
  $data = $storage->read($name);
  $present = is_array($data);
  if (!$present) {
    $data = [];
  }

  $languages[$expectedId] = [
    'present' => $present,
    'id' => $data['id'] ?? NULL,
    'langcode' => $data['langcode'] ?? NULL,
    'locked' => $data['locked'] ?? NULL,
    'weight' => $data['weight'] ?? NULL,
    'label' => $data['label'] ?? NULL,
  ];
}

$coreExtension = $storage->read('core.extension');
if (!is_array($coreExtension)) {
  $coreExtension = [];
}

$settings = $storage->read('config_language_lock.settings');
if (!is_array($settings)) {
  $settings = [];
}

$output = [
  'schema_version' => 1,
  'site_default_language' => \Drupal::languageManager()
    ->getDefaultLanguage()
    ->getId(),
  'config_language_lock_enabled' => array_key_exists(
    'config_language_lock',
    $coreExtension['module'] ?? [],
  ),
  'lock_settings' => [
    'locked_langcode' => $settings['locked_langcode'] ?? NULL,
    'follow_site_default' => $settings['follow_site_default'] ?? NULL,
  ],
  'languages' => $languages,
];

echo json_encode(
  $output,
  JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_THROW_ON_ERROR,
) . PHP_EOL;

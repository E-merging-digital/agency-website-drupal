<?php

declare(strict_types=1);

/**
 * Emits a deterministic fingerprint of Drupal active configuration.
 *
 * This script is intentionally read-only. It is executed through Drush inside
 * the isolated DDEV proof for Configuration Language Lock.
 */

$storage = \Drupal::service('config.storage');

$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
  if (!is_array($value)) {
    return $value;
  }

  if (array_is_list($value)) {
    return array_map($canonicalize, $value);
  }

  ksort($value, SORT_STRING);
  foreach ($value as $key => $entry) {
    $value[$key] = $canonicalize($entry);
  }
  return $value;
};

$names = $storage->listAll();
sort($names, SORT_STRING);

$entries = [];
$moduleOwned = [];
foreach ($names as $name) {
  $data = $storage->read($name);
  if (!is_array($data)) {
    $data = [];
  }
  $canonical = $canonicalize($data);
  $encoded = json_encode(
    $canonical,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  );
  $entry = [
    'sha256' => hash('sha256', $encoded),
    'langcode' => array_key_exists('langcode', $canonical)
      ? $canonical['langcode']
      : NULL,
    'id' => isset($canonical['id']) && is_string($canonical['id'])
      ? $canonical['id']
      : NULL,
  ];
  $entries[$name] = $entry;

  if (str_starts_with($name, 'config_language_lock.')) {
    $moduleOwned[$name] = $canonical;
  }
}

$overall = json_encode(
  array_map(
    static fn(array $entry): string => $entry['sha256'],
    $entries,
  ),
  JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);

$coreExtension = $storage->read('core.extension');
if (!is_array($coreExtension)) {
  $coreExtension = [];
}

$output = [
  'schema_version' => 2,
  'total' => count($entries),
  'overall_sha256' => hash('sha256', $overall),
  'site_default_language' => \Drupal::languageManager()
    ->getDefaultLanguage()
    ->getId(),
  'entries' => $entries,
  'module_owned' => $moduleOwned,
  'special' => [
    'system_menu_footer_langcode' => $entries['system.menu.footer']['langcode'] ?? NULL,
    'language_entity_und_id' => $entries['language.entity.und']['id'] ?? NULL,
    'language_entity_und_source_langcode' => $entries['language.entity.und']['langcode'] ?? NULL,
    'language_entity_zxx_id' => $entries['language.entity.zxx']['id'] ?? NULL,
    'language_entity_zxx_source_langcode' => $entries['language.entity.zxx']['langcode'] ?? NULL,
  ],
  'config_language_lock_enabled' => array_key_exists(
    'config_language_lock',
    $coreExtension['module'] ?? [],
  ),
];

echo json_encode(
  $output,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

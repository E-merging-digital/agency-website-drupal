<?php

declare(strict_types=1);

use Drupal\Core\Config\StorageInterface;
use Drupal\language\Entity\ConfigurableLanguage;

$storage = \Drupal::service('config.storage');
$configFactory = \Drupal::service('config.factory');
$overrideFactory = \Drupal::service('language.config_factory_override');
if (!$storage instanceof StorageInterface) {
  throw new RuntimeException('Active configuration storage is unavailable.');
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

$names = $storage->listAll();
sort($names, SORT_STRING);
$excluded = [
  'config_language_lock.settings' => TRUE,
  'language.entity.und' => TRUE,
  'language.entity.zxx' => TRUE,
];
$semanticNames = array_values(array_filter(
  $names,
  static fn(string $name): bool => !isset($excluded[$name]),
));

$languages = [];
foreach (['fr', 'en'] as $langcode) {
  $language = ConfigurableLanguage::load($langcode);
  if (!$language instanceof ConfigurableLanguage) {
    throw new RuntimeException("Missing language $langcode.");
  }

  $overrideFactory->setLanguage($language);
  $configFactory->reset();

  $fingerprints = [];
  foreach ($semanticNames as $name) {
    $data = $configFactory->get($name)->get();
    if (!is_array($data)) {
      throw new RuntimeException("Effective config $name is not an array.");
    }
    // #1316 compares effective semantic values, not technical ownership.
    // Exclude only the top-level config langcode; nested langcodes remain.
    unset($data['langcode']);
    $fingerprints[$name] = hash('sha256', json_encode(
      $normalize($data),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ));
  }
  ksort($fingerprints, SORT_STRING);

  $languages[$langcode] = [
    'count' => count($fingerprints),
    'names_sha256' => hash('sha256', implode("\n", array_keys($fingerprints))),
    'values_sha256' => hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR)),
  ];
}

$languageEvidence = static function (string $id) use ($storage): array {
  $data = $storage->read('language.entity.' . $id);
  if (!is_array($data)) {
    throw new RuntimeException("Missing semantic language $id.");
  }
  return [
    'id' => $data['id'] ?? NULL,
    'locked' => $data['locked'] ?? NULL,
    'technical_langcode' => $data['langcode'] ?? NULL,
  ];
};

echo json_encode([
  'schema_version' => 1,
  'languages' => $languages,
  'und' => $languageEvidence('und'),
  'zxx' => $languageEvidence('zxx'),
  'config_values_exposed' => FALSE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

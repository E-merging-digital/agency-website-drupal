<?php

declare(strict_types=1);

use Drupal\config_language_lock\ConfigLanguageLockConfigManager;
use Drupal\Core\Config\StorageInterface;

if (getenv('AGENCY_CONFIG_LANGUAGE_SPECIAL_RECONCILE') !== '1') {
  throw new RuntimeException('Explicit special-language reconcile flag is required.');
}

$configFactory = \Drupal::service('config.factory');
$storage = \Drupal::service('config.storage');
$languageManager = \Drupal::languageManager();
$manager = \Drupal::service(ConfigLanguageLockConfigManager::class);

if (!$storage instanceof StorageInterface) {
  throw new RuntimeException('Active configuration storage is unavailable.');
}
if (!$manager instanceof ConfigLanguageLockConfigManager) {
  throw new RuntimeException('Config Language Lock manager is unavailable.');
}

$siteDefault = $languageManager->getDefaultLanguage()->getId();
if ($siteDefault !== 'fr') {
  throw new RuntimeException('Expected site default language fr.');
}

$lockSettings = $configFactory->get('config_language_lock.settings');
$lockedLangcode = $lockSettings->get('locked_langcode');
$followSiteDefault = $lockSettings->get('follow_site_default');
if ($lockedLangcode !== 'fr' || $followSiteDefault !== TRUE) {
  throw new RuntimeException('Config Language Lock settings are not fr / follow-site-default.');
}

$targets = [
  'language.entity.und' => 'und',
  'language.entity.zxx' => 'zxx',
];

$before = [];
foreach ($targets as $name => $semanticId) {
  $data = $storage->read($name);
  if (!is_array($data)) {
    throw new RuntimeException("Unable to read active special language config $name.");
  }
  if (($data['id'] ?? NULL) !== $semanticId) {
    throw new RuntimeException("Unexpected semantic id for $name.");
  }
  if (($data['locked'] ?? NULL) !== TRUE) {
    throw new RuntimeException("Expected locked=true for $name.");
  }

  $technicalLangcode = $data['langcode'] ?? NULL;
  if (!is_string($technicalLangcode)
    || !in_array($technicalLangcode, ['en', 'fr'], TRUE)) {
    throw new RuntimeException("Unexpected technical langcode for $name.");
  }
  $before[$semanticId] = $technicalLangcode;
}

$stats = $manager->updateConfigForLockedLanguageSwitch(array_keys($targets));
if (!is_array($stats)) {
  throw new RuntimeException('Unexpected Config Language Lock migration result.');
}

$boundedStats = [];
foreach ([
  'config_items_changed',
  'translations_updated',
  'translations_removed',
] as $key) {
  if (!array_key_exists($key, $stats) || !is_int($stats[$key])) {
    throw new RuntimeException("Unexpected manager stat $key.");
  }
  $boundedStats[$key] = $stats[$key];
}

$after = [];
foreach ($targets as $name => $semanticId) {
  $configFactory->reset($name);
  $data = $storage->read($name);
  if (!is_array($data)) {
    throw new RuntimeException("Unable to re-read active special language config $name.");
  }
  if (($data['id'] ?? NULL) !== $semanticId) {
    throw new RuntimeException("Semantic id changed for $name.");
  }
  if (($data['locked'] ?? NULL) !== TRUE) {
    throw new RuntimeException("Locked state changed for $name.");
  }
  if (($data['langcode'] ?? NULL) !== 'fr') {
    throw new RuntimeException("Technical langcode did not converge to fr for $name.");
  }
  $after[$semanticId] = 'fr';
}

$result = [
  'schema_version' => 1,
  'status' => 'PASS',
  'mechanism' => 'config_language_lock_special_entity_reconcile',
  'targets' => array_keys($targets),
  'before_technical_langcodes' => $before,
  'after_technical_langcodes' => $after,
  'manager_stats' => $boundedStats,
  'site_default_language' => $siteDefault,
  'locked_langcode' => $lockedLangcode,
  'follow_site_default' => $followSiteDefault,
  'config_values_exposed' => FALSE,
];

echo json_encode(
  $result,
  JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_THROW_ON_ERROR,
) . PHP_EOL;

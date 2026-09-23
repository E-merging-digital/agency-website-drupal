<?php

declare(strict_types=1);

use Drupal\config_language_lock\ConfigLanguageLockConfigManager;

if (getenv('AGENCY_CONFIG_LANGUAGE_1316_EXECUTE') !== '1') {
  throw new RuntimeException('Explicit disposable execution flag is required.');
}

$configFactory = \Drupal::service('config.factory');
$storage = \Drupal::service('config.storage');
$languageManager = \Drupal::languageManager();
$manager = \Drupal::service(ConfigLanguageLockConfigManager::class);
if (!$manager instanceof ConfigLanguageLockConfigManager) {
  throw new RuntimeException('Config Language Lock manager is unavailable.');
}

$siteDefault = $languageManager->getDefaultLanguage()->getId();
if ($siteDefault !== 'fr') {
  throw new RuntimeException('Expected site default language fr.');
}

$settings = $configFactory->getEditable('config_language_lock.settings');
$settings
  ->set('locked_langcode', $siteDefault)
  ->set('follow_site_default', TRUE)
  ->save();

$names = $storage->listAll();
sort($names, SORT_STRING);
$stats = $manager->updateConfigForLockedLanguageSwitch($names);
if (!is_array($stats)) {
  throw new RuntimeException('Unexpected Config Language Lock migration result.');
}

$configFactory->reset('config_language_lock.settings');
$effective = $configFactory->get('config_language_lock.settings');
if ($effective->get('locked_langcode') !== 'fr'
  || $effective->get('follow_site_default') !== TRUE) {
  throw new RuntimeException('Candidate A lock settings were not applied.');
}

$result = [
  'schema_version' => 1,
  'status' => 'PASS',
  'mechanism' => 'Drupal\\config_language_lock\\ConfigLanguageLockConfigManager::updateConfigForLockedLanguageSwitch',
  'input_config_name_count' => count($names),
  'stats' => $stats,
  'locked_langcode' => 'fr',
  'follow_site_default' => TRUE,
  'site_default_language' => $siteDefault,
  'prod_access' => 'NONE',
  'preprod_access' => 'NONE',
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

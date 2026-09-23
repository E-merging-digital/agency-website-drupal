<?php

declare(strict_types=1);

use Drupal\config_language_lock\Hook\ConfigLanguageLockRequirementsHooks;
use Drupal\Core\Extension\Requirement\RequirementSeverity;

/**
 * Emits bounded runtime evidence for the disposable #1314 Candidate A proof.
 *
 * Only versions, lock settings, requirement verdict, collection counts/name
 * hashes and und/zxx semantic state are exposed. No configuration values or
 * collection names are emitted.
 */

$moduleHandler = \Drupal::moduleHandler();
if (
  !$moduleHandler->moduleExists('canvas')
  || !$moduleHandler->moduleExists('config_language_lock')
) {
  exit(2);
}

$moduleList = \Drupal::service('extension.list.module');
$extensionVersion = static function (string $module) use ($moduleList): string {
  $info = $moduleList->getExtensionInfo($module);
  $version = $info['version'] ?? NULL;
  if (!is_string($version) || $version === '' || strlen($version) > 64) {
    exit(3);
  }

  return $version;
};

$coreVersion = \Drupal::VERSION;
if (!is_string($coreVersion) || $coreVersion === '' || strlen($coreVersion) > 64) {
  exit(4);
}

$configFactory = \Drupal::service('config.factory');
$settings = $configFactory->get('config_language_lock.settings');
$lockedLangcode = $settings->get('locked_langcode');
$followSiteDefault = $settings->get('follow_site_default');
if (!is_string($lockedLangcode) || !is_bool($followSiteDefault)) {
  exit(5);
}

$siteDefaultLanguage = \Drupal::languageManager()->getDefaultLanguage()->getId();
if (
  !is_string($siteDefaultLanguage)
  || preg_match('/^[A-Za-z0-9_-]+$/D', $siteDefaultLanguage) !== 1
) {
  exit(6);
}

$requirements = \Drupal::service(ConfigLanguageLockRequirementsHooks::class)
  ->runtimeRequirements();
if (!is_array($requirements)) {
  exit(7);
}
$requirement = $requirements['config_language_lock_canvas_mismatch'] ?? NULL;
if ($requirement === NULL) {
  $requirementVerdict = 'PASS';
}
else {
  if (!is_array($requirement)) {
    exit(8);
  }
  $severity = $requirement['severity'] ?? NULL;
  if (!$severity instanceof RequirementSeverity) {
    exit(9);
  }
  $requirementVerdict = match ($severity) {
    RequirementSeverity::Error => 'ERROR',
    RequirementSeverity::Warning => 'WARNING',
    RequirementSeverity::OK,
    RequirementSeverity::Info => 'PASS',
  };
}

$activeStorage = \Drupal::service('config.storage');
$collectionEvidence = static function (string $collection) use ($activeStorage): array {
  $names = $activeStorage->createCollection($collection)->listAll();
  sort($names, SORT_STRING);

  return [
    'count' => count($names),
    'names_sha256' => hash('sha256', implode("\n", $names)),
  ];
};

$languageEvidence = static function (string $id) use ($activeStorage): array {
  $data = $activeStorage->read('language.entity.' . $id);
  if (!is_array($data)) {
    exit(10);
  }

  $entityId = $data['id'] ?? NULL;
  $locked = $data['locked'] ?? NULL;
  $langcode = $data['langcode'] ?? NULL;
  if (
    !is_string($entityId)
    || !is_bool($locked)
    || !is_string($langcode)
    || $langcode === ''
  ) {
    exit(11);
  }

  return [
    'id' => $entityId,
    'locked' => $locked,
    'technical_langcode' => $langcode,
  ];
};

$result = [
  'schema_version' => 1,
  'drupal_core_version' => $coreVersion,
  'canvas_version' => $extensionVersion('canvas'),
  'config_language_lock_version' => $extensionVersion('config_language_lock'),
  'site_default_language' => $siteDefaultLanguage,
  'locked_langcode' => $lockedLangcode,
  'follow_site_default' => $followSiteDefault,
  'canvas_requirement_verdict' => $requirementVerdict,
  'collections' => [
    'fr' => $collectionEvidence('language.fr'),
    'en' => $collectionEvidence('language.en'),
  ],
  'languages' => [
    'und' => $languageEvidence('und'),
    'zxx' => $languageEvidence('zxx'),
  ],
  'config_values_exposed' => FALSE,
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_THROW_ON_ERROR,
) . PHP_EOL;

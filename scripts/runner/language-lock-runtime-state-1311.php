<?php

declare(strict_types=1);

use Drupal\config_language_lock\Hook\ConfigLanguageLockRequirementsHooks;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Site\Settings;

/**
 * Emits bounded read-only Language Lock / Canvas runtime evidence for #1302.
 *
 * The requirement verdict comes from the installed config_language_lock
 * runtime requirements implementation. No configuration is changed.
 */

if (getenv('AGENCY_LANGUAGE_LOCK_1311_EXECUTE') !== '1') {
  exit(2);
}
if (getenv('AGENCY_LANGUAGE_LOCK_1311_ENVIRONMENT') !== 'PROD') {
  exit(3);
}

$activeDrupalRoot = realpath(DRUPAL_ROOT);
if (
  $activeDrupalRoot === FALSE
  || preg_match(
    '#^/var/www/agency/releases/[A-Za-z0-9._-]+/web$#',
    $activeDrupalRoot,
  ) !== 1
) {
  exit(4);
}
$currentRelease = basename(dirname($activeDrupalRoot));

$moduleHandler = \Drupal::moduleHandler();
$configFactory = \Drupal::service('config.factory');
$languageManager = \Drupal::languageManager();
$moduleList = \Drupal::service('extension.list.module');
$activeStorage = \Drupal::service('config.storage');

$canvasEnabled = $moduleHandler->moduleExists('canvas');
$configLanguageLockEnabled = $moduleHandler->moduleExists('config_language_lock');

$extensionVersion = static function (string $module) use ($moduleList): string {
  $info = $moduleList->getExtensionInfo($module);
  $version = $info['version'] ?? NULL;
  if (!is_string($version) || $version === '' || strlen($version) > 64) {
    exit(5);
  }
  return $version;
};

$canvasVersion = $extensionVersion('canvas');
$configLanguageLockVersion = $extensionVersion('config_language_lock');
$coreVersion = \Drupal::VERSION;
if (!is_string($coreVersion) || $coreVersion === '' || strlen($coreVersion) > 64) {
  exit(6);
}

$siteDefaultLanguage = $languageManager->getDefaultLanguage()->getId();
if (
  !is_string($siteDefaultLanguage)
  || preg_match('/^[A-Za-z0-9_-]+$/', $siteDefaultLanguage) !== 1
) {
  exit(7);
}

$activeSettings = $configFactory->get('config_language_lock.settings');
$activeLockedLangcode = $activeSettings->get('locked_langcode');
$activeFollowSiteDefault = $activeSettings->get('follow_site_default');
if ($activeLockedLangcode !== NULL && !is_string($activeLockedLangcode)) {
  exit(8);
}
if ($activeFollowSiteDefault !== NULL && !is_bool($activeFollowSiteDefault)) {
  exit(9);
}

$syncDirectory = Settings::get('config_sync_directory');
if (!is_string($syncDirectory) || $syncDirectory === '') {
  exit(10);
}
$syncCandidate = str_starts_with($syncDirectory, '/')
  ? $syncDirectory
  : $activeDrupalRoot . '/' . $syncDirectory;
$syncRoot = realpath($syncCandidate);
if (
  $syncRoot === FALSE
  || preg_match('#^/var/www/agency(?:/[A-Za-z0-9._-]+)+$#', $syncRoot) !== 1
) {
  exit(11);
}

$syncStorage = new FileStorage($syncRoot);
$syncSettings = $syncStorage->read('config_language_lock.settings');
if (!is_array($syncSettings)) {
  exit(12);
}
$syncLockedLangcode = $syncSettings['locked_langcode'] ?? NULL;
$syncFollowSiteDefault = $syncSettings['follow_site_default'] ?? NULL;
if ($syncLockedLangcode !== NULL && !is_string($syncLockedLangcode)) {
  exit(13);
}
if ($syncFollowSiteDefault !== NULL && !is_bool($syncFollowSiteDefault)) {
  exit(14);
}

$requirementKey = 'config_language_lock_canvas_mismatch';
$requirementSource = 'Drupal\\config_language_lock\\Hook\\ConfigLanguageLockRequirementsHooks::runtimeRequirements';
$requirements = [];
if ($configLanguageLockEnabled) {
  $requirements = \Drupal::service(ConfigLanguageLockRequirementsHooks::class)
    ->runtimeRequirements();
  if (!is_array($requirements)) {
    exit(15);
  }
}
$requirement = $requirements[$requirementKey] ?? NULL;

if (!$canvasEnabled || !$configLanguageLockEnabled) {
  $requirementSeverity = 'NONE';
  $requirementVerdict = 'NOT_APPLICABLE';
  $requirementSummary = !$canvasEnabled
    ? 'Drupal Canvas is not enabled; the requirement is not applicable.'
    : 'Config Language Lock is not enabled; its Canvas requirement is not active.';
}
elseif ($requirement === NULL) {
  $requirementSeverity = 'NONE';
  $requirementVerdict = 'PASS';
  $requirementSummary = 'No Config Language Lock Canvas mismatch requirement is reported.';
}
else {
  if (!is_array($requirement)) {
    exit(16);
  }
  $severity = $requirement['severity'] ?? NULL;
  if (!$severity instanceof RequirementSeverity) {
    exit(17);
  }
  $requirementSeverity = strtoupper($severity->status());
  $requirementVerdict = match ($severity) {
    RequirementSeverity::Error => 'ERROR',
    RequirementSeverity::Warning => 'WARNING',
    RequirementSeverity::OK,
    RequirementSeverity::Info => 'PASS',
  };
  $value = $requirement['value'] ?? '';
  $requirementSummary = trim(strip_tags((string) $value));
  $requirementSummary = preg_replace('/\s+/u', ' ', $requirementSummary) ?? '';
  if ($requirementSummary === '' || strlen($requirementSummary) > 160) {
    exit(18);
  }
}

$languageEvidence = static function (string $id) use ($activeStorage): array {
  $data = $activeStorage->read('language.entity.' . $id);
  $present = is_array($data);
  if (!$present) {
    $data = [];
  }

  $entityId = $data['id'] ?? NULL;
  $langcode = $data['langcode'] ?? NULL;
  $locked = $data['locked'] ?? NULL;
  if ($entityId !== NULL && !is_string($entityId)) {
    exit(19);
  }
  if ($langcode !== NULL && !is_string($langcode)) {
    exit(20);
  }
  if ($locked !== NULL && !is_bool($locked)) {
    exit(21);
  }

  return [
    'present' => $present,
    'id' => $entityId,
    'langcode' => $langcode,
    'locked' => $locked,
  ];
};

$output = [
  'schema_version' => 1,
  'target' => 'PROD',
  'current_release' => $currentRelease,
  'drupal_root' => $activeDrupalRoot,
  'drupal_core_version' => $coreVersion,
  'canvas_enabled' => $canvasEnabled,
  'canvas_version' => $canvasVersion,
  'config_language_lock_enabled' => $configLanguageLockEnabled,
  'config_language_lock_version' => $configLanguageLockVersion,
  'site_default_language' => $siteDefaultLanguage,
  'active_locked_langcode' => $activeLockedLangcode,
  'active_follow_site_default' => $activeFollowSiteDefault,
  'sync_locked_langcode' => $syncLockedLangcode,
  'sync_follow_site_default' => $syncFollowSiteDefault,
  'active_sync_lock_settings_match' =>
    $activeLockedLangcode === $syncLockedLangcode
    && $activeFollowSiteDefault === $syncFollowSiteDefault,
  'canvas_requirement_source' => $requirementSource,
  'canvas_requirement_key' => $requirementKey,
  'canvas_requirement_severity' => $requirementSeverity,
  'canvas_requirement_verdict' => $requirementVerdict,
  'canvas_requirement_summary' => $requirementSummary,
  'languages' => [
    'und' => $languageEvidence('und'),
    'zxx' => $languageEvidence('zxx'),
  ],
  'config_values_exposed' => FALSE,
];

echo json_encode(
  $output,
  JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_THROW_ON_ERROR,
) . PHP_EOL;

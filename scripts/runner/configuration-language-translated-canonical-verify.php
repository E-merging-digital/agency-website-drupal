<?php

declare(strict_types=1);

use Drupal\Core\Config\StorageInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$manifestPath = $projectRoot . '/docs/evidence/configuration-language-translated-canonical-cohort-720.yml';
$mechanicalPath = $projectRoot . '/docs/evidence/configuration-language-mechanical-cohort-713.yml';
if (!is_file($manifestPath) || !is_file($mechanicalPath)) {
  throw new RuntimeException('#720 or #713 canonical evidence is missing.');
}

$manifest = Yaml::parseFile($manifestPath);
$mechanical = Yaml::parseFile($mechanicalPath);
if (!is_array($manifest) || !is_array($mechanical)) {
  throw new RuntimeException('Canonical evidence must be YAML mappings.');
}

$expectedCount = 173;
$expectedHash = '3908bb3b785091d996e969f67af5d0060b3548d2ec732fb055c748085c0d6547';
$expectedFrenchRequired = [
  'block.block.emerging_digital_footer_branding',
  'block.block.emerging_digital_online_presence',
  'core.entity_form_display.node.page.default',
  'llms_txt.settings',
  'metatag.metatag_defaults.front',
  'system.menu.online-presence',
  'webform.webform.contact',
];

if ((int) ($manifest['expected_count'] ?? -1) !== $expectedCount
  || ($manifest['names_sha256'] ?? NULL) !== $expectedHash
  || ($manifest['expected_fr_override_names'] ?? NULL) !== $expectedFrenchRequired
  || (int) ($manifest['material_translatable_leaf_count'] ?? -1) !== 930
  || (int) ($manifest['explicit_en_coverage_count'] ?? -1) !== 930) {
  throw new RuntimeException('#720 manifest contract drifted.');
}

$items = $manifest['items'] ?? NULL;
if (!is_array($items) || count($items) !== $expectedCount) {
  throw new RuntimeException('#720 manifest must contain exactly 173 items.');
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

$syncStorage = \Drupal::service('config.storage.sync');
$activeStorage = \Drupal::service('config.storage');
if (!$syncStorage instanceof StorageInterface || !$activeStorage instanceof StorageInterface) {
  throw new RuntimeException('Configuration storages are unavailable.');
}
$syncEnglish = $syncStorage->createCollection('language.en');
$syncFrench = $syncStorage->createCollection('language.fr');
$activeEnglish = $activeStorage->createCollection('language.en');
$activeFrench = $activeStorage->createCollection('language.fr');

$problems = [];
$verified = [];
$names = [];
$frenchRequiredSeen = [];
foreach ($items as $item) {
  if (!is_array($item) || !is_string($item['name'] ?? NULL)) {
    $problems[] = ['reason' => 'invalid_manifest_item'];
    continue;
  }
  $name = $item['name'];
  if (isset($names[$name])) {
    $problems[] = ['name' => $name, 'reason' => 'duplicate_manifest_name'];
    continue;
  }
  $names[$name] = TRUE;

  $syncBase = $syncStorage->read($name);
  $activeBase = $activeStorage->read($name);
  if (!is_array($syncBase) || !is_array($activeBase)) {
    $problems[] = ['name' => $name, 'reason' => 'canonical_base_missing'];
    continue;
  }
  if (($syncBase['langcode'] ?? NULL) !== 'en' || ($activeBase['langcode'] ?? NULL) !== 'en') {
    $problems[] = ['name' => $name, 'reason' => 'canonical_base_not_en'];
  }
  $expectedBaseFingerprint = $item['base_after_fingerprint'] ?? NULL;
  if (!is_string($expectedBaseFingerprint)
    || $fingerprint($syncBase) !== $expectedBaseFingerprint
    || $fingerprint($activeBase) !== $expectedBaseFingerprint) {
    $problems[] = ['name' => $name, 'reason' => 'canonical_base_fingerprint_mismatch'];
  }
  if ($syncEnglish->exists($name) || $activeEnglish->exists($name)) {
    $problems[] = ['name' => $name, 'reason' => 'obsolete_en_override_still_present'];
  }

  $requiresFrench = ($item['fr_override_required'] ?? FALSE) === TRUE;
  $syncFr = $syncFrench->read($name);
  $activeFr = $activeFrench->read($name);
  if ($requiresFrench) {
    $frenchRequiredSeen[] = $name;
    $expectedFrFingerprint = $item['fr_override_after_fingerprint'] ?? NULL;
    if (!is_string($expectedFrFingerprint)
      || !is_array($syncFr) || !is_array($activeFr)
      || $fingerprint($syncFr) !== $expectedFrFingerprint
      || $fingerprint($activeFr) !== $expectedFrFingerprint) {
      $problems[] = ['name' => $name, 'reason' => 'required_fr_override_mismatch'];
    }
  }
  elseif (($syncFr !== FALSE && $syncFr !== NULL) || ($activeFr !== FALSE && $activeFr !== NULL)) {
    $problems[] = ['name' => $name, 'reason' => 'unexpected_fr_override_present'];
  }

  $verified[] = [
    'name' => $name,
    'base_langcode' => $syncBase['langcode'] ?? NULL,
    'fr_override_required' => $requiresFrench,
    'base_fingerprint' => $fingerprint($syncBase),
  ];
}

$nameList = array_keys($names);
sort($nameList, SORT_STRING);
$actualHash = hash('sha256', implode("\n", $nameList) . "\n");
if (count($nameList) !== $expectedCount || $actualHash !== $expectedHash) {
  $problems[] = ['reason' => 'manifest_identity_mismatch'];
}
sort($frenchRequiredSeen, SORT_STRING);
if ($frenchRequiredSeen !== $expectedFrenchRequired) {
  $problems[] = ['reason' => 'fr_override_requirement_set_mismatch'];
}

foreach (($manifest['preserved_fr_overrides_outside_cohort'] ?? []) as $name => $expectedFingerprint) {
  $syncData = $syncFrench->read((string) $name);
  $activeData = $activeFrench->read((string) $name);
  if (!is_array($syncData) || !is_array($activeData)
    || $fingerprint($syncData) !== $expectedFingerprint
    || $fingerprint($activeData) !== $expectedFingerprint) {
    $problems[] = ['name' => $name, 'reason' => 'preserved_fr_override_changed'];
  }
}
foreach (($manifest['preserved_en_overrides_outside_cohort'] ?? []) as $name => $expectedFingerprint) {
  $syncData = $syncEnglish->read((string) $name);
  $activeData = $activeEnglish->read((string) $name);
  if (!is_array($syncData) || !is_array($activeData)
    || $fingerprint($syncData) !== $expectedFingerprint
    || $fingerprint($activeData) !== $expectedFingerprint) {
    $problems[] = ['name' => $name, 'reason' => 'preserved_en_override_changed'];
  }
}

$mechanicalItems = $mechanical['items'] ?? NULL;
$mechanicalVerified = 0;
if (!is_array($mechanicalItems) || count($mechanicalItems) !== 39) {
  $problems[] = ['reason' => 'mechanical_cohort_713_invalid'];
}
else {
  foreach ($mechanicalItems as $item) {
    $name = is_array($item) ? ($item['name'] ?? NULL) : NULL;
    if (!is_string($name)) {
      $problems[] = ['reason' => 'mechanical_item_invalid'];
      continue;
    }
    $syncData = $syncStorage->read($name);
    $activeData = $activeStorage->read($name);
    if (!is_array($syncData) || !is_array($activeData)
      || ($syncData['langcode'] ?? NULL) !== 'en'
      || ($activeData['langcode'] ?? NULL) !== 'en') {
      $problems[] = ['name' => $name, 'reason' => 'mechanical_715_regressed'];
      continue;
    }
    $mechanicalVerified++;
  }
}

$distribution = ['__none__' => 0];
$remainingReviewRequired = [];
foreach ($syncStorage->listAll() as $name) {
  $data = $syncStorage->read($name);
  if (!is_array($data)) {
    continue;
  }
  $langcode = $data['langcode'] ?? '__none__';
  $distribution[(string) $langcode] = ($distribution[(string) $langcode] ?? 0) + 1;
  if ($langcode === 'fr' && !$syncEnglish->exists($name)) {
    $remainingReviewRequired[] = $name;
  }
}
ksort($distribution, SORT_STRING);
sort($remainingReviewRequired, SORT_STRING);
if (count($remainingReviewRequired) !== 140) {
  $problems[] = [
    'reason' => 'remaining_fr_review_required_count_mismatch',
    'actual' => count($remainingReviewRequired),
  ];
}

$site = $syncStorage->read('system.site');
if (!is_array($site) || ($site['default_langcode'] ?? NULL) !== 'fr') {
  $problems[] = ['reason' => 'site_default_language_not_fr'];
}
foreach (['und', 'zxx'] as $langcode) {
  $language = $syncStorage->read('language.entity.' . $langcode);
  if (!is_array($language)
    || ($language['id'] ?? NULL) !== $langcode
    || ($language['locked'] ?? NULL) !== TRUE) {
    $problems[] = ['name' => 'language.entity.' . $langcode, 'reason' => 'locked_language_semantics_regressed'];
  }
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $status === 'PASS'
    ? 'ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANONICAL_PROMOTION_VERIFIED'
    : 'CANONICAL_TRANSLATED_PROMOTION_INVALID',
  'counts' => [
    'expected' => $expectedCount,
    'verified' => count($verified),
    'fr_overrides_required' => count($frenchRequiredSeen),
    'mechanical_715_verified_en' => $mechanicalVerified,
    'remaining_fr_review_required' => count($remainingReviewRequired),
    'preserved_fr_overrides_outside_cohort' => count($manifest['preserved_fr_overrides_outside_cohort'] ?? []),
    'preserved_en_overrides_outside_cohort' => count($manifest['preserved_en_overrides_outside_cohort'] ?? []),
    'problem_count' => count($problems),
  ],
  'cohort' => [
    'expected_names_sha256' => $expectedHash,
    'actual_names_sha256' => $actualHash,
  ],
  'distribution_by_langcode' => $distribution,
  'remaining_fr_review_required_names' => $remainingReviewRequired,
  'problems' => $problems,
  'constraints' => [
    'read_only' => TRUE,
    'config_language_lock_must_remain_disabled' => TRUE,
    'production_mutation_allowed' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

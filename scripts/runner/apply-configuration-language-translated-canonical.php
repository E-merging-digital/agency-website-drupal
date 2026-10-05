<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$sourceEvidence = $projectRoot . '/docs/evidence/configuration-language-translated-cohort-718.yml';
$manifestPath = $projectRoot . '/docs/evidence/configuration-language-translated-canonical-cohort-720.yml';

if (!is_file($sourceEvidence)) {
  throw new RuntimeException('#718 translated cohort evidence is missing.');
}

$evidence = Yaml::parseFile($sourceEvidence);
if (!is_array($evidence)) {
  throw new RuntimeException('#718 translated cohort evidence must be a mapping.');
}

$expectedCount = 173;
$expectedHash = '3908bb3b785091d996e969f67af5d0060b3548d2ec732fb055c748085c0d6547';
$expectedMaterialLeaves = 930;
$expectedFrenchRequired = [
  'block.block.emerging_digital_footer_branding',
  'block.block.emerging_digital_online_presence',
  'core.entity_form_display.node.page.default',
  'llms_txt.settings',
  'metatag.metatag_defaults.front',
  'system.menu.online-presence',
  'webform.webform.contact',
];
$expectedPreexistingFrench = [
  'block.block.emerging_digital_footer_menu',
  'views.view.blog',
];
$requiredExceptions = [
  'core.entity_form_display.node.page.default',
  'field.storage.node.ai_automator_status',
];

if ((int) ($evidence['expected_count'] ?? -1) !== $expectedCount
  || ($evidence['names_sha256'] ?? NULL) !== $expectedHash
  || ($evidence['required_exception_names'] ?? NULL) !== $requiredExceptions
  || ($evidence['expected_preexisting_fr_overrides_outside_cohort'] ?? NULL) !== $expectedPreexistingFrench) {
  throw new RuntimeException('#718 cohort contract drifted.');
}

$typedConfigManager = \Drupal::service('config.typed');
$syncStorage = \Drupal::service('config.storage.sync');
$activeStorage = \Drupal::service('config.storage');
if (!$syncStorage instanceof StorageInterface || !$activeStorage instanceof StorageInterface) {
  throw new RuntimeException('Configuration storages are unavailable.');
}
$syncEnglish = $syncStorage->createCollection('language.en');
$syncFrench = $syncStorage->createCollection('language.fr');
$activeEnglish = $activeStorage->createCollection('language.en');
$activeFrench = $activeStorage->createCollection('language.fr');

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

$isMaterial = static function (mixed $value): bool {
  if ($value === NULL || $value === []) {
    return FALSE;
  }
  if (is_string($value)) {
    return trim($value) !== '';
  }
  return is_scalar($value);
};

$pathKey = static function (array $segments): string {
  return json_encode($segments, JSON_THROW_ON_ERROR);
};

$displayPath = static function (array $segments): string {
  return implode('.', array_map(
    static fn(string|int $segment): string => (string) $segment,
    $segments,
  ));
};

$collectTranslatableLeaves = NULL;
$collectTranslatableLeaves = static function (
  TypedDataInterface $element,
  array $segments = [],
) use (&$collectTranslatableLeaves, $displayPath, $pathKey): array {
  if ($element instanceof TraversableTypedDataInterface) {
    $leaves = [];
    foreach ($element as $key => $child) {
      if (!$child instanceof TypedDataInterface) {
        continue;
      }
      foreach ($collectTranslatableLeaves($child, [...$segments, $key]) as $leaf) {
        $leaves[] = $leaf;
      }
    }
    return $leaves;
  }

  $definition = $element->getDataDefinition();
  if (!isset($definition['translatable']) || !$definition['translatable']) {
    return [];
  }

  return [[
    'segments' => $segments,
    'key' => $pathKey($segments),
    'path' => $displayPath($segments),
    'value' => $element->getValue(),
  ]];
};

$collectRawLeaves = NULL;
$collectRawLeaves = static function (
  mixed $value,
  array $segments = [],
) use (&$collectRawLeaves, $displayPath, $pathKey): array {
  if (is_array($value) && $value !== []) {
    $leaves = [];
    foreach ($value as $key => $child) {
      foreach ($collectRawLeaves($child, [...$segments, $key]) as $leaf) {
        $leaves[] = $leaf;
      }
    }
    return $leaves;
  }

  return [[
    'segments' => $segments,
    'key' => $pathKey($segments),
    'path' => $displayPath($segments),
    'value' => $value,
  ]];
};

$names = $syncStorage->listAll();
sort($names, SORT_STRING);
$candidateNames = [];
foreach ($names as $name) {
  $data = $syncStorage->read($name);
  if (is_array($data)
    && ($data['langcode'] ?? NULL) === 'fr'
    && $syncEnglish->exists($name)) {
    $candidateNames[] = $name;
  }
}
sort($candidateNames, SORT_STRING);
$candidateHash = hash('sha256', implode("\n", $candidateNames) . "\n");
if (count($candidateNames) !== $expectedCount || $candidateHash !== $expectedHash) {
  throw new RuntimeException('Current translated candidate identity does not match #718.');
}

$currentFrench = $syncFrench->listAll();
sort($currentFrench, SORT_STRING);
if ($currentFrench !== $expectedPreexistingFrench) {
  throw new RuntimeException('Pre-existing FR override set drifted before #720.');
}

$currentEnglish = $syncEnglish->listAll();
sort($currentEnglish, SORT_STRING);
$preservedEnglish = array_values(array_diff($currentEnglish, $candidateNames));
sort($preservedEnglish, SORT_STRING);
$preservedEnglishFingerprints = [];
foreach ($preservedEnglish as $name) {
  $data = $syncEnglish->read($name);
  if (!is_array($data)) {
    throw new RuntimeException("Unable to read preserved EN override $name.");
  }
  $preservedEnglishFingerprints[$name] = $fingerprint($data);
}
$preservedFrenchFingerprints = [];
foreach ($expectedPreexistingFrench as $name) {
  $data = $syncFrench->read($name);
  if (!is_array($data)) {
    throw new RuntimeException("Unable to read preserved FR override $name.");
  }
  $preservedFrenchFingerprints[$name] = $fingerprint($data);
}

$prepared = [];
$totalMaterial = 0;
$totalCovered = 0;
$frenchRequired = [];

foreach ($candidateNames as $name) {
  $baseData = $syncStorage->read($name);
  $englishData = $syncEnglish->read($name);
  $activeBase = $activeStorage->read($name);
  $activeEn = $activeEnglish->read($name);
  if (!is_array($baseData) || !is_array($englishData)
    || !is_array($activeBase) || !is_array($activeEn)) {
    throw new RuntimeException("Missing sync/active source data for $name.");
  }
  if (($baseData['langcode'] ?? NULL) !== 'fr') {
    throw new RuntimeException("Target base is no longer FR: $name");
  }
  if ($fingerprint($baseData) !== $fingerprint($activeBase)
    || $fingerprint($englishData) !== $fingerprint($activeEn)) {
    throw new RuntimeException("Active/sync baseline mismatch for $name.");
  }
  if ($syncFrench->exists($name) || $activeFrench->exists($name)) {
    throw new RuntimeException("Target unexpectedly already has FR override: $name");
  }
  if (!$typedConfigManager->hasConfigSchema($name)) {
    throw new RuntimeException("Typed config schema missing for $name.");
  }

  $typed = $typedConfigManager->createFromNameAndData($name, $baseData);
  $translatableLeaves = $collectTranslatableLeaves($typed);
  $translatableByKey = [];
  foreach ($translatableLeaves as $leaf) {
    $translatableByKey[$leaf['key']] = $leaf;
  }

  $englishLeaves = $collectRawLeaves($englishData);
  $englishByKey = [];
  foreach ($englishLeaves as $leaf) {
    if (!isset($translatableByKey[$leaf['key']])) {
      throw new RuntimeException("EN override contains non-translatable path {$leaf['path']} in $name.");
    }
    $englishByKey[$leaf['key']] = $leaf;
  }

  $materialCount = 0;
  $coveredCount = 0;
  foreach ($translatableLeaves as $leaf) {
    if (!$isMaterial($leaf['value'])) {
      continue;
    }
    $materialCount++;
    if (!isset($englishByKey[$leaf['key']])) {
      throw new RuntimeException("Material translatable path is not covered in $name: {$leaf['path']}");
    }
    $coveredCount++;
  }
  $totalMaterial += $materialCount;
  $totalCovered += $coveredCount;

  $newBase = $baseData;
  $newBase['langcode'] = 'en';
  foreach ($englishLeaves as $leaf) {
    NestedArray::setValue($newBase, $leaf['segments'], $leaf['value'], TRUE);
  }

  $frenchData = [];
  foreach ($translatableLeaves as $leaf) {
    $sourceExists = FALSE;
    $sourceValue = NestedArray::getValue($baseData, $leaf['segments'], $sourceExists);
    $newExists = FALSE;
    $newValue = NestedArray::getValue($newBase, $leaf['segments'], $newExists);
    $beforeFr = $sourceExists ? $sourceValue : NULL;
    $afterEn = $newExists ? $newValue : NULL;
    if ($beforeFr !== $afterEn) {
      NestedArray::setValue($frenchData, $leaf['segments'], $beforeFr, TRUE);
    }
  }

  $requiresFrench = $frenchData !== [];
  if ($requiresFrench) {
    $frenchRequired[] = $name;
  }

  $prepared[$name] = [
    'name' => $name,
    'origin' => in_array($name, $requiredExceptions, TRUE)
      ? 'issue_711_exception'
      : 'issue_707_complete',
    'material_translatable_leaf_count' => $materialCount,
    'explicit_en_coverage_count' => $coveredCount,
    'fr_override_required' => $requiresFrench,
    'base_before_fingerprint' => $fingerprint($baseData),
    'en_override_before_fingerprint' => $fingerprint($englishData),
    'base_after_data' => $newBase,
    'base_after_fingerprint' => $fingerprint($newBase),
    'fr_override_after_data' => $frenchData,
    'fr_override_after_fingerprint' => $requiresFrench ? $fingerprint($frenchData) : NULL,
  ];
}

sort($frenchRequired, SORT_STRING);
if ($totalMaterial !== $expectedMaterialLeaves || $totalCovered !== $expectedMaterialLeaves) {
  throw new RuntimeException('Typed-config aggregate coverage no longer matches trusted #718.');
}
if ($frenchRequired !== $expectedFrenchRequired) {
  throw new RuntimeException('FR override requirement set no longer matches trusted #718.');
}
if (count($prepared) !== $expectedCount) {
  throw new RuntimeException('Prepared translated canonical target count is not 173.');
}

foreach ($prepared as $name => $item) {
  if (!$syncStorage->write($name, $item['base_after_data'])) {
    throw new RuntimeException("Unable to write canonical base $name.");
  }
  if (!$syncEnglish->delete($name)) {
    throw new RuntimeException("Unable to remove obsolete EN override $name.");
  }
  if ($item['fr_override_required']
    && !$syncFrench->write($name, $item['fr_override_after_data'])) {
    throw new RuntimeException("Unable to write FR override $name.");
  }
}

$postEnglish = $syncEnglish->listAll();
sort($postEnglish, SORT_STRING);
if ($postEnglish !== $preservedEnglish) {
  throw new RuntimeException('Unexpected EN override set after canonical promotion.');
}
$postFrench = $syncFrench->listAll();
sort($postFrench, SORT_STRING);
$expectedPostFrench = array_values(array_unique([
  ...$expectedPreexistingFrench,
  ...$expectedFrenchRequired,
]));
sort($expectedPostFrench, SORT_STRING);
if ($postFrench !== $expectedPostFrench) {
  throw new RuntimeException('Unexpected FR override set after canonical promotion.');
}
foreach ($preservedEnglishFingerprints as $name => $expectedFingerprint) {
  if ($fingerprint($syncEnglish->read($name)) !== $expectedFingerprint) {
    throw new RuntimeException("Preserved EN override changed: $name");
  }
}
foreach ($preservedFrenchFingerprints as $name => $expectedFingerprint) {
  if ($fingerprint($syncFrench->read($name)) !== $expectedFingerprint) {
    throw new RuntimeException("Preserved FR override changed: $name");
  }
}

$manifestItems = [];
$basePaths = [];
$englishPaths = [];
$frenchPaths = [];
foreach ($prepared as $name => $item) {
  $base = $syncStorage->read($name);
  if (!is_array($base)
    || ($base['langcode'] ?? NULL) !== 'en'
    || $fingerprint($base) !== $item['base_after_fingerprint']
    || $syncEnglish->exists($name)) {
    throw new RuntimeException("Post-write canonical state mismatch for $name.");
  }
  $fr = $syncFrench->read($name);
  if ($item['fr_override_required']) {
    if (!is_array($fr) || $fingerprint($fr) !== $item['fr_override_after_fingerprint']) {
      throw new RuntimeException("Post-write FR override mismatch for $name.");
    }
  }
  elseif ($fr !== FALSE && $fr !== NULL) {
    throw new RuntimeException("Unexpected post-write FR override for $name.");
  }

  $basePath = 'config/sync/' . $name . '.yml';
  $englishPath = 'config/sync/language/en/' . $name . '.yml';
  $basePaths[] = $basePath;
  $englishPaths[] = $englishPath;
  if ($item['fr_override_required']) {
    $frenchPaths[] = 'config/sync/language/fr/' . $name . '.yml';
  }

  $manifestItems[] = [
    'name' => $name,
    'origin' => $item['origin'],
    'fr_override_required' => $item['fr_override_required'],
    'material_translatable_leaf_count' => $item['material_translatable_leaf_count'],
    'explicit_en_coverage_count' => $item['explicit_en_coverage_count'],
    'base_before_fingerprint' => $item['base_before_fingerprint'],
    'en_override_before_fingerprint' => $item['en_override_before_fingerprint'],
    'base_after_fingerprint' => $item['base_after_fingerprint'],
    'fr_override_after_fingerprint' => $item['fr_override_after_fingerprint'],
  ];
}

$manifest = [
  'schema_version' => 1,
  'issue' => 720,
  'source_issue' => 718,
  'source_trusted_run' => 32661601566,
  'source_artifact_id' => 9498925772,
  'source_artifact_digest' => 'sha256:7159ac6d670e4d0d8c9d1981d7882c7dbc72604db0be56a9f4a966d8aecef990',
  'expected_count' => $expectedCount,
  'names_sha256' => $expectedHash,
  'material_translatable_leaf_count' => $totalMaterial,
  'explicit_en_coverage_count' => $totalCovered,
  'expected_fr_override_names' => $expectedFrenchRequired,
  'preserved_fr_overrides_outside_cohort' => $preservedFrenchFingerprints,
  'preserved_en_overrides_outside_cohort' => $preservedEnglishFingerprints,
  'items' => $manifestItems,
];

$manifestText = Yaml::dump($manifest, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
if (file_put_contents($manifestPath, $manifestText) === FALSE) {
  throw new RuntimeException('Unable to write #720 canonical cohort manifest.');
}

sort($basePaths, SORT_STRING);
sort($englishPaths, SORT_STRING);
sort($frenchPaths, SORT_STRING);
$resultItems = array_map(
  static fn(array $item): array => [
    'name' => $item['name'],
    'fr_override_required' => $item['fr_override_required'],
    'base_after_fingerprint' => $item['base_after_fingerprint'],
    'fr_override_after_fingerprint' => $item['fr_override_after_fingerprint'],
  ],
  $manifestItems,
);

$result = [
  'schema_version' => 1,
  'status' => 'PASS',
  'verdict' => 'ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANONICAL_PATCH_PREPARED',
  'counts' => [
    'expected' => $expectedCount,
    'prepared' => count($resultItems),
    'material_translatable_leaf_count' => $totalMaterial,
    'explicit_en_coverage_count' => $totalCovered,
    'base_paths_modified' => count($basePaths),
    'en_override_paths_deleted' => count($englishPaths),
    'fr_override_paths_created' => count($frenchPaths),
    'config_paths_changed' => count($basePaths) + count($englishPaths) + count($frenchPaths),
    'problem_count' => 0,
  ],
  'cohort' => [
    'names_sha256' => $candidateHash,
    'fr_override_required_names' => $frenchRequired,
  ],
  'paths' => [
    'base' => $basePaths,
    'en_deleted' => $englishPaths,
    'fr_created' => $frenchPaths,
    'manifest' => 'docs/evidence/configuration-language-translated-canonical-cohort-720.yml',
  ],
  'items' => $resultItems,
  'problems' => [],
  'constraints' => [
    'drupal_sync_storage_used' => TRUE,
    'config_export_used' => FALSE,
    'global_yaml_rewrite_allowed' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_mutation_allowed' => FALSE,
    'editorial_semantic_without_en_override_in_scope' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

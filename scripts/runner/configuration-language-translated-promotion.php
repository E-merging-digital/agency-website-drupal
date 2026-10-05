<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$cohortPath = $projectRoot . '/docs/evidence/configuration-language-translated-cohort-718.yml';

if (!is_dir($configDirectory) || !is_file($cohortPath)) {
  throw new RuntimeException('Configuration sync or #718 cohort evidence is missing.');
}

$cohort = Yaml::parseFile($cohortPath);
if (!is_array($cohort)) {
  throw new RuntimeException('#718 cohort evidence must be a mapping.');
}

$expectedCount = 173;
$expectedHash = '3908bb3b785091d996e969f67af5d0060b3548d2ec732fb055c748085c0d6547';
$requiredExceptions = [
  'core.entity_form_display.node.page.default',
  'field.storage.node.ai_automator_status',
];
$expectedPreexistingFrenchOverrides = [
  'block.block.emerging_digital_footer_menu',
  'views.view.blog',
];

if ((int) ($cohort['expected_count'] ?? -1) !== $expectedCount) {
  throw new RuntimeException('Unexpected #718 cohort count.');
}
if (($cohort['names_sha256'] ?? NULL) !== $expectedHash) {
  throw new RuntimeException('Unexpected #718 cohort identity hash.');
}
if (($cohort['required_exception_names'] ?? NULL) !== $requiredExceptions) {
  throw new RuntimeException('Unexpected #718 required exception set.');
}
if (($cohort['expected_preexisting_fr_overrides_outside_cohort'] ?? NULL) !== $expectedPreexistingFrenchOverrides) {
  throw new RuntimeException('Unexpected #718 pre-existing FR override set.');
}

$configFactory = \Drupal::service('config.factory');
$typedConfigManager = \Drupal::service('config.typed');
$overrideFactory = \Drupal::service('language.config_factory_override');
$activeStorage = \Drupal::service('config.storage');
if (!$activeStorage instanceof StorageInterface) {
  throw new RuntimeException('Active configuration storage is unavailable.');
}

/**
 * Normalizes mappings recursively while preserving list order.
 */
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

/**
 * Hashes normalized data.
 */
$fingerprint = static function (mixed $value) use ($normalize): string {
  return hash('sha256', json_encode(
    $normalize($value),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  ));
};

/**
 * Returns whether a source value is materially present.
 */
$isMaterial = static function (mixed $value): bool {
  if ($value === NULL || $value === []) {
    return FALSE;
  }
  if (is_string($value)) {
    return trim($value) !== '';
  }
  return is_scalar($value);
};

/**
 * Stable path key preserving numeric indexes.
 */
$pathKey = static function (array $segments): string {
  return json_encode($segments, JSON_THROW_ON_ERROR);
};

/**
 * Human-readable typed-config path.
 */
$displayPath = static function (array $segments): string {
  return implode('.', array_map(
    static fn(string|int $segment): string => (string) $segment,
    $segments,
  ));
};

/**
 * Collects all schema-backed translatable leaves.
 */
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

/**
 * Collects raw scalar leaves from an override.
 */
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

/**
 * Captures all raw objects from one storage collection.
 */
$captureStorage = static function (StorageInterface $storage): array {
  $names = $storage->listAll();
  sort($names, SORT_STRING);
  if ($names === []) {
    return [];
  }
  $multiple = $storage->readMultiple($names);
  $captured = [];
  foreach ($names as $name) {
    $data = $multiple[$name] ?? NULL;
    if (!is_array($data)) {
      throw new RuntimeException("Unable to capture configuration $name.");
    }
    $captured[$name] = $data;
  }
  return $captured;
};

/**
 * Captures all non-default collections.
 */
$captureCollections = static function (
  StorageInterface $storage,
  callable $captureStorage,
): array {
  $names = $storage->getAllCollectionNames();
  sort($names, SORT_STRING);
  $collections = [];
  foreach ($names as $name) {
    $collections[$name] = $captureStorage($storage->createCollection($name));
  }
  return $collections;
};

/**
 * Returns names whose normalized raw data changed.
 */
$changedNames = static function (
  array $before,
  array $after,
  callable $fingerprint,
): array {
  $names = array_values(array_unique([...array_keys($before), ...array_keys($after)]));
  sort($names, SORT_STRING);
  $changed = [];
  foreach ($names as $name) {
    if (!array_key_exists($name, $before) || !array_key_exists($name, $after)) {
      $changed[] = $name;
      continue;
    }
    if ($fingerprint($before[$name]) !== $fingerprint($after[$name])) {
      $changed[] = $name;
    }
  }
  return $changed;
};

/**
 * Enumerates repository base-FR configs that carry an EN override file.
 */
$discoverCandidates = static function (string $configDirectory): array {
  $baseFiles = glob($configDirectory . '/*.yml');
  if ($baseFiles === FALSE) {
    throw new RuntimeException('Unable to enumerate repository configuration.');
  }
  $names = [];
  foreach ($baseFiles as $path) {
    $data = Yaml::parseFile($path);
    if (!is_array($data) || ($data['langcode'] ?? NULL) !== 'fr') {
      continue;
    }
    $name = basename($path, '.yml');
    if (is_file($configDirectory . '/language/en/' . $name . '.yml')) {
      $names[] = $name;
    }
  }
  sort($names, SORT_STRING);
  return $names;
};

/**
 * Enumerates repository FR override names.
 */
$discoverFrenchOverrides = static function (string $configDirectory): array {
  $files = glob($configDirectory . '/language/fr/*.yml');
  if ($files === FALSE) {
    throw new RuntimeException('Unable to enumerate repository FR overrides.');
  }
  $names = array_map(
    static fn(string $path): string => basename($path, '.yml'),
    $files,
  );
  sort($names, SORT_STRING);
  return $names;
};

$candidateNames = $discoverCandidates($configDirectory);
$candidateHash = hash('sha256', implode("\n", $candidateNames) . "\n");
$repositoryFrenchOverrides = $discoverFrenchOverrides($configDirectory);

$problems = [];
if (count($candidateNames) !== $expectedCount) {
  $problems[] = [
    'phase' => 'identity',
    'reason' => 'candidate_count_mismatch',
    'expected' => $expectedCount,
    'actual' => count($candidateNames),
  ];
}
if ($candidateHash !== $expectedHash) {
  $problems[] = [
    'phase' => 'identity',
    'reason' => 'candidate_identity_hash_mismatch',
    'expected' => $expectedHash,
    'actual' => $candidateHash,
  ];
}
foreach ($requiredExceptions as $name) {
  if (!in_array($name, $candidateNames, TRUE)) {
    $problems[] = [
      'phase' => 'identity',
      'name' => $name,
      'reason' => 'required_exception_missing',
    ];
  }
}
if ($repositoryFrenchOverrides !== $expectedPreexistingFrenchOverrides) {
  $problems[] = [
    'phase' => 'identity',
    'reason' => 'preexisting_fr_override_set_drifted',
    'expected' => $expectedPreexistingFrenchOverrides,
    'actual' => $repositoryFrenchOverrides,
  ];
}
if (array_intersect($candidateNames, $repositoryFrenchOverrides) !== []) {
  $problems[] = [
    'phase' => 'identity',
    'reason' => 'candidate_already_has_fr_override',
    'names' => array_values(array_intersect($candidateNames, $repositoryFrenchOverrides)),
  ];
}

$classificationItems = [];
$totalMaterialLeaves = 0;
$totalExplicitCoverage = 0;

if ($problems === []) {
  foreach ($candidateNames as $name) {
    $baseConfig = $configFactory->getEditable($name);
    $baseData = $baseConfig->get();
    $englishOverride = $overrideFactory->getOverride('en', $name);
    $frenchOverride = $overrideFactory->getOverride('fr', $name);

    if ($baseConfig->isNew() || ($baseData['langcode'] ?? NULL) !== 'fr') {
      $problems[] = [
        'phase' => 'preflight',
        'name' => $name,
        'reason' => $baseConfig->isNew() ? 'active_base_missing' : 'active_base_not_fr',
      ];
      continue;
    }
    if ($englishOverride->isNew()) {
      $problems[] = [
        'phase' => 'preflight',
        'name' => $name,
        'reason' => 'active_en_override_missing',
      ];
      continue;
    }
    if (!$frenchOverride->isNew()) {
      $problems[] = [
        'phase' => 'preflight',
        'name' => $name,
        'reason' => 'active_fr_override_already_exists',
      ];
      continue;
    }
    if (!$typedConfigManager->hasConfigSchema($name)) {
      $problems[] = [
        'phase' => 'preflight',
        'name' => $name,
        'reason' => 'config_schema_missing',
      ];
      continue;
    }

    try {
      $typedSource = $typedConfigManager->createFromNameAndData($name, $baseData);
      $translatableLeaves = $collectTranslatableLeaves($typedSource);
    }
    catch (Throwable $exception) {
      $problems[] = [
        'phase' => 'preflight',
        'name' => $name,
        'reason' => 'typed_config_traversal_failed',
        'error_class' => $exception::class,
        'message' => $exception->getMessage(),
      ];
      continue;
    }

    $translatableByKey = [];
    foreach ($translatableLeaves as $leaf) {
      $translatableByKey[$leaf['key']] = $leaf;
    }

    $englishData = $englishOverride->get();
    $englishLeaves = $collectRawLeaves($englishData);
    $englishByKey = [];
    $unexpectedPaths = [];
    foreach ($englishLeaves as $leaf) {
      $englishByKey[$leaf['key']] = $leaf;
      if (!isset($translatableByKey[$leaf['key']])) {
        $unexpectedPaths[] = $leaf['path'];
      }
    }

    $materialPaths = [];
    $missingPaths = [];
    foreach ($translatableLeaves as $leaf) {
      if (!$isMaterial($leaf['value'])) {
        continue;
      }
      $materialPaths[] = $leaf['path'];
      if (!isset($englishByKey[$leaf['key']])) {
        $missingPaths[] = $leaf['path'];
      }
    }
    sort($materialPaths, SORT_STRING);
    sort($missingPaths, SORT_STRING);
    sort($unexpectedPaths, SORT_STRING);

    if ($missingPaths !== []) {
      $problems[] = [
        'phase' => 'preflight',
        'name' => $name,
        'reason' => 'material_translatable_source_not_covered',
        'paths' => $missingPaths,
      ];
    }
    if ($unexpectedPaths !== []) {
      $problems[] = [
        'phase' => 'preflight',
        'name' => $name,
        'reason' => 'en_override_contains_non_translatable_or_unresolved_path',
        'paths' => $unexpectedPaths,
      ];
    }

    $totalMaterialLeaves += count($materialPaths);
    $totalExplicitCoverage += count($materialPaths) - count($missingPaths);
    $classificationItems[$name] = [
      'name' => $name,
      'classification' => $missingPaths === [] && $unexpectedPaths === []
        ? 'complete_for_promotion'
        : 'review_required',
      'material_translatable_leaf_count' => count($materialPaths),
      'explicit_en_coverage_count' => count($materialPaths) - count($missingPaths),
      'explicit_en_leaf_count' => count($englishLeaves),
      'translatable_leaves' => $translatableLeaves,
      'english_leaves' => $englishLeaves,
    ];
  }
}

$baselineDefault = $captureStorage($activeStorage);
$baselineCollections = $captureCollections($activeStorage, $captureStorage);
$baselineFingerprint = $fingerprint([
  'default' => $baselineDefault,
  'collections' => $baselineCollections,
]);

$migratedDefault = $baselineDefault;
$migratedCollections = $baselineCollections;
$finalDefault = $baselineDefault;
$finalCollections = $baselineCollections;
$expectedMigratedDefault = $baselineDefault;
$expectedMigratedCollections = $baselineCollections;
$promotionItems = [];
$touchedNames = [];

try {
  if ($problems === [] && count($classificationItems) === $expectedCount) {
    if (!isset($expectedMigratedCollections['language.en'])) {
      throw new RuntimeException('Active language.en collection is missing.');
    }
    if (!isset($expectedMigratedCollections['language.fr'])) {
      $expectedMigratedCollections['language.fr'] = [];
    }

    foreach ($candidateNames as $name) {
      $item = $classificationItems[$name];
      $baseData = $baselineDefault[$name] ?? NULL;
      $englishData = $baselineCollections['language.en'][$name] ?? NULL;
      if (!is_array($baseData) || !is_array($englishData)) {
        throw new RuntimeException("Baseline data is missing for $name.");
      }

      $newBase = $baseData;
      $newBase['langcode'] = 'en';
      foreach ($item['english_leaves'] as $leaf) {
        NestedArray::setValue($newBase, $leaf['segments'], $leaf['value'], TRUE);
      }

      $frenchData = [];
      $effectiveFrChecks = [];
      $effectiveEnChecks = [];
      foreach ($item['translatable_leaves'] as $leaf) {
        $sourceExists = FALSE;
        $sourceValue = NestedArray::getValue($baseData, $leaf['segments'], $sourceExists);
        $englishExists = FALSE;
        $englishValue = NestedArray::getValue($englishData, $leaf['segments'], $englishExists);
        $newBaseExists = FALSE;
        $newBaseValue = NestedArray::getValue($newBase, $leaf['segments'], $newBaseExists);

        $beforeFr = $sourceExists ? $sourceValue : NULL;
        $beforeEn = $englishExists ? $englishValue : $beforeFr;
        $afterEn = $newBaseExists ? $newBaseValue : NULL;

        if ($beforeFr !== $afterEn) {
          NestedArray::setValue($frenchData, $leaf['segments'], $beforeFr, TRUE);
        }

        $effectiveFrChecks[$leaf['key']] = [
          'path' => $leaf['path'],
          'expected' => $beforeFr,
        ];
        $effectiveEnChecks[$leaf['key']] = [
          'path' => $leaf['path'],
          'expected' => $beforeEn,
        ];
      }

      $configFactory->getEditable($name)->setData($newBase)->save();
      $overrideFactory->getOverride('en', $name)->delete();
      if ($frenchData !== []) {
        $overrideFactory->getOverride('fr', $name)->setData($frenchData)->save();
      }
      $touchedNames[] = $name;

      $expectedMigratedDefault[$name] = $newBase;
      unset($expectedMigratedCollections['language.en'][$name]);
      if ($frenchData !== []) {
        $expectedMigratedCollections['language.fr'][$name] = $frenchData;
      }
      else {
        unset($expectedMigratedCollections['language.fr'][$name]);
      }

      $promotionItems[$name] = [
        'name' => $name,
        'origin' => in_array($name, $requiredExceptions, TRUE)
          ? 'issue_711_exception'
          : 'issue_707_complete',
        'material_translatable_leaf_count' => $item['material_translatable_leaf_count'],
        'explicit_en_leaf_count' => $item['explicit_en_leaf_count'],
        'fr_override_required' => $frenchData !== [],
        'fr_override_leaf_count' => count($collectRawLeaves($frenchData)),
        'effective_fr_checks' => $effectiveFrChecks,
        'effective_en_checks' => $effectiveEnChecks,
      ];
    }

    $migratedDefault = $captureStorage($activeStorage);
    $migratedCollections = $captureCollections($activeStorage, $captureStorage);

    if ($fingerprint($migratedDefault) !== $fingerprint($expectedMigratedDefault)) {
      $problems[] = [
        'phase' => 'promotion_validation',
        'reason' => 'default_collection_does_not_match_expected_promotion',
        'changed_names' => $changedNames($baselineDefault, $migratedDefault, $fingerprint),
      ];
    }
    if ($fingerprint($migratedCollections) !== $fingerprint($expectedMigratedCollections)) {
      $problems[] = [
        'phase' => 'promotion_validation',
        'reason' => 'language_collections_do_not_match_expected_promotion',
      ];
    }

    foreach ($candidateNames as $name) {
      $promotedBase = $migratedDefault[$name] ?? NULL;
      $promotedFrench = $migratedCollections['language.fr'][$name] ?? [];
      $promotedEnglish = $migratedCollections['language.en'][$name] ?? NULL;
      if (!is_array($promotedBase) || ($promotedBase['langcode'] ?? NULL) !== 'en') {
        $problems[] = [
          'phase' => 'promotion_validation',
          'name' => $name,
          'reason' => 'base_not_promoted_to_en',
        ];
        continue;
      }
      if ($promotedEnglish !== NULL) {
        $problems[] = [
          'phase' => 'promotion_validation',
          'name' => $name,
          'reason' => 'en_override_not_removed',
        ];
      }

      foreach ($promotionItems[$name]['effective_fr_checks'] as $check) {
        $segments = json_decode(array_search($check, $promotionItems[$name]['effective_fr_checks'], TRUE) ?: '[]', TRUE);
        if (!is_array($segments)) {
          continue;
        }
        $baseExists = FALSE;
        $baseValue = NestedArray::getValue($promotedBase, $segments, $baseExists);
        $frExists = FALSE;
        $frValue = NestedArray::getValue($promotedFrench, $segments, $frExists);
        $actual = $frExists ? $frValue : ($baseExists ? $baseValue : NULL);
        if ($actual !== $check['expected']) {
          $problems[] = [
            'phase' => 'promotion_validation',
            'name' => $name,
            'reason' => 'effective_fr_value_changed',
            'path' => $check['path'],
          ];
        }
      }
      foreach ($promotionItems[$name]['effective_en_checks'] as $check) {
        $segments = json_decode(array_search($check, $promotionItems[$name]['effective_en_checks'], TRUE) ?: '[]', TRUE);
        if (!is_array($segments)) {
          continue;
        }
        $exists = FALSE;
        $actual = NestedArray::getValue($promotedBase, $segments, $exists);
        $actual = $exists ? $actual : NULL;
        if ($actual !== $check['expected']) {
          $problems[] = [
            'phase' => 'promotion_validation',
            'name' => $name,
            'reason' => 'effective_en_value_changed',
            'path' => $check['path'],
          ];
        }
      }
    }
  }
}
catch (Throwable $exception) {
  $problems[] = [
    'phase' => 'promotion',
    'reason' => 'promotion_execution_failed',
    'error_class' => $exception::class,
    'message' => $exception->getMessage(),
  ];
}
finally {
  foreach (array_reverse($touchedNames) as $name) {
    try {
      $base = $baselineDefault[$name] ?? NULL;
      $english = $baselineCollections['language.en'][$name] ?? NULL;
      if (!is_array($base) || !is_array($english)) {
        throw new RuntimeException("Rollback baseline missing for $name.");
      }
      $configFactory->getEditable($name)->setData($base)->save();
      $overrideFactory->getOverride('en', $name)->setData($english)->save();
      $fr = $overrideFactory->getOverride('fr', $name);
      if (!$fr->isNew()) {
        $fr->delete();
      }
    }
    catch (Throwable $exception) {
      $problems[] = [
        'phase' => 'rollback',
        'name' => $name,
        'reason' => 'rollback_failed',
        'error_class' => $exception::class,
        'message' => $exception->getMessage(),
      ];
    }
  }
}

$finalDefault = $captureStorage($activeStorage);
$finalCollections = $captureCollections($activeStorage, $captureStorage);
$finalFingerprint = $fingerprint([
  'default' => $finalDefault,
  'collections' => $finalCollections,
]);
if ($finalFingerprint !== $baselineFingerprint) {
  $problems[] = [
    'phase' => 'rollback_validation',
    'reason' => 'final_configuration_fingerprint_mismatch',
    'baseline' => $baselineFingerprint,
    'final' => $finalFingerprint,
  ];
}

$changedDuringPromotion = $changedNames($baselineDefault, $migratedDefault, $fingerprint);
$unexpectedDefaultChanges = array_values(array_diff($changedDuringPromotion, $candidateNames));
sort($unexpectedDefaultChanges, SORT_STRING);
$frOverrideCreated = 0;
foreach ($promotionItems as $item) {
  if ($item['fr_override_required']) {
    $frOverrideCreated++;
  }
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANDIDATES_PROMOTION_ROLLBACK_PROVEN'
  : 'TRANSLATED_PROMOTION_ROLLBACK_NOT_PROVEN';

$resultItems = [];
foreach ($candidateNames as $name) {
  if (!isset($classificationItems[$name])) {
    continue;
  }
  $resultItems[] = [
    'name' => $name,
    'origin' => in_array($name, $requiredExceptions, TRUE)
      ? 'issue_711_exception'
      : 'issue_707_complete',
    'classification' => $classificationItems[$name]['classification'],
    'material_translatable_leaf_count' => $classificationItems[$name]['material_translatable_leaf_count'],
    'explicit_en_coverage_count' => $classificationItems[$name]['explicit_en_coverage_count'],
    'explicit_en_leaf_count' => $classificationItems[$name]['explicit_en_leaf_count'],
    'promoted' => isset($promotionItems[$name]),
    'fr_override_required' => $promotionItems[$name]['fr_override_required'] ?? NULL,
  ];
}

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'cohort' => [
    'expected_count' => $expectedCount,
    'actual_count' => count($candidateNames),
    'expected_names_sha256' => $expectedHash,
    'actual_names_sha256' => $candidateHash,
    'required_exception_names' => $requiredExceptions,
    'preexisting_fr_overrides_outside_cohort' => $repositoryFrenchOverrides,
  ],
  'counts' => [
    'cohort_classified' => count($classificationItems),
    'material_translatable_leaf_count' => $totalMaterialLeaves,
    'explicit_en_coverage_count' => $totalExplicitCoverage,
    'promoted' => count($promotionItems),
    'en_overrides_removed' => count($promotionItems),
    'fr_overrides_created' => $frOverrideCreated,
    'unexpected_default_mutation_count' => count($unexpectedDefaultChanges),
    'rollback_restored' => $finalFingerprint === $baselineFingerprint ? count($touchedNames) : 0,
    'problem_count' => count($problems),
  ],
  'baseline_fingerprint' => $baselineFingerprint,
  'final_fingerprint' => $finalFingerprint,
  'unexpected_default_mutations' => $unexpectedDefaultChanges,
  'problems' => $problems,
  'items' => $resultItems,
  'constraints' => [
    'active_config_only' => TRUE,
    'repository_config_mutation_allowed_by_this_proof' => FALSE,
    'canonical_translated_migration_allowed_by_this_proof' => FALSE,
    'bulk_langcode_replacement_allowed' => FALSE,
    'config_language_lock_activation_allowed_by_this_proof' => FALSE,
    'config_export_allowed_by_this_proof' => FALSE,
    'production_mutation_allowed_by_this_proof' => FALSE,
    'editorial_semantic_without_en_override_in_scope' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

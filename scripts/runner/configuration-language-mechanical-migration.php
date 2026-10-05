<?php

declare(strict_types=1);

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$cohortPath = $projectRoot . '/docs/evidence/configuration-language-mechanical-cohort-713.yml';

if (!is_dir($configDirectory) || !is_file($cohortPath)) {
  throw new RuntimeException('Configuration sync or #713 cohort evidence is missing.');
}

$cohort = Yaml::parseFile($cohortPath);
if (!is_array($cohort)) {
  throw new RuntimeException('Mechanical cohort evidence must be a mapping.');
}

$expectedCount = 39;
$expectedByType = [
  'entity_form_display' => 10,
  'entity_view_display' => 10,
  'field_storage_config' => 5,
  'language_content_settings' => 14,
];
$excluded = [
  'core.entity_form_display.node.page.default',
  'field.storage.node.ai_automator_status',
];

if ((int) ($cohort['expected_count'] ?? -1) !== $expectedCount) {
  throw new RuntimeException('Unexpected #713 cohort count.');
}
if (($cohort['expected_by_type'] ?? NULL) !== $expectedByType) {
  throw new RuntimeException('Unexpected #713 cohort type counts.');
}
if (($cohort['excluded_review_required'] ?? NULL) !== $excluded) {
  throw new RuntimeException('Unexpected #713 excluded exception set.');
}

$items = $cohort['items'] ?? NULL;
if (!is_array($items) || count($items) !== $expectedCount) {
  throw new RuntimeException('The #713 cohort must contain exactly 39 items.');
}

$cohortByName = [];
$typeCounts = array_fill_keys(array_keys($expectedByType), 0);
foreach ($items as $item) {
  if (!is_array($item)) {
    throw new RuntimeException('Each #713 cohort item must be a mapping.');
  }
  $name = $item['name'] ?? NULL;
  $type = $item['type'] ?? NULL;
  if (!is_string($name) || !is_string($type) || !array_key_exists($type, $expectedByType)) {
    throw new RuntimeException('Invalid #713 cohort item.');
  }
  if (isset($cohortByName[$name]) || in_array($name, $excluded, TRUE)) {
    throw new RuntimeException('Duplicate or excluded #713 cohort item.');
  }
  $cohortByName[$name] = $type;
  $typeCounts[$type]++;
}
ksort($cohortByName, SORT_STRING);
if ($typeCounts !== $expectedByType) {
  throw new RuntimeException('The #713 cohort type distribution drifted.');
}

$typedConfigManager = \Drupal::service('config.typed');
$configFactory = \Drupal::service('config.factory');
$configManager = \Drupal::service('config.manager');
$entityTypeManager = \Drupal::entityTypeManager();
$activeStorage = \Drupal::service('config.storage');

if (!$activeStorage instanceof StorageInterface) {
  throw new RuntimeException('Active configuration storage is unavailable.');
}

/**
 * Renders a path for machine-readable evidence.
 */
$displayPath = static function (array $segments): string {
  return implode('.', array_map(
    static fn(string|int $segment): string => (string) $segment,
    $segments,
  ));
};

/**
 * Collects schema-backed translatable leaves from one config object.
 */
$collectTranslatableLeaves = NULL;
$collectTranslatableLeaves = static function (
  TypedDataInterface $element,
  array $segments = [],
) use (&$collectTranslatableLeaves, $displayPath): array {
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
    'path' => $displayPath($segments),
    'value' => $element->getValue(),
  ]];
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
 * Recursively normalizes mappings while preserving list order.
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
 * Hashes normalized configuration data.
 */
$fingerprint = static function (array $data) use ($normalize): string {
  return hash('sha256', json_encode(
    $normalize($data),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  ));
};

/**
 * Removes only the source-language metadata before semantic comparison.
 */
$withoutLangcode = static function (array $data): array {
  unset($data['langcode']);
  return $data;
};

/**
 * Captures all raw objects from one configuration collection.
 */
$captureStorage = static function (StorageInterface $storage): array {
  $names = $storage->listAll();
  sort($names, SORT_STRING);
  $captured = [];
  if ($names === []) {
    return $captured;
  }
  $multiple = $storage->readMultiple($names);
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
 * Captures every non-default collection, including language overrides.
 */
$captureCollections = static function (
  StorageInterface $storage,
  callable $captureStorage,
): array {
  $collectionNames = $storage->getAllCollectionNames();
  sort($collectionNames, SORT_STRING);
  $collections = [];
  foreach ($collectionNames as $collectionName) {
    $collections[$collectionName] = $captureStorage(
      $storage->createCollection($collectionName),
    );
  }
  return $collections;
};

/**
 * Returns normalized changed configuration names between snapshots.
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
    $beforeData = $before[$name] ?? NULL;
    $afterData = $after[$name] ?? NULL;
    if (!is_array($beforeData) || !is_array($afterData)) {
      $changed[] = $name;
      continue;
    }
    if ($fingerprint($beforeData) !== $fingerprint($afterData)) {
      $changed[] = $name;
    }
  }
  return $changed;
};

/**
 * Resolves a config name to its real Drupal config entity.
 */
$loadConfigEntity = static function (string $name) use (
  $configManager,
  $entityTypeManager,
): ConfigEntityInterface {
  $entityTypeId = $configManager->getEntityTypeIdByName($name);
  if (!is_string($entityTypeId) || $entityTypeId === '') {
    throw new RuntimeException("No config entity type resolves $name.");
  }
  $definition = $entityTypeManager->getDefinition($entityTypeId);
  $prefix = $definition->getConfigPrefix();
  if (!str_starts_with($name, $prefix . '.')) {
    throw new RuntimeException("Config prefix mismatch for $name.");
  }
  $id = substr($name, strlen($prefix) + 1);
  $entity = $entityTypeManager->getStorage($entityTypeId)->load($id);
  if (!$entity instanceof ConfigEntityInterface) {
    throw new RuntimeException("Unable to load config entity $name.");
  }
  return $entity;
};

$problems = [];
$classificationItems = [];
foreach ($cohortByName as $name => $expectedType) {
  if (is_file($configDirectory . '/language/en/' . $name . '.yml')) {
    $problems[] = [
      'name' => $name,
      'phase' => 'preflight',
      'reason' => 'unexpected_en_override_file',
    ];
    continue;
  }

  $sourceConfig = $configFactory->getEditable($name);
  $sourceData = $sourceConfig->get();
  if ($sourceConfig->isNew() || ($sourceData['langcode'] ?? NULL) !== 'fr') {
    $problems[] = [
      'name' => $name,
      'phase' => 'preflight',
      'reason' => $sourceConfig->isNew()
        ? 'active_config_missing'
        : 'active_source_langcode_not_fr',
    ];
    continue;
  }

  $entityTypeId = $configManager->getEntityTypeIdByName($name);
  if ($entityTypeId !== $expectedType) {
    $problems[] = [
      'name' => $name,
      'phase' => 'preflight',
      'reason' => 'entity_type_mismatch',
      'expected' => $expectedType,
      'actual' => $entityTypeId,
    ];
    continue;
  }

  if (!$typedConfigManager->hasConfigSchema($name)) {
    $problems[] = [
      'name' => $name,
      'phase' => 'preflight',
      'reason' => 'config_schema_missing',
    ];
    continue;
  }

  try {
    $typedSource = $typedConfigManager->createFromNameAndData($name, $sourceData);
    $allTranslatableLeaves = $collectTranslatableLeaves($typedSource);
  }
  catch (Throwable $exception) {
    $problems[] = [
      'name' => $name,
      'phase' => 'preflight',
      'reason' => 'typed_config_traversal_failed',
      'error_class' => $exception::class,
    ];
    continue;
  }

  $materialPaths = [];
  foreach ($allTranslatableLeaves as $leaf) {
    if ($isMaterial($leaf['value'])) {
      $materialPaths[] = $leaf['path'];
    }
  }
  sort($materialPaths, SORT_STRING);
  if ($materialPaths !== []) {
    $problems[] = [
      'name' => $name,
      'phase' => 'preflight',
      'reason' => 'material_translatable_source_detected',
      'paths' => $materialPaths,
    ];
  }

  $classificationItems[$name] = [
    'name' => $name,
    'type' => $expectedType,
    'material_translatable_leaf_count' => count($materialPaths),
  ];
}

$baselineDefault = $captureStorage($activeStorage);
$baselineCollections = $captureCollections($activeStorage, $captureStorage);
$migratedDefault = $baselineDefault;
$migratedCollections = $baselineCollections;
$finalDefault = $baselineDefault;
$finalCollections = $baselineCollections;
$migratedNames = [];
$migrationItems = [];

if ($problems === [] && count($classificationItems) === $expectedCount) {
  foreach (array_keys($cohortByName) as $name) {
    try {
      $entity = $loadConfigEntity($name);
      $entity->set('langcode', 'en');
      $entity->save();
      $migratedNames[] = $name;
    }
    catch (Throwable $exception) {
      $problems[] = [
        'name' => $name,
        'phase' => 'migration',
        'reason' => 'config_entity_save_failed',
        'error_class' => $exception::class,
        'message' => $exception->getMessage(),
      ];
      break;
    }
  }

  $migratedDefault = $captureStorage($activeStorage);
  $migratedCollections = $captureCollections($activeStorage, $captureStorage);
  $changedDuringMigration = $changedNames($baselineDefault, $migratedDefault, $fingerprint);
  $expectedChanged = array_keys($cohortByName);
  sort($expectedChanged, SORT_STRING);

  foreach ($expectedChanged as $name) {
    $before = $baselineDefault[$name] ?? NULL;
    $after = $migratedDefault[$name] ?? NULL;
    if (!is_array($before) || !is_array($after)) {
      $problems[] = [
        'name' => $name,
        'phase' => 'migration_validation',
        'reason' => 'target_missing_from_snapshot',
      ];
      continue;
    }

    $semanticBefore = $withoutLangcode($before);
    $semanticAfter = $withoutLangcode($after);
    $semanticPreserved = $fingerprint($semanticBefore) === $fingerprint($semanticAfter);
    $langcodeMigrated = ($before['langcode'] ?? NULL) === 'fr'
      && ($after['langcode'] ?? NULL) === 'en';

    if (!$semanticPreserved) {
      $problems[] = [
        'name' => $name,
        'phase' => 'migration_validation',
        'reason' => 'non_langcode_value_changed',
      ];
    }
    if (!$langcodeMigrated) {
      $problems[] = [
        'name' => $name,
        'phase' => 'migration_validation',
        'reason' => 'langcode_not_migrated_fr_to_en',
        'before' => $before['langcode'] ?? NULL,
        'after' => $after['langcode'] ?? NULL,
      ];
    }

    $migrationItems[$name] = [
      ...$classificationItems[$name],
      'before_langcode' => $before['langcode'] ?? NULL,
      'migrated_langcode' => $after['langcode'] ?? NULL,
      'semantic_fingerprint_before' => $fingerprint($semanticBefore),
      'semantic_fingerprint_migrated' => $fingerprint($semanticAfter),
      'semantic_preserved' => $semanticPreserved,
    ];
  }

  $unexpectedChanged = array_values(array_diff($changedDuringMigration, $expectedChanged));
  sort($unexpectedChanged, SORT_STRING);
  if ($unexpectedChanged !== []) {
    $problems[] = [
      'phase' => 'migration_validation',
      'reason' => 'unexpected_active_config_mutation',
      'names' => $unexpectedChanged,
    ];
  }
  $missingChanged = array_values(array_diff($expectedChanged, $changedDuringMigration));
  sort($missingChanged, SORT_STRING);
  if ($missingChanged !== []) {
    $problems[] = [
      'phase' => 'migration_validation',
      'reason' => 'expected_target_not_changed',
      'names' => $missingChanged,
    ];
  }
  if ($fingerprint($baselineCollections) !== $fingerprint($migratedCollections)) {
    $problems[] = [
      'phase' => 'migration_validation',
      'reason' => 'language_or_nondefault_collection_changed',
    ];
  }
}

foreach (array_reverse($migratedNames) as $name) {
  try {
    $entity = $loadConfigEntity($name);
    $entity->set('langcode', 'fr');
    $entity->save();
  }
  catch (Throwable $exception) {
    $problems[] = [
      'name' => $name,
      'phase' => 'rollback',
      'reason' => 'config_entity_rollback_save_failed',
      'error_class' => $exception::class,
      'message' => $exception->getMessage(),
    ];
  }
}

$finalDefault = $captureStorage($activeStorage);
$finalCollections = $captureCollections($activeStorage, $captureStorage);
$changedAfterRollback = $changedNames($baselineDefault, $finalDefault, $fingerprint);
if ($changedAfterRollback !== []) {
  $problems[] = [
    'phase' => 'rollback_validation',
    'reason' => 'active_config_not_fully_restored',
    'names' => $changedAfterRollback,
  ];
}
if ($fingerprint($baselineCollections) !== $fingerprint($finalCollections)) {
  $problems[] = [
    'phase' => 'rollback_validation',
    'reason' => 'collections_not_fully_restored',
  ];
}

$rollbackRestored = 0;
foreach (array_keys($cohortByName) as $name) {
  $before = $baselineDefault[$name] ?? NULL;
  $final = $finalDefault[$name] ?? NULL;
  if (is_array($before) && is_array($final) && $fingerprint($before) === $fingerprint($final)) {
    $rollbackRestored++;
  }
  if (isset($migrationItems[$name]) && is_array($final)) {
    $migrationItems[$name]['rollback_langcode'] = $final['langcode'] ?? NULL;
    $migrationItems[$name]['rollback_restored'] = is_array($before)
      && $fingerprint($before) === $fingerprint($final);
  }
}
ksort($migrationItems, SORT_STRING);

$migratedCount = 0;
foreach ($migrationItems as $item) {
  if (
    ($item['migrated_langcode'] ?? NULL) === 'en'
    && ($item['semantic_preserved'] ?? FALSE) === TRUE
  ) {
    $migratedCount++;
  }
}

$changedDuringMigration = isset($changedDuringMigration) ? $changedDuringMigration : [];
$expectedChanged = array_keys($cohortByName);
sort($expectedChanged, SORT_STRING);
$unexpectedMutationCount = count(array_diff($changedDuringMigration, $expectedChanged));
$languageOverrideDeltaCount = $fingerprint($baselineCollections) === $fingerprint($migratedCollections) ? 0 : 1;
$materialLeafCount = array_sum(array_column($classificationItems, 'material_translatable_leaf_count'));

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'THIRTY_NINE_MECHANICAL_CANDIDATES_MIGRATION_ROLLBACK_PROVEN'
  : 'MECHANICAL_COHORT_MIGRATION_ROLLBACK_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'source' => $cohort['source'] ?? [],
  'counts' => [
    'cohort_expected' => $expectedCount,
    'cohort_classified' => count($classificationItems),
    'material_translatable_leaf_count' => $materialLeafCount,
    'migrated' => $migratedCount,
    'unexpected_mutation_count' => $unexpectedMutationCount,
    'language_override_delta_count' => $languageOverrideDeltaCount,
    'rollback_restored' => $rollbackRestored,
    'problem_count' => count($problems),
  ],
  'migration_changed_names' => $changedDuringMigration,
  'rollback_changed_names' => $changedAfterRollback,
  'problems' => $problems,
  'items' => array_values($migrationItems),
  'constraints' => [
    'active_config_only' => TRUE,
    'repository_config_mutation_allowed_by_this_proof' => FALSE,
    'canonical_base_langcode_migration_allowed_by_this_proof' => FALSE,
    'bulk_langcode_replacement_allowed' => FALSE,
    'config_language_lock_activation_allowed_by_this_proof' => FALSE,
    'config_export_allowed_by_this_proof' => FALSE,
    'production_mutation_allowed_by_this_proof' => FALSE,
    'editorial_semantic_cohort_in_scope' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

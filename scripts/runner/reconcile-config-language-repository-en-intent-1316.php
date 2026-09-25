<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Symfony\Component\Yaml\Yaml;

if (getenv('AGENCY_CONFIG_LANGUAGE_REPOSITORY_EN_RECONCILE') !== '1') {
  throw new RuntimeException('Repository EN reconciliation requires explicit disposable authority.');
}

$mode = getenv('AGENCY_CONFIG_LANGUAGE_REPOSITORY_EN_MODE');
if (!in_array($mode, ['ANALYZE', 'RECONCILE'], TRUE)) {
  throw new RuntimeException('Repository EN reconciliation mode must be ANALYZE or RECONCILE.');
}

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
if (!is_dir($configDirectory)) {
  throw new RuntimeException('Configuration sync directory is unavailable.');
}

$configFactory = \Drupal::service('config.factory');
$typedConfigManager = \Drupal::service('config.typed');
$overrideFactory = \Drupal::service('language.config_factory_override');
$activeStorage = \Drupal::service('config.storage');
if (!$activeStorage instanceof StorageInterface) {
  throw new RuntimeException('Active configuration storage is unavailable.');
}
$englishStorage = $activeStorage->createCollection('language.en');
$english = ConfigurableLanguage::load('en');
if (!$english instanceof ConfigurableLanguage) {
  throw new RuntimeException('English configurable language is unavailable.');
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

$fingerprint = static function (bool $exists, mixed $value) use ($normalize): string {
  return hash('sha256', json_encode(
    [
      'exists' => $exists,
      'value' => $exists ? $normalize($value) : '__MISSING__',
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  ));
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

$deepOverride = NULL;
$deepOverride = static function (mixed $base, mixed $override) use (&$deepOverride): mixed {
  if (!is_array($base) || !is_array($override)) {
    return $override;
  }
  foreach ($override as $key => $value) {
    if (array_key_exists($key, $base)) {
      $base[$key] = $deepOverride($base[$key], $value);
    }
    else {
      $base[$key] = $value;
    }
  }
  return $base;
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
  if (!isset($definition['translatable']) || $definition['translatable'] !== TRUE) {
    return [];
  }

  $schemaType = 'UNKNOWN';
  if (method_exists($definition, 'getDataType')) {
    $candidateType = $definition->getDataType();
    if (is_string($candidateType) && $candidateType !== '') {
      $schemaType = $candidateType;
    }
  }

  return [[
    'segments' => $segments,
    'key' => $pathKey($segments),
    'path' => $displayPath($segments),
    'schema_type' => $schemaType,
    'schema_translatability' => 'YES',
  ]];
};

$readYaml = static function (string $path): array {
  $data = Yaml::parseFile($path);
  if (!is_array($data)) {
    throw new RuntimeException('Repository configuration YAML is not a mapping.');
  }
  return $data;
};

$ownedPaths = [];
$schemaProblems = [];
$baseFiles = glob($configDirectory . '/*.yml');
if ($baseFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate repository configuration.');
}
sort($baseFiles, SORT_STRING);

foreach ($baseFiles as $basePath) {
  $name = basename($basePath, '.yml');
  $baseData = $readYaml($basePath);
  $overridePath = $configDirectory . '/language/en/' . $name . '.yml';
  $explicitOverride = is_file($overridePath);
  $canonicalEnglish = ($baseData['langcode'] ?? NULL) === 'en';

  if (!$explicitOverride && !$canonicalEnglish) {
    continue;
  }
  if (!$typedConfigManager->hasConfigSchema($name)) {
    $schemaProblems[] = [
      'config' => $name,
      'reason' => 'schema_missing',
    ];
    continue;
  }

  $overrideData = $explicitOverride ? $readYaml($overridePath) : [];
  $typingData = $explicitOverride
    ? $deepOverride($baseData, $overrideData)
    : $baseData;

  try {
    $typed = $typedConfigManager->createFromNameAndData($name, $typingData);
    $translatableLeaves = $collectTranslatableLeaves($typed);
  }
  catch (Throwable $exception) {
    $schemaProblems[] = [
      'config' => $name,
      'reason' => 'typed_traversal_failed',
      'error_class' => $exception::class,
    ];
    continue;
  }

  $translatableByKey = [];
  foreach ($translatableLeaves as $leaf) {
    $translatableByKey[$leaf['key']] = $leaf;
  }

  if ($explicitOverride) {
    foreach ($collectRawLeaves($overrideData) as $sourceLeaf) {
      $typedLeaf = $translatableByKey[$sourceLeaf['key']] ?? NULL;
      if (!is_array($typedLeaf)) {
        continue;
      }
      $ownedPaths[] = [
        'config' => $name,
        'segments' => $sourceLeaf['segments'],
        'path' => $sourceLeaf['path'],
        'source_value' => $sourceLeaf['value'],
        'ownership' => 'EXPLICIT_EN_OVERRIDE',
        'schema_type' => $typedLeaf['schema_type'],
        'schema_translatability' => 'YES',
      ];
    }
    continue;
  }

  foreach ($translatableLeaves as $typedLeaf) {
    $exists = FALSE;
    $sourceValue = NestedArray::getValue(
      $baseData,
      $typedLeaf['segments'],
      $exists,
    );
    if (!$exists) {
      continue;
    }
    $ownedPaths[] = [
      'config' => $name,
      'segments' => $typedLeaf['segments'],
      'path' => $typedLeaf['path'],
      'source_value' => $sourceValue,
      'ownership' => 'EN_CANONICAL_BASE',
      'schema_type' => $typedLeaf['schema_type'],
      'schema_translatability' => 'YES',
    ];
  }
}

if ($schemaProblems !== []) {
  throw new RuntimeException('Repository EN ownership schema classification failed.');
}

usort(
  $ownedPaths,
  static fn(array $left, array $right): int =>
    [$left['config'], $left['path']] <=> [$right['config'], $right['path']],
);

$ownedObjectNames = array_values(array_unique(array_column($ownedPaths, 'config')));
sort($ownedObjectNames, SORT_STRING);

$overrideFactory->setLanguage($english);

$getEffectiveEnglish = static function (string $name) use (
  $configFactory,
  $overrideFactory,
  $english,
): array {
  $overrideFactory->setLanguage($english);
  $configFactory->reset();
  $data = $configFactory->get($name)->get();
  if (!is_array($data)) {
    throw new RuntimeException('Effective English configuration is not a mapping.');
  }
  return $data;
};

$captureStorageLayers = static function (array $item) use (
  $activeStorage,
  $englishStorage,
  $getEffectiveEnglish,
  $fingerprint,
): array {
  $name = $item['config'];
  $segments = $item['segments'];

  $canonicalData = $activeStorage->read($name);
  $canonicalObjectExists = is_array($canonicalData);
  $canonicalPathExists = FALSE;
  $canonicalValue = NULL;
  if ($canonicalObjectExists) {
    $canonicalValue = NestedArray::getValue(
      $canonicalData,
      $segments,
      $canonicalPathExists,
    );
  }

  $overrideData = $englishStorage->read($name);
  $overrideObjectExists = is_array($overrideData);
  $overridePathExists = FALSE;
  $overrideValue = NULL;
  if ($overrideObjectExists) {
    $overrideValue = NestedArray::getValue(
      $overrideData,
      $segments,
      $overridePathExists,
    );
  }

  $effectiveData = $getEffectiveEnglish($name);
  $effectivePathExists = FALSE;
  $effectiveValue = NestedArray::getValue(
    $effectiveData,
    $segments,
    $effectivePathExists,
  );

  return [
    'canonical_exists' => $canonicalPathExists,
    'canonical_sha256' => $fingerprint(
      $canonicalPathExists,
      $canonicalValue,
    ),
    'en_override_object_exists' => $overrideObjectExists,
    'en_override_path_exists' => $overridePathExists,
    'en_override_sha256' => $fingerprint(
      $overridePathExists,
      $overrideValue,
    ),
    'effective_en_exists' => $effectivePathExists,
    'effective_en_sha256' => $fingerprint(
      $effectivePathExists,
      $effectiveValue,
    ),
  ];
};

$maskingClassification = static function (
  string $sourceSha256,
  array $after,
): string {
  $sourceEqualsCanonical = hash_equals(
    $sourceSha256,
    $after['canonical_sha256'],
  );
  $sourceEqualsEffective = hash_equals(
    $sourceSha256,
    $after['effective_en_sha256'],
  );
  $effectiveEqualsOverride = hash_equals(
    $after['effective_en_sha256'],
    $after['en_override_sha256'],
  );

  if ($sourceEqualsEffective) {
    return 'CONVERGED';
  }
  if (!$sourceEqualsCanonical) {
    return 'CANONICAL_WRITE_DID_NOT_PERSIST';
  }
  if (
    $after['en_override_path_exists'] === TRUE
    && $effectiveEqualsOverride
  ) {
    return 'LANGUAGE_EN_OVERRIDE_MASKS_CANONICAL';
  }
  if (
    $sourceEqualsCanonical
    && (
      $after['en_override_path_exists'] !== TRUE
      || !$effectiveEqualsOverride
    )
  ) {
    return 'OTHER_EFFECTIVE_LAYER';
  }

  return 'UNCLASSIFIED';
};

$discoverDivergences = static function () use (
  $ownedPaths,
  $getEffectiveEnglish,
  $fingerprint,
): array {
  $effectiveByName = [];
  $divergences = [];

  foreach ($ownedPaths as $owned) {
    $name = $owned['config'];
    if (!array_key_exists($name, $effectiveByName)) {
      $effectiveByName[$name] = $getEffectiveEnglish($name);
    }

    $exists = FALSE;
    $runtimeValue = NestedArray::getValue(
      $effectiveByName[$name],
      $owned['segments'],
      $exists,
    );
    if ($exists && $runtimeValue === $owned['source_value']) {
      continue;
    }

    $divergences[] = [
      ...$owned,
      'runtime_exists' => $exists,
      'source_sha256' => $fingerprint(TRUE, $owned['source_value']),
      'runtime_sha256' => $fingerprint($exists, $runtimeValue),
    ];
  }

  return $divergences;
};

$publicDivergences = static function (array $divergences): array {
  return array_map(
    static fn(array $item): array => [
      'config' => $item['config'],
      'path' => $item['path'],
      'ownership' => $item['ownership'],
      'schema_type' => $item['schema_type'],
      'schema_translatability' => $item['schema_translatability'],
      'source_sha256' => $item['source_sha256'],
      'runtime_sha256' => $item['runtime_sha256'],
      'source_equals_runtime' => FALSE,
    ],
    $divergences,
  );
};

$divergenceIdentity = static function (array $divergences): string {
  $items = array_map(
    static fn(array $item): array => [
      'config' => $item['config'],
      'path' => $item['path'],
      'ownership' => $item['ownership'],
      'source_sha256' => $item['source_sha256'],
      'runtime_sha256' => $item['runtime_sha256'],
    ],
    $divergences,
  );
  return hash('sha256', json_encode(
    $items,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  ));
};

$before = $discoverDivergences();
$beforeObjects = array_values(array_unique(array_column($before, 'config')));
sort($beforeObjects, SORT_STRING);
$beforeIdentity = $divergenceIdentity($before);

if ($mode === 'ANALYZE') {
  echo json_encode([
    'schema_version' => 1,
    'status' => 'PASS',
    'mode' => $mode,
    'repository_en_owned_object_count' => count($ownedObjectNames),
    'repository_en_owned_path_count' => count($ownedPaths),
    'divergent_object_count_before' => count($beforeObjects),
    'divergent_path_count_before' => count($before),
    'divergent_objects_before' => $beforeObjects,
    'divergent_paths_before' => $publicDivergences($before),
    'divergence_sha256' => $beforeIdentity,
    'reconciled_object_count' => 0,
    'reconciled_path_count' => 0,
    'divergent_object_count_after' => count($beforeObjects),
    'divergent_path_count_after' => count($before),
    'prod_access' => 'NONE',
    'preprod_access' => 'NONE',
    'raw_config_values_exposed' => FALSE,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
  return;
}

$expectedIdentity = getenv('AGENCY_CONFIG_LANGUAGE_REPOSITORY_EN_EXPECTED_DIVERGENCE_SHA256');
if (
  !is_string($expectedIdentity)
  || preg_match('/^[0-9a-f]{64}$/', $expectedIdentity) !== 1
  || !hash_equals($expectedIdentity, $beforeIdentity)
) {
  throw new RuntimeException('Repository EN divergence identity changed before reconciliation.');
}

$beforeLayersByKey = [];
foreach ($before as $item) {
  $key = $item['config'] . "\0" . $item['path'];
  $beforeLayersByKey[$key] = $captureStorageLayers($item);
}

foreach ($before as $item) {
  foreach ($item['segments'] as $segment) {
    if (str_contains((string) $segment, '.')) {
      throw new RuntimeException('Repository EN path contains an unsupported dotted segment.');
    }
  }
  if ($item['schema_translatability'] !== 'YES') {
    throw new RuntimeException('Repository EN reconciliation refused a non-translatable path.');
  }
}

$byObject = [];
foreach ($before as $item) {
  $byObject[$item['config']][] = $item;
}

foreach ($byObject as $name => $items) {
  $ownerships = array_values(array_unique(array_column($items, 'ownership')));
  if (count($ownerships) !== 1) {
    throw new RuntimeException('Repository EN ownership mode is ambiguous for one configuration object.');
  }

  if ($ownerships[0] === 'EN_CANONICAL_BASE') {
    $config = $configFactory->getEditable($name);
    if ($config->isNew()) {
      throw new RuntimeException('Active canonical configuration is missing during reconciliation.');
    }
    foreach ($items as $item) {
      $config->set($item['path'], $item['source_value']);
    }
    $config->save();
    continue;
  }

  if ($ownerships[0] === 'EXPLICIT_EN_OVERRIDE') {
    $override = $overrideFactory->getOverride('en', $name);
    foreach ($items as $item) {
      $override->set($item['path'], $item['source_value']);
    }
    $override->save();
    continue;
  }

  throw new RuntimeException('Unsupported repository EN ownership mode.');
}

$configFactory->reset();
$afterBeforeCleanup = $discoverDivergences();
$afterBeforeCleanupObjects = array_values(array_unique(array_column($afterBeforeCleanup, 'config')));
sort($afterBeforeCleanupObjects, SORT_STRING);

$reconciledPaths = [];
$reconciledPathItems = [];
foreach ($before as $item) {
  $key = $item['config'] . "\0" . $item['path'];
  $beforeEvidence = $beforeLayersByKey[$key] ?? NULL;
  if (!is_array($beforeEvidence)) {
    throw new RuntimeException('Pre-reconciliation path evidence is unavailable.');
  }

  $afterEvidence = $captureStorageLayers($item);
  $sourceSha256 = $item['source_sha256'];
  $sourceEqualsCanonicalAfter = hash_equals(
    $sourceSha256,
    $afterEvidence['canonical_sha256'],
  );
  $sourceEqualsEnOverrideAfter = hash_equals(
    $sourceSha256,
    $afterEvidence['en_override_sha256'],
  );
  $sourceEqualsEffectiveAfter = hash_equals(
    $sourceSha256,
    $afterEvidence['effective_en_sha256'],
  );

  $reconciledPaths[] = [
    'config' => $item['config'],
    'path' => $item['path'],
    'ownership' => $item['ownership'],
    'schema_type' => $item['schema_type'],
    'schema_translatability' => $item['schema_translatability'],
    'source_sha256' => $sourceSha256,
    'before_sha256' => $item['runtime_sha256'],
    'after_sha256' => $afterEvidence['effective_en_sha256'],
    'source_equals_after' => $sourceEqualsEffectiveAfter,
    'canonical_before_exists' => $beforeEvidence['canonical_exists'],
    'canonical_before_sha256' => $beforeEvidence['canonical_sha256'],
    'en_override_object_before_exists' => $beforeEvidence['en_override_object_exists'],
    'en_override_path_before_exists' => $beforeEvidence['en_override_path_exists'],
    'en_override_before_sha256' => $beforeEvidence['en_override_sha256'],
    'effective_en_before_exists' => $beforeEvidence['effective_en_exists'],
    'effective_en_before_sha256' => $beforeEvidence['effective_en_sha256'],
    'canonical_after_exists' => $afterEvidence['canonical_exists'],
    'canonical_after_sha256' => $afterEvidence['canonical_sha256'],
    'en_override_object_after_exists' => $afterEvidence['en_override_object_exists'],
    'en_override_path_after_exists' => $afterEvidence['en_override_path_exists'],
    'en_override_after_sha256' => $afterEvidence['en_override_sha256'],
    'effective_en_after_exists' => $afterEvidence['effective_en_exists'],
    'effective_en_after_sha256' => $afterEvidence['effective_en_sha256'],
    'source_equals_canonical_after' => $sourceEqualsCanonicalAfter,
    'source_equals_en_override_after' => $sourceEqualsEnOverrideAfter,
    'source_equals_effective_after' => $sourceEqualsEffectiveAfter,
    'masking_classification' => $maskingClassification(
      $sourceSha256,
      $afterEvidence,
    ),
    'masking_classification_before_cleanup' => $maskingClassification(
      $sourceSha256,
      $afterEvidence,
    ),
    'override_path_cleared' => FALSE,
    'override_object_deleted' => FALSE,
    'source_equals_effective_after_cleanup' => $sourceEqualsEffectiveAfter,
  ];
  $reconciledPathItems[$key] = $item;
}

$maskingPathIndexes = [];
$maskingObjectNames = [];
foreach ($reconciledPaths as $index => $evidence) {
  if (
    $evidence['ownership'] === 'EN_CANONICAL_BASE'
    && $evidence['schema_translatability'] === 'YES'
    && $evidence['source_equals_canonical_after'] === TRUE
    && $evidence['en_override_object_after_exists'] === TRUE
    && $evidence['en_override_path_after_exists'] === TRUE
    && $evidence['source_equals_en_override_after'] === FALSE
    && $evidence['source_equals_effective_after'] === FALSE
    && $evidence['masking_classification'] === 'LANGUAGE_EN_OVERRIDE_MASKS_CANONICAL'
  ) {
    $maskingPathIndexes[] = $index;
    $maskingObjectNames[$evidence['config']] = TRUE;
  }
}

$maskingPathCountBeforeCleanup = count($maskingPathIndexes);
$maskingObjectCountBeforeCleanup = count($maskingObjectNames);
if (
  $maskingPathCountBeforeCleanup !== 2
  || $maskingObjectCountBeforeCleanup !== 2
) {
  throw new RuntimeException('Repository EN masking set differs from the authorized 2-object / 2-path gate.');
}

$maskingPathsBeforeCleanup = [];
$cleanupByObject = [];
foreach ($maskingPathIndexes as $index) {
  $evidence = $reconciledPaths[$index];
  $key = $evidence['config'] . "\0" . $evidence['path'];
  $item = $reconciledPathItems[$key] ?? NULL;
  if (!is_array($item)) {
    throw new RuntimeException('Masking cleanup path evidence is unavailable.');
  }

  $maskingPathsBeforeCleanup[] = [
    'config' => $evidence['config'],
    'path' => $evidence['path'],
    'ownership' => $evidence['ownership'],
    'schema_type' => $evidence['schema_type'],
    'schema_translatability' => $evidence['schema_translatability'],
    'masking_classification' => $evidence['masking_classification'],
  ];
  $cleanupByObject[$evidence['config']][] = [
    'index' => $index,
    'segments' => $item['segments'],
    'path' => $evidence['path'],
  ];
}

$clearedOverridePathCount = 0;
$clearedOverrideObjectCount = 0;
$deletedEmptyOverrideObjectCount = 0;
$savedNonemptyOverrideObjectCount = 0;

foreach ($cleanupByObject as $name => $cleanupItems) {
  $rawBefore = $englishStorage->read($name);
  if (!is_array($rawBefore)) {
    throw new RuntimeException('Expected active language.en override object is unavailable.');
  }

  $expectedRawAfter = $rawBefore;
  foreach ($cleanupItems as $cleanupItem) {
    $pathExists = FALSE;
    NestedArray::getValue(
      $expectedRawAfter,
      $cleanupItem['segments'],
      $pathExists,
    );
    if (!$pathExists) {
      throw new RuntimeException('Authorized masking path disappeared before cleanup.');
    }
    NestedArray::unsetValue($expectedRawAfter, $cleanupItem['segments']);
  }

  $override = $overrideFactory->getOverride('en', $name);
  foreach ($cleanupItems as $cleanupItem) {
    $override->clear($cleanupItem['path']);
    $clearedOverridePathCount++;
    $reconciledPaths[$cleanupItem['index']]['override_path_cleared'] = TRUE;
  }
  $clearedOverrideObjectCount++;

  if ($override->getRawData() === []) {
    $override->delete();
    $deletedEmptyOverrideObjectCount++;
    foreach ($cleanupItems as $cleanupItem) {
      $reconciledPaths[$cleanupItem['index']]['override_object_deleted'] = TRUE;
    }
  }
  else {
    $override->save();
    $savedNonemptyOverrideObjectCount++;
  }

  $rawAfter = $englishStorage->read($name);
  $actualRawAfter = is_array($rawAfter) ? $rawAfter : [];
  if ($normalize($actualRawAfter) !== $normalize($expectedRawAfter)) {
    throw new RuntimeException('Masking cleanup changed unrelated language.en override data.');
  }
}

if ($clearedOverridePathCount !== 2) {
  throw new RuntimeException('Cleared override path count differs from the authorized gate.');
}

$overrideFactory->setLanguage($english);
$configFactory->reset();

$after = $discoverDivergences();
$afterObjects = array_values(array_unique(array_column($after, 'config')));
sort($afterObjects, SORT_STRING);

foreach ($reconciledPaths as $index => $evidence) {
  $key = $evidence['config'] . "\0" . $evidence['path'];
  $item = $reconciledPathItems[$key] ?? NULL;
  if (!is_array($item)) {
    throw new RuntimeException('Post-cleanup path evidence is unavailable.');
  }

  $cleanupEvidence = $captureStorageLayers($item);
  $sourceEqualsEffectiveAfterCleanup = hash_equals(
    $evidence['source_sha256'],
    $cleanupEvidence['effective_en_sha256'],
  );
  $reconciledPaths[$index]['source_equals_effective_after_cleanup'] = $sourceEqualsEffectiveAfterCleanup;
}

echo json_encode([
  'schema_version' => 1,
  'status' => $after === [] ? 'PASS' : 'FAIL',
  'mode' => $mode,
  'repository_en_owned_object_count' => count($ownedObjectNames),
  'repository_en_owned_path_count' => count($ownedPaths),
  'divergent_object_count_before' => count($beforeObjects),
  'divergent_path_count_before' => count($before),
  'divergent_objects_before' => $beforeObjects,
  'divergent_paths_before' => $publicDivergences($before),
  'divergence_sha256' => $beforeIdentity,
  'reconciled_object_count' => count($beforeObjects),
  'reconciled_path_count' => count($before),
  'reconciled_paths' => $reconciledPaths,
  'masking_object_count_before_cleanup' => $maskingObjectCountBeforeCleanup,
  'masking_path_count_before_cleanup' => $maskingPathCountBeforeCleanup,
  'masking_paths_before_cleanup' => $maskingPathsBeforeCleanup,
  'cleared_override_path_count' => $clearedOverridePathCount,
  'cleared_override_object_count' => $clearedOverrideObjectCount,
  'deleted_empty_override_object_count' => $deletedEmptyOverrideObjectCount,
  'saved_nonempty_override_object_count' => $savedNonemptyOverrideObjectCount,
  'divergent_object_count_after' => count($afterObjects),
  'divergent_path_count_after' => count($after),
  'divergent_objects_after' => $afterObjects,
  'divergent_paths_after' => $publicDivergences($after),
  'prod_access' => 'NONE',
  'preprod_access' => 'NONE',
  'raw_config_values_exposed' => FALSE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

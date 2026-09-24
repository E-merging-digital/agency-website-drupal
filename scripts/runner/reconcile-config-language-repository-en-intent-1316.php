<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
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
$after = $discoverDivergences();
$afterObjects = array_values(array_unique(array_column($after, 'config')));
sort($afterObjects, SORT_STRING);

$afterByKey = [];
foreach ($ownedPaths as $owned) {
  $effective = $getEffectiveEnglish($owned['config']);
  $exists = FALSE;
  $value = NestedArray::getValue($effective, $owned['segments'], $exists);
  $afterByKey[$owned['config'] . "\0" . $owned['path']] = [
    'exists' => $exists,
    'sha256' => $fingerprint($exists, $value),
  ];
}

$reconciledPaths = [];
foreach ($before as $item) {
  $key = $item['config'] . "\0" . $item['path'];
  $afterEvidence = $afterByKey[$key] ?? NULL;
  if (!is_array($afterEvidence)) {
    throw new RuntimeException('Post-reconciliation path evidence is unavailable.');
  }
  $reconciledPaths[] = [
    'config' => $item['config'],
    'path' => $item['path'],
    'ownership' => $item['ownership'],
    'schema_type' => $item['schema_type'],
    'schema_translatability' => $item['schema_translatability'],
    'source_sha256' => $item['source_sha256'],
    'before_sha256' => $item['runtime_sha256'],
    'after_sha256' => $afterEvidence['sha256'],
    'source_equals_after' => hash_equals($item['source_sha256'], $afterEvidence['sha256']),
  ];
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
  'divergent_object_count_after' => count($afterObjects),
  'divergent_path_count_after' => count($after),
  'divergent_objects_after' => $afterObjects,
  'divergent_paths_after' => $publicDivergences($after),
  'prod_access' => 'NONE',
  'preprod_access' => 'NONE',
  'raw_config_values_exposed' => FALSE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

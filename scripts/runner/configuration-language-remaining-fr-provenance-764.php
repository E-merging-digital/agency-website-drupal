<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot
  . '/docs/evidence/'
  . 'configuration-language-remaining-fr-provenance-cohort-764.yml';

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #764 provenance baseline is incomplete.');
}

$manifest = Yaml::parseFile($manifestPath);
if (!is_array($manifest)) {
  throw new RuntimeException('Issue #764 manifest must be a mapping.');
}
if (($manifest['issue'] ?? NULL) !== 764) {
  throw new RuntimeException('Issue #764 manifest identity mismatch.');
}
if (($manifest['parent_issue'] ?? NULL) !== 609) {
  throw new RuntimeException('Issue #764 parent identity mismatch.');
}
if (($manifest['cohort']['total'] ?? NULL) !== 69) {
  throw new RuntimeException('Issue #764 cohort must contain 69 names.');
}

$manifestNames = $manifest['cohort']['names'] ?? NULL;
if (!is_array($manifestNames) || count($manifestNames) !== 69) {
  throw new RuntimeException('Issue #764 manifest names are incomplete.');
}
$manifestNames = array_values(array_map('strval', $manifestNames));
sort($manifestNames, SORT_STRING);
if (count(array_unique($manifestNames)) !== 69) {
  throw new RuntimeException('Issue #764 manifest names must be unique.');
}

$manifestHash = hash(
  'sha256',
  implode("\n", $manifestNames) . "\n",
);
$expectedHash = $manifest['cohort']['names_sha256'] ?? NULL;
if ($manifestHash !== $expectedHash) {
  throw new RuntimeException('Issue #764 manifest names hash mismatch.');
}

$typedConfigManager = \Drupal::service('config.typed');
$configFactory = \Drupal::service('config.factory');
$activeStorage = \Drupal::service('config.storage');

$isMaterial = static function (mixed $value): bool {
  if ($value === NULL || $value === []) {
    return FALSE;
  }
  if (is_string($value)) {
    return trim($value) !== '';
  }
  return is_scalar($value);
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
) use (&$collectTranslatableLeaves, $displayPath): array {
  if ($element instanceof TraversableTypedDataInterface) {
    $leaves = [];
    foreach ($element as $key => $child) {
      if (!$child instanceof TypedDataInterface) {
        continue;
      }
      foreach (
        $collectTranslatableLeaves($child, [...$segments, $key])
        as $leaf
      ) {
        $leaves[] = $leaf;
      }
    }
    return $leaves;
  }

  $definition = $element->getDataDefinition();
  if (empty($definition['translatable'])) {
    return [];
  }

  return [[
    'segments' => $segments,
    'path' => $displayPath($segments),
    'value' => $element->getValue(),
  ]];
};

$baseFiles = glob($configDirectory . '/*.yml');
if ($baseFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate base configuration.');
}
sort($baseFiles, SORT_STRING);

$repositoryDistribution = [
  '__none__' => 0,
  'en' => 0,
  'fr' => 0,
  'und' => 0,
];
$repositoryDataByName = [];
$repositoryFrNames = [];
foreach ($baseFiles as $basePath) {
  $data = Yaml::parseFile($basePath);
  if (!is_array($data)) {
    throw new RuntimeException("Invalid configuration YAML: $basePath");
  }
  $name = basename($basePath, '.yml');
  $repositoryDataByName[$name] = $data;
  $langcode = $data['langcode'] ?? NULL;
  $key = is_string($langcode) ? $langcode : '__none__';
  if (!array_key_exists($key, $repositoryDistribution)) {
    $repositoryDistribution[$key] = 0;
  }
  $repositoryDistribution[$key]++;
  if ($langcode === 'fr') {
    $repositoryFrNames[] = $name;
  }
}
ksort($repositoryDistribution, SORT_STRING);
sort($repositoryFrNames, SORT_STRING);

$expectedDistribution = [
  '__none__' => 59,
  'en' => 466,
  'fr' => 69,
  'und' => 1,
];
ksort($expectedDistribution, SORT_STRING);

$baselineProblems = [];
if (count($baseFiles) !== 595) {
  $baselineProblems[] = [
    'reason' => 'repository_total_mismatch',
    'actual' => count($baseFiles),
    'expected' => 595,
  ];
}
if ($repositoryDistribution !== $expectedDistribution) {
  $baselineProblems[] = [
    'reason' => 'repository_distribution_mismatch',
    'actual' => $repositoryDistribution,
    'expected' => $expectedDistribution,
  ];
}
if ($repositoryFrNames !== $manifestNames) {
  $baselineProblems[] = [
    'reason' => 'remaining_fr_name_set_mismatch',
    'missing' => array_values(array_diff($manifestNames, $repositoryFrNames)),
    'unexpected' => array_values(array_diff(
      $repositoryFrNames,
      $manifestNames,
    )),
  ];
}

$activeNames = $activeStorage->listAll();
sort($activeNames, SORT_STRING);
$activeDistribution = [
  '__none__' => 0,
  'en' => 0,
  'fr' => 0,
  'und' => 0,
];
foreach ($activeNames as $name) {
  $data = $activeStorage->read($name);
  if (!is_array($data)) {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => 'active_config_unreadable',
    ];
    continue;
  }
  $langcode = $data['langcode'] ?? NULL;
  $key = is_string($langcode) ? $langcode : '__none__';
  if (!array_key_exists($key, $activeDistribution)) {
    $activeDistribution[$key] = 0;
  }
  $activeDistribution[$key]++;
}
ksort($activeDistribution, SORT_STRING);
if (count($activeNames) !== 595) {
  $baselineProblems[] = [
    'reason' => 'active_total_mismatch',
    'actual' => count($activeNames),
    'expected' => 595,
  ];
}
if ($activeDistribution !== $expectedDistribution) {
  $baselineProblems[] = [
    'reason' => 'active_distribution_mismatch',
    'actual' => $activeDistribution,
    'expected' => $expectedDistribution,
  ];
}

$candidateNames = array_fill_keys($manifestNames, TRUE);
$defaultIndex = [];
$extensionServices = [
  'module' => 'extension.list.module',
  'theme' => 'extension.list.theme',
  'profile' => 'extension.list.profile',
];
foreach ($extensionServices as $extensionType => $serviceId) {
  $extensionList = \Drupal::service($serviceId);
  foreach ($extensionList->getList() as $extensionName => $extension) {
    $extensionRoot = DRUPAL_ROOT . '/' . $extension->getPath();
    foreach (['install', 'optional'] as $directoryKind) {
      $directory = $extensionRoot . '/config/' . $directoryKind;
      if (!is_dir($directory)) {
        continue;
      }
      $files = glob($directory . '/*.yml');
      if ($files === FALSE) {
        throw new RuntimeException("Unable to enumerate $directory.");
      }
      sort($files, SORT_STRING);
      foreach ($files as $path) {
        $name = basename($path, '.yml');
        if (!isset($candidateNames[$name])) {
          continue;
        }
        $data = Yaml::parseFile($path);
        if (!is_array($data)) {
          continue;
        }
        $langcode = isset($data['langcode'])
          && is_string($data['langcode'])
            ? $data['langcode']
            : NULL;
        if (!in_array($langcode, ['en', NULL], TRUE)) {
          continue;
        }
        $defaultIndex[$name][] = [
          'extension_type' => $extensionType,
          'extension' => $extensionName,
          'directory' => $directoryKind,
          'path' => ltrim(str_replace($projectRoot, '', $path), '/'),
          'langcode' => $langcode,
          'data' => $data,
        ];
      }
    }
  }
}
ksort($defaultIndex, SORT_STRING);

$items = [];
foreach ($manifestNames as $name) {
  $repositoryData = $repositoryDataByName[$name] ?? NULL;
  if (!is_array($repositoryData)) {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => 'repository_config_missing',
    ];
    continue;
  }
  if (($repositoryData['langcode'] ?? NULL) !== 'fr') {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => 'repository_langcode_not_fr',
    ];
    continue;
  }
  if (is_file($configDirectory . '/language/en/' . $name . '.yml')) {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => 'unexpected_en_override',
    ];
    continue;
  }

  $activeConfig = $configFactory->getEditable($name);
  $activeData = $activeConfig->get();
  if ($activeConfig->isNew()) {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => 'active_config_missing',
    ];
    continue;
  }
  if ($activeData !== $repositoryData) {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => 'active_repository_data_mismatch',
    ];
    continue;
  }

  if (!$typedConfigManager->hasConfigSchema($name)) {
    $items[] = [
      'name' => $name,
      'classification' => 'schema_or_provenance_unresolved',
      'reason' => 'config_schema_missing',
      'material_translatable_leaves' => [],
      'english_defaults' => [],
    ];
    continue;
  }

  try {
    $typedSource = $typedConfigManager->createFromNameAndData(
      $name,
      $activeData,
    );
    $allLeaves = $collectTranslatableLeaves($typedSource);
  }
  catch (Throwable $exception) {
    $items[] = [
      'name' => $name,
      'classification' => 'schema_or_provenance_unresolved',
      'reason' => 'typed_config_traversal_failed',
      'error_class' => $exception::class,
      'material_translatable_leaves' => [],
      'english_defaults' => [],
    ];
    continue;
  }

  $materialLeaves = [];
  foreach ($allLeaves as $leaf) {
    if (!$isMaterial($leaf['value'])) {
      continue;
    }
    $materialLeaves[] = $leaf;
  }
  usort(
    $materialLeaves,
    static fn(array $left, array $right): int =>
      $left['path'] <=> $right['path'],
  );

  if ($materialLeaves === []) {
    $items[] = [
      'name' => $name,
      'classification' => 'schema_or_provenance_unresolved',
      'reason' => 'historical_material_source_disappeared',
      'material_translatable_leaves' => [],
      'english_defaults' => [],
    ];
    continue;
  }

  $englishDefaults = [];
  $exactDefaults = [];
  foreach ($defaultIndex[$name] ?? [] as $default) {
    $divergences = [];
    foreach ($materialLeaves as $leaf) {
      $exists = FALSE;
      $defaultValue = NestedArray::getValue(
        $default['data'],
        $leaf['segments'],
        $exists,
      );
      if (!$exists || $defaultValue !== $leaf['value']) {
        $divergences[] = [
          'path' => $leaf['path'],
          'repository_value' => $leaf['value'],
          'default_value_present' => $exists,
          'default_value' => $exists ? $defaultValue : NULL,
        ];
      }
    }
    $summary = [
      'extension_type' => $default['extension_type'],
      'extension' => $default['extension'],
      'directory' => $default['directory'],
      'path' => $default['path'],
      'langcode' => $default['langcode'],
      'divergences' => $divergences,
    ];
    $englishDefaults[] = $summary;
    if ($divergences === []) {
      $exactDefaults[] = $summary;
    }
  }

  $runtimeSource = NULL;
  if (str_starts_with($name, 'canvas.component.')) {
    $source = $repositoryData['source'] ?? NULL;
    $sourceLocalId = $repositoryData['source_local_id'] ?? NULL;
    $provider = $repositoryData['provider'] ?? NULL;
    if (
      is_string($source)
      && $source !== ''
      && is_string($sourceLocalId)
      && $sourceLocalId !== ''
      && is_string($provider)
      && $provider !== ''
    ) {
      $runtimeSource = [
        'source' => $source,
        'source_local_id' => $sourceLocalId,
        'provider' => $provider,
      ];
    }
  }

  if ($exactDefaults !== []) {
    $classification = 'english_default_exact_match';
    $reason = 'all_material_leaves_match_extension_default';
  }
  elseif ($englishDefaults !== []) {
    $classification = 'extension_default_present_but_values_diverged';
    $reason = 'extension_default_exists_but_material_leaves_diverge';
  }
  elseif ($runtimeSource !== NULL) {
    $classification = 'runtime_source_candidate';
    $reason = 'canvas_component_exposes_explicit_runtime_source_reference';
  }
  else {
    $classification = 'project_custom_or_editorial_review_required';
    $reason = 'no_authoritative_english_default_or_runtime_reference_proven';
  }

  $evidenceLeaves = array_map(
    static fn(array $leaf): array => [
      'path' => $leaf['path'],
      'value' => $leaf['value'],
    ],
    $materialLeaves,
  );

  $items[] = [
    'name' => $name,
    'classification' => $classification,
    'reason' => $reason,
    'material_translatable_leaf_count' => count($materialLeaves),
    'material_translatable_leaves' => $evidenceLeaves,
    'english_defaults' => $englishDefaults,
    'runtime_source' => $runtimeSource,
  ];
}

usort(
  $items,
  static fn(array $left, array $right): int => $left['name'] <=> $right['name'],
);

$classifications = [
  'english_default_exact_match',
  'extension_default_present_but_values_diverged',
  'runtime_source_candidate',
  'project_custom_or_editorial_review_required',
  'schema_or_provenance_unresolved',
];
$counts = [
  'candidate_total' => 69,
  'classified' => count($items),
  'baseline_problem' => count($baselineProblems),
];
$namesByClassification = [];
foreach ($classifications as $classification) {
  $counts[$classification] = 0;
  $namesByClassification[$classification] = [];
}
foreach ($items as $item) {
  $classification = (string) $item['classification'];
  $counts[$classification]++;
  $namesByClassification[$classification][] = $item['name'];
}

$focus = [
  'canvas_component' => [],
  'canvas_folder' => [],
  'other' => [],
];
foreach ($items as $item) {
  $name = (string) $item['name'];
  if (str_starts_with($name, 'canvas.component.')) {
    $focus['canvas_component'][] = $name;
  }
  elseif (str_starts_with($name, 'canvas.folder.')) {
    $focus['canvas_folder'][] = $name;
  }
  else {
    $focus['other'][] = $name;
  }
}
foreach ($focus as $key => $names) {
  sort($names, SORT_STRING);
  $focus[$key] = [
    'count' => count($names),
    'names' => $names,
  ];
}

$lockEnabled = is_file($configDirectory . '/config_language_lock.settings.yml')
  || str_contains(
    (string) file_get_contents($configDirectory . '/core.extension.yml'),
    "  config_language_lock:",
  );

$status = 'PASS';
$verdict = 'REMAINING_FR_PROVENANCE_CLASSIFIED';
if (
  $baselineProblems !== []
  || count($items) !== 69
  || $counts['schema_or_provenance_unresolved'] !== 0
  || $focus['canvas_component']['count'] !== 30
  || $focus['canvas_folder']['count'] !== 13
  || $focus['other']['count'] !== 26
  || $lockEnabled
) {
  $status = 'FAIL';
  $verdict = 'REMAINING_FR_PROVENANCE_CLASSIFICATION_FAILED';
}

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'baseline' => [
    'manifest' => basename($manifestPath),
    'manifest_names_sha256' => $manifestHash,
    'repository_total' => count($baseFiles),
    'active_total' => count($activeNames),
    'repository_distribution' => $repositoryDistribution,
    'active_distribution' => $activeDistribution,
  ],
  'counts' => $counts,
  'focus' => $focus,
  'names_by_classification' => $namesByClassification,
  'baseline_problems' => $baselineProblems,
  'items' => $items,
  'constraints' => [
    'read_only' => TRUE,
    'migration_allowed_by_this_proof' => FALSE,
    'config_language_lock_enabled_canonically' => $lockEnabled,
    'config_language_lock_activation_allowed_by_this_proof' => FALSE,
    'config_export_allowed_by_this_proof' => FALSE,
    'production_access_allowed_by_this_proof' => FALSE,
    'provider_secret_allowed_by_this_proof' => FALSE,
    'natural_language_heuristic_used' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT
  | JSON_UNESCAPED_SLASHES
  | JSON_UNESCAPED_UNICODE
  | JSON_THROW_ON_ERROR,
) . PHP_EOL;

<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$policyPath = $projectRoot . '/docs/configuration-language-policy.yml';
$promotionManifest = $projectRoot
  . '/docs/evidence/configuration-language-translated-canonical-cohort-720.yml';

if (!is_dir($configDirectory) || !is_file($policyPath) || !is_file($promotionManifest)) {
  throw new RuntimeException('Post-#720 configuration-language baseline is incomplete.');
}

$policy = Yaml::parseFile($policyPath);
if (!is_array($policy)) {
  throw new RuntimeException('Configuration language policy must be a mapping.');
}
if (($policy['canonical_configuration_language'] ?? NULL) !== 'en') {
  throw new RuntimeException('Canonical configuration language must remain en.');
}
if (($policy['enforce_consistency'] ?? TRUE) !== FALSE) {
  throw new RuntimeException('Provenance classification must run before enforcement.');
}

$typedConfigManager = \Drupal::service('config.typed');
$configFactory = \Drupal::service('config.factory');

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
    'path' => $displayPath($segments),
    'value' => $element->getValue(),
  ]];
};

$baseFiles = glob($configDirectory . '/*.yml');
if ($baseFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate base configuration.');
}
sort($baseFiles, SORT_STRING);

$candidates = [];
foreach ($baseFiles as $basePath) {
  $data = Yaml::parseFile($basePath);
  if (!is_array($data) || ($data['langcode'] ?? NULL) !== 'fr') {
    continue;
  }

  $name = basename($basePath, '.yml');
  if (is_file($configDirectory . '/language/en/' . $name . '.yml')) {
    continue;
  }
  $candidates[$name] = $data;
}
ksort($candidates, SORT_STRING);

$candidateNames = array_fill_keys(array_keys($candidates), TRUE);
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
        $defaultIndex[$name][] = [
          'extension_type' => $extensionType,
          'extension' => $extensionName,
          'directory' => $directoryKind,
          'path' => ltrim(str_replace($projectRoot, '', $path), '/'),
          'langcode' => isset($data['langcode']) && is_string($data['langcode'])
            ? $data['langcode']
            : NULL,
          'data' => $data,
        ];
      }
    }
  }
}
ksort($defaultIndex, SORT_STRING);

$items = [];
$baselineProblems = [];
foreach ($candidates as $name => $repositoryData) {
  $sourceConfig = $configFactory->getEditable($name);
  $sourceData = $sourceConfig->get();
  if ($sourceConfig->isNew() || ($sourceData['langcode'] ?? NULL) !== 'fr') {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => $sourceConfig->isNew()
        ? 'active_config_missing'
        : 'active_source_langcode_not_fr',
    ];
    continue;
  }
  if ($sourceData !== $repositoryData) {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => 'active_repository_data_mismatch',
    ];
    continue;
  }

  if (!$typedConfigManager->hasConfigSchema($name)) {
    $items[] = [
      'name' => $name,
      'classification' => 'schema_unresolved_review_required',
      'reason' => 'config_schema_missing',
      'material_translatable_leaf_count' => 0,
      'material_translatable_leaves' => [],
      'english_default_matches' => [],
    ];
    continue;
  }

  try {
    $typedSource = $typedConfigManager->createFromNameAndData($name, $sourceData);
    $allLeaves = $collectTranslatableLeaves($typedSource);
  }
  catch (Throwable $exception) {
    $items[] = [
      'name' => $name,
      'classification' => 'schema_unresolved_review_required',
      'reason' => 'typed_config_traversal_failed',
      'error_class' => $exception::class,
      'material_translatable_leaf_count' => 0,
      'material_translatable_leaves' => [],
      'english_default_matches' => [],
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
    static fn(array $left, array $right): int => $left['path'] <=> $right['path'],
  );

  $defaultMatches = [];
  foreach ($defaultIndex[$name] ?? [] as $default) {
    if (!in_array($default['langcode'], ['en', NULL], TRUE)) {
      continue;
    }

    $matches = TRUE;
    foreach ($materialLeaves as $leaf) {
      $exists = FALSE;
      $defaultValue = NestedArray::getValue(
        $default['data'],
        $leaf['segments'],
        $exists,
      );
      if (!$exists || $defaultValue !== $leaf['value']) {
        $matches = FALSE;
        break;
      }
    }
    if ($matches) {
      $defaultMatches[] = [
        'extension_type' => $default['extension_type'],
        'extension' => $default['extension'],
        'directory' => $default['directory'],
        'path' => $default['path'],
        'langcode' => $default['langcode'],
      ];
    }
  }

  if ($materialLeaves === []) {
    $classification = 'no_material_translatable_source_candidate';
    $reason = 'typed_schema_has_no_material_translatable_source_leaves';
  }
  elseif ($defaultMatches !== []) {
    $classification = 'english_default_exact_match_candidate';
    $reason = 'all_material_translatable_leaves_match_extension_default';
  }
  else {
    $classification = 'material_review_required';
    $reason = 'material_translatable_source_without_exact_english_default';
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
    'english_default_matches' => $defaultMatches,
  ];
}

usort(
  $items,
  static fn(array $left, array $right): int => $left['name'] <=> $right['name'],
);

$counts = [
  'candidate_fr_without_en_override' => count($candidates),
  'classified' => count($items),
  'baseline_problem' => count($baselineProblems),
  'no_material_translatable_source_candidate' => 0,
  'english_default_exact_match_candidate' => 0,
  'material_review_required' => 0,
  'schema_unresolved_review_required' => 0,
];
$namesByClassification = [
  'no_material_translatable_source_candidate' => [],
  'english_default_exact_match_candidate' => [],
  'material_review_required' => [],
  'schema_unresolved_review_required' => [],
];
foreach ($items as $item) {
  $classification = (string) $item['classification'];
  $counts[$classification]++;
  $namesByClassification[$classification][] = $item['name'];
}

$status = 'PASS';
$verdict = 'REVIEW_REQUIRED_PROVENANCE_CLASSIFIED';
if (
  count($candidates) !== 140
  || count($items) !== 140
  || $baselineProblems !== []
  || $counts['schema_unresolved_review_required'] !== 0
) {
  $status = 'FAIL';
  $verdict = 'REVIEW_REQUIRED_PROVENANCE_CLASSIFICATION_FAILED';
}

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'baseline' => [
    'expected_candidate_count' => 140,
    'canonical_configuration_language' => 'en',
    'enforcement_enabled' => FALSE,
    'promotion_manifest' => 'configuration-language-translated-canonical-cohort-720.yml',
  ],
  'counts' => $counts,
  'names_by_classification' => $namesByClassification,
  'baseline_problems' => $baselineProblems,
  'items' => $items,
  'constraints' => [
    'read_only' => TRUE,
    'migration_allowed_by_this_proof' => FALSE,
    'config_language_lock_activation_allowed_by_this_proof' => FALSE,
    'config_export_allowed_by_this_proof' => FALSE,
    'production_mutation_allowed_by_this_proof' => FALSE,
    'natural_language_heuristic_used' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

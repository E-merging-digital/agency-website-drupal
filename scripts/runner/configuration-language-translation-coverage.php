<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$baselinePath = $projectRoot . '/docs/evidence/configuration-language-baseline-609.yml';

if (!is_dir($configDirectory) || !is_file($baselinePath)) {
  throw new RuntimeException('Configuration sync or #609 baseline is missing.');
}

$baseline = Yaml::parseFile($baselinePath);
if (!is_array($baseline)) {
  throw new RuntimeException('Configuration language baseline must be a mapping.');
}

$expectedCandidateCount = (int) (
  $baseline['migration_analysis']['fr_base_with_en_override'] ?? -1
);
if ($expectedCandidateCount !== 171) {
  throw new RuntimeException('Unexpected #609 translated FR-base baseline count.');
}

$configFactory = \Drupal::service('config.factory');
$typedConfigManager = \Drupal::service('config.typed');
$overrideFactory = \Drupal::service('language.config_factory_override');

/**
 * Returns whether a translatable source value needs explicit coverage.
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
 * Encodes a path without losing numeric sequence keys.
 */
$pathKey = static function (array $segments): string {
  return json_encode($segments, JSON_THROW_ON_ERROR);
};

/**
 * Renders a path for machine-readable evidence and human inspection.
 */
$displayPath = static function (array $segments): string {
  return implode('.', array_map(
    static fn(string|int $segment): string => (string) $segment,
    $segments,
  ));
};

/**
 * Collects schema-backed translatable leaves from one source config object.
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
      $childSegments = [...$segments, $key];
      foreach ($collectTranslatableLeaves($child, $childSegments) as $leaf) {
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
 * Collects scalar leaf paths from one raw language override.
 */
$collectRawLeafPaths = NULL;
$collectRawLeafPaths = static function (
  mixed $value,
  array $segments = [],
) use (&$collectRawLeafPaths, $displayPath, $pathKey): array {
  if (is_array($value)) {
    $leaves = [];
    foreach ($value as $key => $child) {
      foreach ($collectRawLeafPaths($child, [...$segments, $key]) as $leaf) {
        $leaves[] = $leaf;
      }
    }
    return $leaves;
  }

  return [[
    'segments' => $segments,
    'key' => $pathKey($segments),
    'path' => $displayPath($segments),
  ]];
};

$baseFiles = glob($configDirectory . '/*.yml');
if ($baseFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate base configuration.');
}
sort($baseFiles, SORT_STRING);

$candidateNames = [];
foreach ($baseFiles as $basePath) {
  $baseData = Yaml::parseFile($basePath);
  if (!is_array($baseData) || ($baseData['langcode'] ?? NULL) !== 'fr') {
    continue;
  }

  $name = basename($basePath, '.yml');
  $englishPath = $configDirectory . '/language/en/' . $name . '.yml';
  if (is_file($englishPath)) {
    $candidateNames[] = $name;
  }
}
sort($candidateNames, SORT_STRING);

$items = [];
$baselineProblems = [];
foreach ($candidateNames as $name) {
  $sourceConfig = $configFactory->getEditable($name);
  $sourceData = $sourceConfig->get();
  $sourceLangcode = $sourceData['langcode'] ?? NULL;
  if ($sourceConfig->isNew() || $sourceLangcode !== 'fr') {
    $baselineProblems[] = [
      'name' => $name,
      'reason' => $sourceConfig->isNew()
        ? 'active_config_missing'
        : 'active_source_langcode_not_fr',
    ];
    continue;
  }

  $englishOverride = $overrideFactory->getOverride('en', $name);
  if ($englishOverride->isNew()) {
    $items[] = [
      'name' => $name,
      'classification' => 'schema_unresolved_review_required',
      'reason' => 'active_en_override_missing',
      'material_translatable_leaf_count' => 0,
      'explicit_en_coverage_count' => 0,
      'missing_count' => 0,
      'covered_paths' => [],
      'missing_paths' => [],
      'source_equal_paths' => [],
      'unexpected_override_paths' => [],
    ];
    continue;
  }
  $englishData = $englishOverride->get();

  if (!$typedConfigManager->hasConfigSchema($name)) {
    $items[] = [
      'name' => $name,
      'classification' => 'schema_unresolved_review_required',
      'reason' => 'config_schema_missing',
      'material_translatable_leaf_count' => 0,
      'explicit_en_coverage_count' => 0,
      'missing_count' => 0,
      'covered_paths' => [],
      'missing_paths' => [],
      'source_equal_paths' => [],
      'unexpected_override_paths' => array_column(
        $collectRawLeafPaths($englishData),
        'path',
      ),
    ];
    continue;
  }

  try {
    $typedSource = $typedConfigManager->createFromNameAndData(
      $name,
      $sourceData,
    );
    $allTranslatableLeaves = $collectTranslatableLeaves($typedSource);
  }
  catch (Throwable $exception) {
    $items[] = [
      'name' => $name,
      'classification' => 'schema_unresolved_review_required',
      'reason' => 'typed_config_traversal_failed',
      'error_class' => $exception::class,
      'material_translatable_leaf_count' => 0,
      'explicit_en_coverage_count' => 0,
      'missing_count' => 0,
      'covered_paths' => [],
      'missing_paths' => [],
      'source_equal_paths' => [],
      'unexpected_override_paths' => array_column(
        $collectRawLeafPaths($englishData),
        'path',
      ),
    ];
    continue;
  }

  $translatableByKey = [];
  $materialLeaves = [];
  foreach ($allTranslatableLeaves as $leaf) {
    $translatableByKey[$leaf['key']] = TRUE;
    if ($isMaterial($leaf['value'])) {
      $materialLeaves[] = $leaf;
    }
  }

  $coveredPaths = [];
  $missingPaths = [];
  $sourceEqualPaths = [];
  foreach ($materialLeaves as $leaf) {
    $keyExists = FALSE;
    $overrideValue = NestedArray::getValue(
      $englishData,
      $leaf['segments'],
      $keyExists,
    );
    if (!$keyExists) {
      $missingPaths[] = $leaf['path'];
      continue;
    }

    $coveredPaths[] = $leaf['path'];
    if ($overrideValue === $leaf['value']) {
      $sourceEqualPaths[] = $leaf['path'];
    }
  }

  $unexpectedOverridePaths = [];
  foreach ($collectRawLeafPaths($englishData) as $overrideLeaf) {
    if (!isset($translatableByKey[$overrideLeaf['key']])) {
      $unexpectedOverridePaths[] = $overrideLeaf['path'];
    }
  }

  sort($coveredPaths, SORT_STRING);
  sort($missingPaths, SORT_STRING);
  sort($sourceEqualPaths, SORT_STRING);
  sort($unexpectedOverridePaths, SORT_STRING);

  $classification = 'en_override_complete_for_material_translatable_source';
  $reason = 'all_material_translatable_source_leaves_explicitly_overridden';
  if ($unexpectedOverridePaths !== []) {
    $classification = 'schema_unresolved_review_required';
    $reason = 'override_contains_non_translatable_or_unresolved_paths';
  }
  elseif ($missingPaths !== []) {
    $classification = 'en_override_partial_review_required';
    $reason = 'material_translatable_source_leaves_missing_from_en_override';
  }

  $allExplicitOverridesEqualSource = $coveredPaths !== []
    && count($sourceEqualPaths) === count($coveredPaths);

  $items[] = [
    'name' => $name,
    'classification' => $classification,
    'reason' => $reason,
    'material_translatable_leaf_count' => count($materialLeaves),
    'explicit_en_coverage_count' => count($coveredPaths),
    'missing_count' => count($missingPaths),
    'source_equal_explicit_override_count' => count($sourceEqualPaths),
    'signals' => [
      'en_override_redundant_or_source_equal' => $allExplicitOverridesEqualSource,
    ],
    'covered_paths' => $coveredPaths,
    'missing_paths' => $missingPaths,
    'source_equal_paths' => $sourceEqualPaths,
    'unexpected_override_paths' => $unexpectedOverridePaths,
  ];
}

usort(
  $items,
  static fn(array $left, array $right): int => $left['name'] <=> $right['name'],
);

$counts = [
  'candidate_fr_base_with_en_override' => count($candidateNames),
  'classified' => count($items),
  'baseline_problem' => count($baselineProblems),
  'en_override_complete_for_material_translatable_source' => 0,
  'en_override_partial_review_required' => 0,
  'schema_unresolved_review_required' => 0,
  'en_override_redundant_or_source_equal' => 0,
];
foreach ($items as $item) {
  $classification = (string) $item['classification'];
  if (array_key_exists($classification, $counts)) {
    $counts[$classification]++;
  }
  if (($item['signals']['en_override_redundant_or_source_equal'] ?? FALSE) === TRUE) {
    $counts['en_override_redundant_or_source_equal']++;
  }
}

$webformContact = NULL;
foreach ($items as $item) {
  if ($item['name'] === 'webform.webform.contact') {
    $webformContact = $item;
    break;
  }
}

$status = 'PASS';
$verdict = 'COVERAGE_CLASSIFIED';
if (
  count($candidateNames) !== $expectedCandidateCount
  || $baselineProblems !== []
  || count($items) !== $expectedCandidateCount
) {
  $status = 'FAIL';
  $verdict = 'BASELINE_DRIFT';
}
elseif ($webformContact === NULL) {
  $status = 'FAIL';
  $verdict = 'CONTROL_CASE_MISSING';
}
elseif (
  $counts['en_override_partial_review_required'] > 0
  || $counts['schema_unresolved_review_required'] > 0
) {
  $verdict = 'REVIEW_REQUIRED';
}

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'baseline' => [
    'baseline_id' => $baseline['baseline_id'] ?? NULL,
    'expected_fr_base_with_en_override' => $expectedCandidateCount,
  ],
  'counts' => $counts,
  'baseline_problems' => $baselineProblems,
  'focus' => [
    'webform.webform.contact' => $webformContact,
  ],
  'items' => $items,
  'constraints' => [
    'read_only' => TRUE,
    'bulk_langcode_replacement_allowed' => FALSE,
    'configuration_migration_allowed_by_this_proof' => FALSE,
    'config_language_lock_activation_allowed_by_this_proof' => FALSE,
    'config_export_allowed_by_this_proof' => FALSE,
    'production_mutation_allowed_by_this_proof' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

<?php

declare(strict_types=1);

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

$expectedByType = [
  'entity_form_display' => 11,
  'entity_view_display' => 10,
  'field_storage_config' => 6,
  'language_content_settings' => 14,
];
$expectedCandidateCount = array_sum($expectedByType);

$baselineByType = $baseline['migration_analysis']['fr_without_en_override_by_entity_type'] ?? [];
foreach ($expectedByType as $type => $expected) {
  if ((int) ($baselineByType[$type] ?? -1) !== $expected) {
    throw new RuntimeException("Unexpected #609 technical baseline count for $type.");
  }
}

$typedConfigManager = \Drupal::service('config.typed');
$configFactory = \Drupal::service('config.factory');

/**
 * Maps a configuration name to the bounded technical cohort.
 */
$configType = static function (string $name): ?string {
  return match (TRUE) {
    str_starts_with($name, 'core.entity_form_display.') => 'entity_form_display',
    str_starts_with($name, 'core.entity_view_display.') => 'entity_view_display',
    str_starts_with($name, 'field.storage.') => 'field_storage_config',
    str_starts_with($name, 'language.content_settings.') => 'language_content_settings',
    default => NULL,
  };
};

/**
 * Returns whether a translatable source value is materially present.
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

$baseFiles = glob($configDirectory . '/*.yml');
if ($baseFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate base configuration.');
}
sort($baseFiles, SORT_STRING);

$candidates = [];
foreach ($baseFiles as $basePath) {
  $name = basename($basePath, '.yml');
  $type = $configType($name);
  if ($type === NULL) {
    continue;
  }

  $baseData = Yaml::parseFile($basePath);
  if (!is_array($baseData) || ($baseData['langcode'] ?? NULL) !== 'fr') {
    continue;
  }

  if (is_file($configDirectory . '/language/en/' . $name . '.yml')) {
    continue;
  }

  $candidates[$name] = $type;
}
ksort($candidates, SORT_STRING);

$candidateCountsByType = array_fill_keys(array_keys($expectedByType), 0);
foreach ($candidates as $type) {
  $candidateCountsByType[$type]++;
}

$items = [];
$baselineProblems = [];
foreach ($candidates as $name => $type) {
  $sourceConfig = $configFactory->getEditable($name);
  $sourceData = $sourceConfig->get();
  if ($sourceConfig->isNew() || ($sourceData['langcode'] ?? NULL) !== 'fr') {
    $baselineProblems[] = [
      'name' => $name,
      'type' => $type,
      'reason' => $sourceConfig->isNew()
        ? 'active_config_missing'
        : 'active_source_langcode_not_fr',
    ];
    continue;
  }

  if (!$typedConfigManager->hasConfigSchema($name)) {
    $items[] = [
      'name' => $name,
      'type' => $type,
      'classification' => 'schema_unresolved_review_required',
      'reason' => 'config_schema_missing',
      'material_translatable_leaf_count' => 0,
      'material_translatable_paths' => [],
    ];
    continue;
  }

  try {
    $typedSource = $typedConfigManager->createFromNameAndData($name, $sourceData);
    $allTranslatableLeaves = $collectTranslatableLeaves($typedSource);
  }
  catch (Throwable $exception) {
    $items[] = [
      'name' => $name,
      'type' => $type,
      'classification' => 'schema_unresolved_review_required',
      'reason' => 'typed_config_traversal_failed',
      'error_class' => $exception::class,
      'material_translatable_leaf_count' => 0,
      'material_translatable_paths' => [],
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

  $classification = $materialPaths === []
    ? 'no_material_translatable_source_candidate'
    : 'material_translatable_source_review_required';
  $reason = $materialPaths === []
    ? 'typed_schema_has_no_material_translatable_source_leaves'
    : 'typed_schema_has_material_translatable_source_leaves';

  $items[] = [
    'name' => $name,
    'type' => $type,
    'classification' => $classification,
    'reason' => $reason,
    'material_translatable_leaf_count' => count($materialPaths),
    'material_translatable_paths' => $materialPaths,
  ];
}

usort(
  $items,
  static fn(array $left, array $right): int => $left['name'] <=> $right['name'],
);

$counts = [
  'candidate_technical_fr_base_without_en_override' => count($candidates),
  'classified' => count($items),
  'baseline_problem' => count($baselineProblems),
  'no_material_translatable_source_candidate' => 0,
  'material_translatable_source_review_required' => 0,
  'schema_unresolved_review_required' => 0,
];
$classifiedByType = [];
foreach ($expectedByType as $type => $expected) {
  $classifiedByType[$type] = [
    'expected' => $expected,
    'candidate' => $candidateCountsByType[$type],
    'classified' => 0,
    'no_material_translatable_source_candidate' => 0,
    'material_translatable_source_review_required' => 0,
    'schema_unresolved_review_required' => 0,
  ];
}
foreach ($items as $item) {
  $classification = (string) $item['classification'];
  $type = (string) $item['type'];
  $counts[$classification]++;
  $classifiedByType[$type]['classified']++;
  $classifiedByType[$type][$classification]++;
}

$status = 'PASS';
$verdict = 'TECHNICAL_COHORT_CLASSIFIED';
if (
  count($candidates) !== $expectedCandidateCount
  || count($items) !== $expectedCandidateCount
  || $baselineProblems !== []
  || $candidateCountsByType !== $expectedByType
) {
  $status = 'FAIL';
  $verdict = 'BASELINE_DRIFT';
}
elseif (
  $counts['material_translatable_source_review_required'] > 0
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
    'expected_candidate_count' => $expectedCandidateCount,
    'expected_by_type' => $expectedByType,
  ],
  'counts' => $counts,
  'by_type' => $classifiedByType,
  'baseline_problems' => $baselineProblems,
  'items' => $items,
  'constraints' => [
    'read_only' => TRUE,
    'bulk_langcode_replacement_allowed' => FALSE,
    'configuration_migration_allowed_by_this_proof' => FALSE,
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

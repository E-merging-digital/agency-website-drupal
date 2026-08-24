<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$policyPath = $projectRoot . '/docs/configuration-language-policy.yml';
$priorEvidence = $projectRoot . '/docs/evidence/configuration-language-safe-provenance-cohort-754.yml';

if (!is_dir($configDirectory) || !is_file($policyPath) || !is_file($priorEvidence)) {
  throw new RuntimeException('Post-#754 configuration-language baseline is incomplete.');
}

$policy = Yaml::parseFile($policyPath);
if (!is_array($policy)) {
  throw new RuntimeException('Configuration language policy must be a mapping.');
}
if (($policy['canonical_configuration_language'] ?? NULL) !== 'en') {
  throw new RuntimeException('Canonical configuration language must remain en.');
}
if (($policy['enforce_consistency'] ?? TRUE) !== FALSE) {
  throw new RuntimeException('Runtime provenance classification must run before enforcement.');
}

$typedConfigManager = \Drupal::service('config.typed');
$configFactory = \Drupal::service('config.factory');
$entityFieldManager = \Drupal::service('entity_field.manager');

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

$resolveSourceValue = static function (mixed $value): array {
  if ($value instanceof TranslatableMarkup) {
    return [
      'resolved' => TRUE,
      'value' => $value->getUntranslatedString(),
      'source_kind' => 'translatable_markup_untranslated_source',
    ];
  }
  if ($value === NULL || is_scalar($value)) {
    return [
      'resolved' => TRUE,
      'value' => $value,
      'source_kind' => 'scalar_runtime_value',
    ];
  }

  return [
    'resolved' => FALSE,
    'value' => NULL,
    'source_kind' => get_debug_type($value),
  ];
};

$baseFiles = glob($configDirectory . '/core.base_field_override.*.yml');
if ($baseFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate core.base_field_override configuration.');
}
sort($baseFiles, SORT_STRING);

$candidates = [];
foreach ($baseFiles as $basePath) {
  $repositoryData = Yaml::parseFile($basePath);
  if (!is_array($repositoryData) || ($repositoryData['langcode'] ?? NULL) !== 'fr') {
    continue;
  }

  $name = basename($basePath, '.yml');
  if (is_file($configDirectory . '/language/en/' . $name . '.yml')) {
    continue;
  }
  $candidates[$name] = $repositoryData;
}
ksort($candidates, SORT_STRING);

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

  $entityTypeId = isset($sourceData['entity_type']) && is_string($sourceData['entity_type'])
    ? $sourceData['entity_type']
    : '';
  $bundle = isset($sourceData['bundle']) && is_string($sourceData['bundle'])
    ? $sourceData['bundle']
    : '';
  $fieldName = isset($sourceData['field_name']) && is_string($sourceData['field_name'])
    ? $sourceData['field_name']
    : '';

  $itemBase = [
    'name' => $name,
    'entity_type' => $entityTypeId,
    'bundle' => $bundle,
    'field_name' => $fieldName,
  ];

  if ($entityTypeId === '' || $fieldName === '') {
    $items[] = $itemBase + [
      'classification' => 'runtime_base_definition_unresolved_review_required',
      'reason' => 'override_identity_incomplete',
      'material_translatable_leaf_count' => 0,
      'comparisons' => [],
    ];
    continue;
  }

  if (!$typedConfigManager->hasConfigSchema($name)) {
    $items[] = $itemBase + [
      'classification' => 'runtime_base_definition_unresolved_review_required',
      'reason' => 'config_schema_missing',
      'material_translatable_leaf_count' => 0,
      'comparisons' => [],
    ];
    continue;
  }

  try {
    $typedSource = $typedConfigManager->createFromNameAndData($name, $sourceData);
    $allLeaves = $collectTranslatableLeaves($typedSource);
  }
  catch (Throwable $exception) {
    $items[] = $itemBase + [
      'classification' => 'runtime_base_definition_unresolved_review_required',
      'reason' => 'typed_config_traversal_failed',
      'error_class' => $exception::class,
      'material_translatable_leaf_count' => 0,
      'comparisons' => [],
    ];
    continue;
  }

  $materialLeaves = [];
  foreach ($allLeaves as $leaf) {
    if ($isMaterial($leaf['value'])) {
      $materialLeaves[] = $leaf;
    }
  }
  usort(
    $materialLeaves,
    static fn(array $left, array $right): int => $left['path'] <=> $right['path'],
  );

  try {
    $baseDefinitions = $entityFieldManager->getBaseFieldDefinitions($entityTypeId);
  }
  catch (Throwable $exception) {
    $items[] = $itemBase + [
      'classification' => 'runtime_base_definition_unresolved_review_required',
      'reason' => 'base_field_definitions_resolution_failed',
      'error_class' => $exception::class,
      'material_translatable_leaf_count' => count($materialLeaves),
      'comparisons' => [],
    ];
    continue;
  }

  $baseDefinition = $baseDefinitions[$fieldName] ?? NULL;
  if ($baseDefinition === NULL) {
    $items[] = $itemBase + [
      'classification' => 'runtime_base_definition_unresolved_review_required',
      'reason' => 'base_field_definition_missing',
      'material_translatable_leaf_count' => count($materialLeaves),
      'comparisons' => [],
    ];
    continue;
  }

  $settings = $baseDefinition->getSettings();
  $comparisons = [];
  $unresolved = FALSE;
  $mismatch = FALSE;
  foreach ($materialLeaves as $leaf) {
    $segments = $leaf['segments'];
    $runtimeRaw = NULL;
    $runtimeFound = TRUE;
    $resolver = NULL;

    if ($segments === ['label']) {
      $runtimeRaw = $baseDefinition->getLabel();
      $resolver = 'base_definition.label';
    }
    elseif ($segments === ['description']) {
      $runtimeRaw = $baseDefinition->getDescription();
      $resolver = 'base_definition.description';
    }
    elseif (($segments[0] ?? NULL) === 'settings' && count($segments) > 1) {
      $relativeSegments = array_slice($segments, 1);
      $runtimeRaw = NestedArray::getValue($settings, $relativeSegments, $runtimeFound);
      $resolver = 'base_definition.settings';
    }
    else {
      $runtimeFound = FALSE;
      $resolver = 'unsupported_typed_path';
    }

    if (!$runtimeFound) {
      $unresolved = TRUE;
      $comparisons[] = [
        'path' => $leaf['path'],
        'config_value' => $leaf['value'],
        'runtime_source_value' => NULL,
        'resolver' => $resolver,
        'resolved' => FALSE,
        'matches' => FALSE,
      ];
      continue;
    }

    $resolvedSource = $resolveSourceValue($runtimeRaw);
    if (!$resolvedSource['resolved']) {
      $unresolved = TRUE;
      $comparisons[] = [
        'path' => $leaf['path'],
        'config_value' => $leaf['value'],
        'runtime_source_value' => NULL,
        'resolver' => $resolver,
        'source_kind' => $resolvedSource['source_kind'],
        'resolved' => FALSE,
        'matches' => FALSE,
      ];
      continue;
    }

    $matches = $resolvedSource['value'] === $leaf['value'];
    if (!$matches) {
      $mismatch = TRUE;
    }
    $comparisons[] = [
      'path' => $leaf['path'],
      'config_value' => $leaf['value'],
      'runtime_source_value' => $resolvedSource['value'],
      'resolver' => $resolver,
      'source_kind' => $resolvedSource['source_kind'],
      'resolved' => TRUE,
      'matches' => $matches,
    ];
  }

  if ($materialLeaves === []) {
    $classification = 'runtime_base_definition_unresolved_review_required';
    $reason = 'no_material_translatable_source_leaves';
  }
  elseif ($unresolved) {
    $classification = 'runtime_base_definition_unresolved_review_required';
    $reason = 'runtime_source_resolution_incomplete';
  }
  elseif ($mismatch) {
    $classification = 'runtime_base_definition_review_required';
    $reason = 'material_translatable_source_differs_from_runtime_base_definition';
  }
  else {
    $classification = 'runtime_base_definition_exact_match_candidate';
    $reason = 'all_material_translatable_leaves_match_untranslated_runtime_base_definition';
  }

  $items[] = $itemBase + [
    'classification' => $classification,
    'reason' => $reason,
    'base_definition_class' => $baseDefinition::class,
    'material_translatable_leaf_count' => count($materialLeaves),
    'comparisons' => $comparisons,
  ];
}

usort($items, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

$classificationNames = [
  'runtime_base_definition_exact_match_candidate' => [],
  'runtime_base_definition_review_required' => [],
  'runtime_base_definition_unresolved_review_required' => [],
];
foreach ($items as $item) {
  $classificationNames[$item['classification']][] = $item['name'];
}
foreach ($classificationNames as &$names) {
  sort($names, SORT_STRING);
}
unset($names);

$counts = [
  'candidate_core_base_field_override_fr_without_en_override' => count($candidates),
  'classified' => count($items),
  'baseline_problem' => count($baselineProblems),
  'runtime_base_definition_exact_match_candidate' => count($classificationNames['runtime_base_definition_exact_match_candidate']),
  'runtime_base_definition_review_required' => count($classificationNames['runtime_base_definition_review_required']),
  'runtime_base_definition_unresolved_review_required' => count($classificationNames['runtime_base_definition_unresolved_review_required']),
];

$status = (
  $counts['candidate_core_base_field_override_fr_without_en_override'] === 53
  && $counts['classified'] === 53
  && $counts['baseline_problem'] === 0
) ? 'PASS' : 'FAIL';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => 'BASE_FIELD_RUNTIME_PROVENANCE_CLASSIFIED',
  'baseline' => [
    'expected_total_config' => 595,
    'expected_distribution' => [
      '__none__' => 59,
      'en' => 413,
      'fr' => 122,
      'und' => 1,
    ],
  ],
  'counts' => $counts,
  'names_by_classification' => $classificationNames,
  'baseline_problems' => $baselineProblems,
  'items' => $items,
  'constraints' => [
    'read_only' => TRUE,
    'natural_language_heuristic_used' => FALSE,
    'runtime_source_uses_untranslated_translatable_markup' => TRUE,
    'configuration_language_lock_enabled_canonically' => FALSE,
    'migration_authorized' => FALSE,
  ],
];

print json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($status !== 'PASS') {
  exit(1);
}

<?php

declare(strict_types=1);

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';

$expectedNames = [
  'canvas.component.sdc.emerging_digital.cta',
  'canvas.component.sdc.emerging_digital.hero',
  'canvas.component.sdc.emerging_digital.trust-list',
  'canvas.component.sdc.olivero.teaser',
];
sort($expectedNames, SORT_STRING);
$expectedNamesHash = 'b1babac65a7a0d0e6e22f776fc26cb486dea0882b2d27d98daed2a8f2f5cbe71';
if (hash('sha256', implode("\n", $expectedNames) . "\n") !== $expectedNamesHash) {
  throw new RuntimeException('Issue #772 fixed SDC cohort hash mismatch.');
}

if (!is_dir($configDirectory)) {
  throw new RuntimeException('Issue #772 config directory is unavailable.');
}

$displayPath = static function (array $segments): string {
  return implode('.', array_map(
    static fn(string|int $segment): string => (string) $segment,
    $segments,
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

$normalizeForEvidence = NULL;
$normalizeForEvidence = static function (
  mixed $value,
  array $segments = [],
) use (&$normalizeForEvidence, $displayPath): mixed {
  if ($value instanceof TranslatableMarkup) {
    return [
      '__kind' => 'translatable_markup',
      '__class' => $value::class,
      'untranslated' => $value->getUntranslatedString(),
    ];
  }

  if (is_array($value)) {
    $normalized = [];
    $keys = array_keys($value);
    $isList = $keys === range(0, count($keys) - 1);
    if (!$isList) {
      sort($keys, SORT_STRING);
    }
    foreach ($keys as $key) {
      $normalized[$key] = $normalizeForEvidence(
        $value[$key],
        [...$segments, $key],
      );
    }
    return $normalized;
  }

  if ($value instanceof BackedEnum) {
    return [
      '__kind' => 'backed_enum',
      '__class' => $value::class,
      'name' => $value->name,
      'value' => $value->value,
    ];
  }

  if ($value instanceof UnitEnum) {
    return [
      '__kind' => 'unit_enum',
      '__class' => $value::class,
      'name' => $value->name,
    ];
  }

  if (is_object($value)) {
    return [
      '__kind' => 'object',
      '__class' => $value::class,
      '__path' => $displayPath($segments),
    ];
  }

  if (is_scalar($value) || $value === NULL) {
    return $value;
  }

  return [
    '__kind' => 'unsupported',
    '__type' => get_debug_type($value),
    '__path' => $displayPath($segments),
  ];
};

$collectComparableScalars = NULL;
$collectComparableScalars = static function (
  mixed $value,
  string $payload,
  array $segments = [],
) use (&$collectComparableScalars, $displayPath): array {
  if ($value instanceof TranslatableMarkup) {
    return [[
      'payload' => $payload,
      'path' => $displayPath([...$segments, 'untranslated']),
      'kind' => 'translatable_markup_untranslated',
      'value_type' => 'string',
      'value' => $value->getUntranslatedString(),
    ]];
  }

  if (is_array($value)) {
    $scalars = [];
    foreach ($value as $key => $child) {
      foreach (
        $collectComparableScalars($child, $payload, [...$segments, $key])
        as $scalar
      ) {
        $scalars[] = $scalar;
      }
    }
    return $scalars;
  }

  if (is_scalar($value)) {
    return [[
      'payload' => $payload,
      'path' => $displayPath($segments),
      'kind' => 'native_scalar',
      'value_type' => get_debug_type($value),
      'value' => $value,
    ]];
  }

  return [];
};

$typedConfigManager = \Drupal::service('config.typed');
$entityTypeManager = \Drupal::entityTypeManager();
$container = \Drupal::getContainer();
if (!$container->has('plugin.manager.sdc')) {
  throw new RuntimeException('Issue #772 requires plugin.manager.sdc.');
}
$sdcManager = $container->get('plugin.manager.sdc');

$componentEntityTypeIds = [];
foreach ($entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
  if (
    $definition instanceof \Drupal\Core\Config\Entity\ConfigEntityTypeInterface
    && $definition->getConfigPrefix() === 'canvas.component'
  ) {
    $componentEntityTypeIds[] = $entityTypeId;
  }
}
sort($componentEntityTypeIds, SORT_STRING);
if (count($componentEntityTypeIds) !== 1) {
  throw new RuntimeException(sprintf(
    'Expected exactly one Canvas component config entity type, found %d.',
    count($componentEntityTypeIds),
  ));
}
$componentEntityTypeId = $componentEntityTypeIds[0];
$componentStorage = $entityTypeManager->getStorage($componentEntityTypeId);

$baselineProblems = [];
$items = [];
$totalMaterialLeaves = 0;
$totalComponentSourceMatched = 0;
$totalSdcMetadataMatched = 0;
$totalNewlyExplainedBySdc = 0;
$totalFinalMatched = 0;
$totalFinalUnmatched = 0;
$allPresent = 0;
$partial = 0;
$none = 0;
$unresolved = 0;

foreach ($expectedNames as $configName) {
  $path = $configDirectory . '/' . $configName . '.yml';
  if (!is_file($path)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_config_missing',
    ];
    $unresolved++;
    continue;
  }

  $repositoryData = Yaml::parseFile($path);
  if (!is_array($repositoryData)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_config_invalid',
    ];
    $unresolved++;
    continue;
  }

  if (($repositoryData['langcode'] ?? NULL) !== 'fr') {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_langcode_not_fr',
      'actual' => $repositoryData['langcode'] ?? NULL,
    ];
    $unresolved++;
    continue;
  }
  if (($repositoryData['source'] ?? NULL) !== 'sdc') {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_source_not_sdc',
      'actual' => $repositoryData['source'] ?? NULL,
    ];
    $unresolved++;
    continue;
  }
  if (is_file($configDirectory . '/language/en/' . $configName . '.yml')) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'unexpected_en_override',
    ];
    $unresolved++;
    continue;
  }

  $entityId = $repositoryData['id'] ?? NULL;
  $repositorySourceLocalId = $repositoryData['source_local_id'] ?? NULL;
  if (
    !is_string($entityId)
    || $entityId === ''
    || !is_string($repositorySourceLocalId)
    || $repositorySourceLocalId === ''
  ) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_source_identity_invalid',
    ];
    $unresolved++;
    continue;
  }

  $entity = $componentStorage->load($entityId);
  if ($entity === NULL || !method_exists($entity, 'getComponentSource')) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'runtime_component_source_unavailable',
    ];
    $unresolved++;
    continue;
  }

  $runtimeData = $entity->toArray();
  foreach ([
    'source' => 'sdc',
    'source_local_id' => $repositorySourceLocalId,
    'provider' => $repositoryData['provider'] ?? NULL,
  ] as $key => $expectedValue) {
    if (($runtimeData[$key] ?? NULL) !== $expectedValue) {
      $baselineProblems[] = [
        'name' => $configName,
        'reason' => 'runtime_source_identity_mismatch',
        'key' => $key,
        'expected' => $expectedValue,
        'actual' => $runtimeData[$key] ?? NULL,
      ];
      $unresolved++;
      continue 2;
    }
  }

  $sourceObject = $entity->getComponentSource();
  if (
    !is_object($sourceObject)
    || !method_exists($sourceObject, 'getSourceSpecificComponentId')
    || !method_exists($sourceObject, 'getConfiguration')
  ) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'runtime_sdc_source_api_incomplete',
    ];
    $unresolved++;
    continue;
  }

  $sourceSpecificId = $sourceObject->getSourceSpecificComponentId();
  if (
    !is_string($sourceSpecificId)
    || $sourceSpecificId === ''
    || $sourceSpecificId !== $repositorySourceLocalId
  ) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'source_specific_component_id_mismatch',
      'expected' => $repositorySourceLocalId,
      'actual' => $sourceSpecificId,
    ];
    $unresolved++;
    continue;
  }

  if (!$sdcManager->hasDefinition($sourceSpecificId)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'sdc_definition_missing',
      'source_specific_component_id' => $sourceSpecificId,
    ];
    $unresolved++;
    continue;
  }

  $sdcDefinition = $sdcManager->getDefinition($sourceSpecificId, FALSE);
  if (!is_array($sdcDefinition)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'sdc_definition_not_array',
      'source_specific_component_id' => $sourceSpecificId,
      'actual_type' => get_debug_type($sdcDefinition),
    ];
    $unresolved++;
    continue;
  }

  if (($sdcDefinition['id'] ?? NULL) !== $sourceSpecificId) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'sdc_definition_id_mismatch',
      'expected' => $sourceSpecificId,
      'actual' => $sdcDefinition['id'] ?? NULL,
    ];
    $unresolved++;
    continue;
  }

  $typed = $typedConfigManager->get($configName);
  if (!$typed instanceof TypedDataInterface) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'typed_config_unavailable',
    ];
    $unresolved++;
    continue;
  }

  $allTranslatableLeaves = $collectTranslatableLeaves($typed);
  $materialLeaves = array_values(array_filter(
    $allTranslatableLeaves,
    static fn(array $leaf): bool => $isMaterial($leaf['value'] ?? NULL),
  ));

  $componentSourceConfiguration = $sourceObject->getConfiguration();
  $componentSourceScalars = $collectComparableScalars(
    $componentSourceConfiguration,
    'component_source_configuration',
    ['component_source_configuration'],
  );
  $sdcDefinitionScalars = $collectComparableScalars(
    $sdcDefinition,
    'sdc_definition',
    ['sdc_definition'],
  );
  $allNativeScalars = [
    ...$componentSourceScalars,
    ...$sdcDefinitionScalars,
  ];

  $componentSourceMatchedLeafCount = 0;
  $sdcMetadataMatchedLeafCount = 0;
  $newlyExplainedBySdcCount = 0;
  $finalMatchedLeafCount = 0;
  $evidenceLeaves = [];

  foreach ($materialLeaves as $leaf) {
    $value = $leaf['value'];
    $valueType = get_debug_type($value);
    $matches = [];
    foreach ($allNativeScalars as $scalar) {
      if (
        $scalar['value_type'] === $valueType
        && $scalar['value'] === $value
      ) {
        $matches[] = [
          'payload' => $scalar['payload'],
          'path' => $scalar['path'],
          'kind' => $scalar['kind'],
        ];
      }
    }

    $componentSourceMatches = array_values(array_filter(
      $matches,
      static fn(array $match): bool =>
        $match['payload'] === 'component_source_configuration',
    ));
    $sdcMetadataMatches = array_values(array_filter(
      $matches,
      static fn(array $match): bool => $match['payload'] === 'sdc_definition',
    ));

    if ($componentSourceMatches !== []) {
      $componentSourceMatchedLeafCount++;
    }
    if ($sdcMetadataMatches !== []) {
      $sdcMetadataMatchedLeafCount++;
    }
    if ($componentSourceMatches === [] && $sdcMetadataMatches !== []) {
      $newlyExplainedBySdcCount++;
    }
    if ($matches !== []) {
      $finalMatchedLeafCount++;
    }

    $evidenceLeaves[] = [
      'path' => $leaf['path'],
      'value' => $value,
      'value_type' => $valueType,
      'component_source_matches' => $componentSourceMatches,
      'sdc_metadata_matches' => $sdcMetadataMatches,
      'final_matched' => $matches !== [],
      'newly_explained_by_sdc_metadata' =>
        $componentSourceMatches === [] && $sdcMetadataMatches !== [],
    ];
  }

  $materialLeafCount = count($materialLeaves);
  $finalUnmatchedLeafCount = $materialLeafCount - $finalMatchedLeafCount;
  if ($materialLeafCount === 0) {
    $classification = 'no_material_translatable_leaves';
  }
  elseif ($finalMatchedLeafCount === $materialLeafCount) {
    $classification = 'all_material_values_present_in_combined_native_sources';
    $allPresent++;
  }
  elseif ($finalMatchedLeafCount > 0) {
    $classification = 'partial_material_values_present_in_combined_native_sources';
    $partial++;
  }
  else {
    $classification = 'no_material_values_present_in_combined_native_sources';
    $none++;
  }

  $totalMaterialLeaves += $materialLeafCount;
  $totalComponentSourceMatched += $componentSourceMatchedLeafCount;
  $totalSdcMetadataMatched += $sdcMetadataMatchedLeafCount;
  $totalNewlyExplainedBySdc += $newlyExplainedBySdcCount;
  $totalFinalMatched += $finalMatchedLeafCount;
  $totalFinalUnmatched += $finalUnmatchedLeafCount;

  $items[] = [
    'config_name' => $configName,
    'entity_id' => $entityId,
    'source' => 'sdc',
    'source_specific_component_id' => $sourceSpecificId,
    'source_class' => $sourceObject::class,
    'sdc_manager_class' => $sdcManager::class,
    'sdc_definition_provider' => $sdcDefinition['provider'] ?? NULL,
    'classification' => $classification,
    'material_translatable_leaf_count' => $materialLeafCount,
    'component_source_matched_leaf_count' => $componentSourceMatchedLeafCount,
    'sdc_metadata_matched_leaf_count' => $sdcMetadataMatchedLeafCount,
    'newly_explained_by_sdc_metadata_count' => $newlyExplainedBySdcCount,
    'final_matched_leaf_count' => $finalMatchedLeafCount,
    'final_unmatched_leaf_count' => $finalUnmatchedLeafCount,
    'material_translatable_leaves' => $evidenceLeaves,
    'native_source' => [
      'component_source_configuration' => $normalizeForEvidence(
        $componentSourceConfiguration,
        ['component_source_configuration'],
      ),
      'sdc_definition' => $normalizeForEvidence(
        $sdcDefinition,
        ['sdc_definition'],
      ),
    ],
  ];
}

usort(
  $items,
  static fn(array $left, array $right): int =>
    $left['config_name'] <=> $right['config_name'],
);

if (count($items) !== 4) {
  $baselineProblems[] = [
    'reason' => 'analyzed_component_count_mismatch',
    'actual' => count($items),
    'expected' => 4,
  ];
}
if ($totalMaterialLeaves !== 36) {
  $baselineProblems[] = [
    'reason' => 'material_translatable_leaf_baseline_mismatch',
    'actual' => $totalMaterialLeaves,
    'expected' => 36,
  ];
}
if ($totalComponentSourceMatched !== 8) {
  $baselineProblems[] = [
    'reason' => 'component_source_match_baseline_mismatch',
    'actual' => $totalComponentSourceMatched,
    'expected' => 8,
  ];
}
if (($totalMaterialLeaves - $totalComponentSourceMatched) !== 28) {
  $baselineProblems[] = [
    'reason' => 'pre_sdc_gap_baseline_mismatch',
    'actual' => $totalMaterialLeaves - $totalComponentSourceMatched,
    'expected' => 28,
  ];
}

$problems = $baselineProblems;
$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'SDC_NATIVE_METADATA_PROVENANCE_ANALYZED'
  : 'SDC_NATIVE_METADATA_PROVENANCE_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'cohort_names_sha256' => $expectedNamesHash,
  'runtime' => [
    'component_entity_type_id' => $componentEntityTypeId,
    'sdc_manager_service_id' => 'plugin.manager.sdc',
    'sdc_manager_class' => $sdcManager::class,
  ],
  'counts' => [
    'candidate_total' => 4,
    'analyzed' => count($items),
    'sdc' => count($items),
    'baseline_problem' => count($baselineProblems),
    'problem_count' => count($problems),
    'source_payload_unresolved' => $unresolved,
    'material_translatable_leaves' => $totalMaterialLeaves,
    'component_source_matched_leaves' => $totalComponentSourceMatched,
    'pre_sdc_gap_leaves' => $totalMaterialLeaves - $totalComponentSourceMatched,
    'sdc_metadata_matched_leaves' => $totalSdcMetadataMatched,
    'newly_explained_by_sdc_metadata_leaves' => $totalNewlyExplainedBySdc,
    'final_matched_leaves' => $totalFinalMatched,
    'final_unmatched_leaves' => $totalFinalUnmatched,
    'all_material_values_present_in_combined_native_sources' => $allPresent,
    'partial_material_values_present_in_combined_native_sources' => $partial,
    'no_material_values_present_in_combined_native_sources' => $none,
  ],
  'items' => $items,
  'problems' => $problems,
  'constraints' => [
    'read_only' => TRUE,
    'strict_type_and_value_equality_only' => TRUE,
    'source_specific_ids_from_runtime_only' => TRUE,
    'natural_language_heuristic_used' => FALSE,
    'fuzzy_matching_used' => FALSE,
    'value_presence_authorizes_migration' => FALSE,
    'source_generation_executed' => FALSE,
    'config_entity_creation_executed' => FALSE,
    'config_entity_update_executed' => FALSE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_access_allowed' => FALSE,
    'provider_secret_used' => FALSE,
    'executed_mutating_methods' => [],
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT
  | JSON_UNESCAPED_SLASHES
  | JSON_UNESCAPED_UNICODE
  | JSON_THROW_ON_ERROR,
) . PHP_EOL;

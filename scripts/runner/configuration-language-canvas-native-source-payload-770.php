<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot
  . '/docs/evidence/'
  . 'configuration-language-canvas-runtime-source-api-cohort-766.yml';

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #770 source baseline is incomplete.');
}

$manifest = Yaml::parseFile($manifestPath);
if (!is_array($manifest)) {
  throw new RuntimeException('Issue #766 manifest must be a mapping.');
}
if (($manifest['issue'] ?? NULL) !== 766 || ($manifest['parent_issue'] ?? NULL) !== 609) {
  throw new RuntimeException('Issue #770 must reuse the exact #766 cohort.');
}
if (($manifest['cohort']['total'] ?? NULL) !== 30) {
  throw new RuntimeException('Issue #770 requires exactly 30 Canvas components.');
}

$manifestNames = $manifest['cohort']['names'] ?? NULL;
if (!is_array($manifestNames) || count($manifestNames) !== 30) {
  throw new RuntimeException('Issue #766 manifest names are incomplete.');
}
$manifestNames = array_values(array_map('strval', $manifestNames));
sort($manifestNames, SORT_STRING);
if (count(array_unique($manifestNames)) !== 30) {
  throw new RuntimeException('Issue #766 manifest names must be unique.');
}
$manifestHash = hash('sha256', implode("\n", $manifestNames) . "\n");
if (
  $manifestHash
  !== 'b2ad9dcff4b65e56e2a76efefc55b508cf4012b6aaa18cba0c4879cf2f3dec23'
  || $manifestHash !== ($manifest['cohort']['names_sha256'] ?? NULL)
) {
  throw new RuntimeException('Issue #766 manifest names hash mismatch.');
}

$canvasVersion = InstalledVersions::getPrettyVersion('drupal/canvas');
if ($canvasVersion !== '1.10.1') {
  throw new RuntimeException(sprintf(
    'Issue #770 requires Canvas 1.10.1, got %s.',
    $canvasVersion ?? 'unknown',
  ));
}

$typedConfigManager = \Drupal::service('config.typed');
$activeStorage = \Drupal::service('config.storage');
$entityTypeManager = \Drupal::entityTypeManager();

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
  if (empty($definition['translatable'])) {
    return [];
  }

  return [[
    'segments' => $segments,
    'path' => $displayPath($segments),
    'value' => $element->getValue(),
  ]];
};

$serializationNotes = [];
$normalizePayload = NULL;
$normalizePayload = static function (
  mixed $value,
  string $path,
) use (&$normalizePayload, &$serializationNotes): mixed {
  if ($value === NULL || is_scalar($value)) {
    return $value;
  }
  if ($value instanceof TranslatableMarkup) {
    return [
      '__kind' => 'translatable_markup',
      '__class' => $value::class,
      'untranslated' => $value->getUntranslatedString(),
    ];
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
  if (is_array($value)) {
    $normalized = [];
    foreach ($value as $key => $child) {
      $normalized[$key] = $normalizePayload(
        $child,
        $path . '.' . (string) $key,
      );
    }
    if (!array_is_list($normalized)) {
      ksort($normalized, SORT_STRING);
    }
    return $normalized;
  }
  if (is_object($value)) {
    $public = get_object_vars($value);
    $serializationNotes[] = [
      'path' => $path,
      'representation' => 'object_class_and_public_properties_only',
      'class' => $value::class,
    ];
    return [
      '__kind' => 'object',
      '__class' => $value::class,
      'public' => $normalizePayload($public, $path . '.public'),
    ];
  }
  if (is_resource($value)) {
    throw new RuntimeException(sprintf(
      'Unsupported resource in native source payload at %s.',
      $path,
    ));
  }

  throw new RuntimeException(sprintf(
    'Unsupported native source payload value at %s.',
    $path,
  ));
};

$collectEvidenceScalars = NULL;
$collectEvidenceScalars = static function (
  mixed $value,
  string $path,
  string $payload,
) use (&$collectEvidenceScalars): array {
  if ($value === NULL) {
    return [];
  }
  if (is_scalar($value)) {
    return [[
      'payload' => $payload,
      'path' => $path,
      'kind' => 'native_scalar',
      'value' => $value,
    ]];
  }
  if ($value instanceof TranslatableMarkup) {
    return [[
      'payload' => $payload,
      'path' => $path . '.__untranslated',
      'kind' => 'translatable_markup_untranslated_source',
      'value' => $value->getUntranslatedString(),
    ]];
  }
  if ($value instanceof BackedEnum) {
    return [[
      'payload' => $payload,
      'path' => $path . '.__enum_value',
      'kind' => 'backed_enum_value',
      'value' => $value->value,
    ]];
  }
  if (is_array($value)) {
    $points = [];
    foreach ($value as $key => $child) {
      foreach (
        $collectEvidenceScalars(
          $child,
          $path . '.' . (string) $key,
          $payload,
        ) as $point
      ) {
        $points[] = $point;
      }
    }
    return $points;
  }

  return [];
};

$componentEntityTypeIds = [];
foreach ($entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
  if (
    $definition instanceof ConfigEntityTypeInterface
    && $definition->getConfigPrefix() === 'canvas.component'
  ) {
    $componentEntityTypeIds[] = $entityTypeId;
  }
}
sort($componentEntityTypeIds, SORT_STRING);
if (count($componentEntityTypeIds) !== 1) {
  throw new RuntimeException('Expected exactly one Canvas Component config entity type.');
}
$componentEntityTypeId = $componentEntityTypeIds[0];
$componentStorage = $entityTypeManager->getStorage($componentEntityTypeId);

$repositoryCanvasFrNames = [];
foreach (glob($configDirectory . '/canvas.component.*.yml') ?: [] as $path) {
  $data = Yaml::parseFile($path);
  if (is_array($data) && ($data['langcode'] ?? NULL) === 'fr') {
    $repositoryCanvasFrNames[] = basename($path, '.yml');
  }
}
sort($repositoryCanvasFrNames, SORT_STRING);

$baselineProblems = [];
if ($repositoryCanvasFrNames !== $manifestNames) {
  $baselineProblems[] = [
    'reason' => 'canvas_fr_name_set_mismatch',
    'missing' => array_values(array_diff($manifestNames, $repositoryCanvasFrNames)),
    'unexpected' => array_values(array_diff($repositoryCanvasFrNames, $manifestNames)),
  ];
}

$sourceCounts = ['block' => 0, 'sdc' => 0];
$classifications = [
  'all_material_values_present_in_native_source_payload' => 0,
  'partial_material_values_present_in_native_source_payload' => 0,
  'no_material_values_present_in_native_source_payload' => 0,
  'source_payload_unresolved' => 0,
];
$items = [];
$unmatchedLeaves = [];
$totalMaterialLeaves = 0;
$totalMatchedLeaves = 0;

foreach ($manifestNames as $configName) {
  $repositoryPath = $configDirectory . '/' . $configName . '.yml';
  $repositoryData = Yaml::parseFile($repositoryPath);
  if (!is_array($repositoryData)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_config_invalid',
    ];
    continue;
  }
  if (($repositoryData['langcode'] ?? NULL) !== 'fr') {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_langcode_not_fr',
    ];
    continue;
  }
  if (is_file($configDirectory . '/language/en/' . $configName . '.yml')) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'unexpected_en_override',
    ];
    continue;
  }

  $activeData = $activeStorage->read($configName);
  if (!is_array($activeData) || $activeData !== $repositoryData) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'active_repository_mismatch',
    ];
    continue;
  }
  if (!$typedConfigManager->hasConfigSchema($configName)) {
    $classifications['source_payload_unresolved']++;
    $items[] = [
      'config_name' => $configName,
      'classification' => 'source_payload_unresolved',
      'reason' => 'config_schema_missing',
    ];
    continue;
  }

  try {
    $typed = $typedConfigManager->createFromNameAndData($configName, $activeData);
    $allLeaves = $collectTranslatableLeaves($typed);
  }
  catch (Throwable $exception) {
    $classifications['source_payload_unresolved']++;
    $items[] = [
      'config_name' => $configName,
      'classification' => 'source_payload_unresolved',
      'reason' => 'typed_config_traversal_failed',
      'error_class' => $exception::class,
    ];
    continue;
  }

  $materialLeaves = [];
  foreach ($allLeaves as $leaf) {
    if (!$isMaterial($leaf['value'])) {
      continue;
    }
    if (!is_scalar($leaf['value'])) {
      $classifications['source_payload_unresolved']++;
      $items[] = [
        'config_name' => $configName,
        'classification' => 'source_payload_unresolved',
        'reason' => 'material_leaf_not_scalar',
        'leaf_path' => $leaf['path'],
        'leaf_type' => get_debug_type($leaf['value']),
      ];
      continue 2;
    }
    $materialLeaves[] = [
      'path' => $leaf['path'],
      'value' => $leaf['value'],
      'value_type' => get_debug_type($leaf['value']),
    ];
  }
  usort(
    $materialLeaves,
    static fn(array $left, array $right): int => $left['path'] <=> $right['path'],
  );

  $entityId = $repositoryData['id'] ?? NULL;
  $sourceKind = $repositoryData['source'] ?? NULL;
  $sourceLocalId = $repositoryData['source_local_id'] ?? NULL;
  $provider = $repositoryData['provider'] ?? NULL;
  if (
    !is_string($entityId)
    || !is_string($sourceKind)
    || !isset($sourceCounts[$sourceKind])
    || !is_string($sourceLocalId)
    || !is_string($provider)
  ) {
    $classifications['source_payload_unresolved']++;
    $items[] = [
      'config_name' => $configName,
      'classification' => 'source_payload_unresolved',
      'reason' => 'source_identity_invalid',
    ];
    continue;
  }

  $entity = $componentStorage->load($entityId);
  if ($entity === NULL || !method_exists($entity, 'getComponentSource')) {
    $classifications['source_payload_unresolved']++;
    $items[] = [
      'config_name' => $configName,
      'classification' => 'source_payload_unresolved',
      'reason' => 'runtime_component_or_source_missing',
    ];
    continue;
  }

  $runtimeData = $entity->toArray();
  if (
    ($runtimeData['source'] ?? NULL) !== $sourceKind
    || ($runtimeData['source_local_id'] ?? NULL) !== $sourceLocalId
    || ($runtimeData['provider'] ?? NULL) !== $provider
  ) {
    $classifications['source_payload_unresolved']++;
    $items[] = [
      'config_name' => $configName,
      'classification' => 'source_payload_unresolved',
      'reason' => 'runtime_source_identity_mismatch',
    ];
    continue;
  }

  try {
    $source = $entity->getComponentSource();
    $configuration = $source->getConfiguration();
    $pluginDefinition = $source->getPluginDefinition();
    $runtimeSourceLocalId = $source->getSourceSpecificComponentId();
    $versionHash = $source->generateVersionHash();
  }
  catch (Throwable $exception) {
    $classifications['source_payload_unresolved']++;
    $items[] = [
      'config_name' => $configName,
      'classification' => 'source_payload_unresolved',
      'reason' => 'native_source_payload_read_failed',
      'error_class' => $exception::class,
    ];
    continue;
  }

  if (
    !is_array($configuration)
    || !is_array($pluginDefinition)
    || !is_string($runtimeSourceLocalId)
    || $runtimeSourceLocalId !== $sourceLocalId
    || !is_string($versionHash)
    || $versionHash === ''
  ) {
    $classifications['source_payload_unresolved']++;
    $items[] = [
      'config_name' => $configName,
      'classification' => 'source_payload_unresolved',
      'reason' => 'native_source_payload_shape_invalid',
      'configuration_type' => get_debug_type($configuration),
      'definition_type' => get_debug_type($pluginDefinition),
      'runtime_source_local_id' => $runtimeSourceLocalId,
      'version_hash_type' => get_debug_type($versionHash),
    ];
    continue;
  }

  try {
    $normalizedConfiguration = $normalizePayload(
      $configuration,
      'configuration',
    );
    $normalizedDefinition = $normalizePayload(
      $pluginDefinition,
      'plugin_definition',
    );
  }
  catch (Throwable $exception) {
    $classifications['source_payload_unresolved']++;
    $items[] = [
      'config_name' => $configName,
      'classification' => 'source_payload_unresolved',
      'reason' => 'native_source_payload_serialization_failed',
      'error_class' => $exception::class,
    ];
    continue;
  }

  $evidencePoints = array_merge(
    $collectEvidenceScalars($configuration, 'configuration', 'configuration'),
    $collectEvidenceScalars(
      $pluginDefinition,
      'plugin_definition',
      'plugin_definition',
    ),
  );

  $leafEvidence = [];
  $matchedLeafCount = 0;
  foreach ($materialLeaves as $leaf) {
    $matches = [];
    foreach ($evidencePoints as $point) {
      if ($point['value'] === $leaf['value']) {
        $matches[] = [
          'payload' => $point['payload'],
          'path' => $point['path'],
          'kind' => $point['kind'],
        ];
      }
    }
    usort(
      $matches,
      static fn(array $left, array $right): int =>
        [$left['payload'], $left['path'], $left['kind']]
        <=> [$right['payload'], $right['path'], $right['kind']],
    );
    if ($matches !== []) {
      $matchedLeafCount++;
    }
    else {
      $unmatchedLeaves[] = [
        'config_name' => $configName,
        'source' => $sourceKind,
        'leaf_path' => $leaf['path'],
        'value' => $leaf['value'],
        'value_type' => $leaf['value_type'],
      ];
    }
    $leafEvidence[] = [
      ...$leaf,
      'strict_native_matches' => $matches,
      'matched' => $matches !== [],
    ];
  }

  $materialLeafCount = count($materialLeaves);
  if ($materialLeafCount > 0 && $matchedLeafCount === $materialLeafCount) {
    $classification = 'all_material_values_present_in_native_source_payload';
  }
  elseif ($matchedLeafCount > 0) {
    $classification = 'partial_material_values_present_in_native_source_payload';
  }
  else {
    $classification = 'no_material_values_present_in_native_source_payload';
  }
  $classifications[$classification]++;
  $sourceCounts[$sourceKind]++;
  $totalMaterialLeaves += $materialLeafCount;
  $totalMatchedLeaves += $matchedLeafCount;

  $items[] = [
    'config_name' => $configName,
    'entity_id' => $entityId,
    'source' => $sourceKind,
    'source_local_id' => $sourceLocalId,
    'provider' => $provider,
    'source_class' => $source::class,
    'classification' => $classification,
    'material_translatable_leaf_count' => $materialLeafCount,
    'matched_material_leaf_count' => $matchedLeafCount,
    'unmatched_material_leaf_count' => $materialLeafCount - $matchedLeafCount,
    'material_translatable_leaves' => $leafEvidence,
    'native_source' => [
      'configuration' => $normalizedConfiguration,
      'plugin_definition' => $normalizedDefinition,
      'source_specific_component_id' => $runtimeSourceLocalId,
      'version_hash' => $versionHash,
    ],
  ];
}

usort(
  $items,
  static fn(array $left, array $right): int =>
    $left['config_name'] <=> $right['config_name'],
);
usort(
  $unmatchedLeaves,
  static fn(array $left, array $right): int =>
    [$left['config_name'], $left['leaf_path']]
    <=> [$right['config_name'], $right['leaf_path']],
);
usort(
  $serializationNotes,
  static fn(array $left, array $right): int =>
    [$left['path'], $left['class']] <=> [$right['path'], $right['class']],
);

$analyzed = array_sum($classifications);
$problems = $baselineProblems;
if ($analyzed !== 30) {
  $problems[] = [
    'reason' => 'analyzed_component_count_mismatch',
    'actual' => $analyzed,
    'expected' => 30,
  ];
}
if ($sourceCounts !== ['block' => 26, 'sdc' => 4]) {
  $problems[] = [
    'reason' => 'source_kind_count_mismatch',
    'actual' => $sourceCounts,
    'expected' => ['block' => 26, 'sdc' => 4],
  ];
}
if ($classifications['source_payload_unresolved'] !== 0) {
  $problems[] = [
    'reason' => 'source_payload_unresolved_present',
    'count' => $classifications['source_payload_unresolved'],
  ];
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'CANVAS_NATIVE_SOURCE_PAYLOADS_CORRELATED'
  : 'CANVAS_NATIVE_SOURCE_PAYLOAD_CORRELATION_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'baseline' => [
    'canvas_version' => $canvasVersion,
    'manifest_issue' => 766,
    'manifest_names_sha256' => $manifestHash,
    'expected_candidate_count' => 30,
    'expected_source_counts' => ['block' => 26, 'sdc' => 4],
  ],
  'counts' => [
    'candidate_total' => 30,
    'analyzed' => $analyzed,
    'block' => $sourceCounts['block'],
    'sdc' => $sourceCounts['sdc'],
    'baseline_problem' => count($baselineProblems),
    'problem_count' => count($problems),
    'material_translatable_leaves' => $totalMaterialLeaves,
    'matched_material_translatable_leaves' => $totalMatchedLeaves,
    'unmatched_material_translatable_leaves' => count($unmatchedLeaves),
    ...$classifications,
  ],
  'items' => $items,
  'unmatched_material_translatable_leaves' => $unmatchedLeaves,
  'serialization_notes' => $serializationNotes,
  'problems' => $problems,
  'constraints' => [
    'read_only' => TRUE,
    'strict_type_and_value_equality_only' => TRUE,
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

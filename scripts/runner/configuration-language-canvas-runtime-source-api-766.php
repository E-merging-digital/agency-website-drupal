<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot
  . '/docs/evidence/'
  . 'configuration-language-canvas-runtime-source-api-cohort-766.yml';

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #766 runtime API baseline is incomplete.');
}

$manifest = Yaml::parseFile($manifestPath);
if (!is_array($manifest)) {
  throw new RuntimeException('Issue #766 manifest must be a mapping.');
}
if (($manifest['issue'] ?? NULL) !== 766) {
  throw new RuntimeException('Issue #766 manifest identity mismatch.');
}
if (($manifest['parent_issue'] ?? NULL) !== 609) {
  throw new RuntimeException('Issue #766 parent identity mismatch.');
}
if (($manifest['cohort']['total'] ?? NULL) !== 30) {
  throw new RuntimeException('Issue #766 cohort must contain 30 names.');
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

$manifestHash = hash(
  'sha256',
  implode("\n", $manifestNames) . "\n",
);
$expectedHash = $manifest['cohort']['names_sha256'] ?? NULL;
if ($manifestHash !== $expectedHash) {
  throw new RuntimeException('Issue #766 manifest names hash mismatch.');
}

$canvasVersion = InstalledVersions::getPrettyVersion('drupal/canvas');
if ($canvasVersion !== '1.10.1') {
  throw new RuntimeException(sprintf(
    'Issue #766 requires the locked Canvas 1.10.1 runtime, got %s.',
    $canvasVersion ?? 'unknown',
  ));
}

$typeToString = NULL;
$typeToString = static function (?ReflectionType $type) use (&$typeToString): ?string {
  if ($type === NULL) {
    return NULL;
  }
  if ($type instanceof ReflectionNamedType) {
    return ($type->allowsNull() && $type->getName() !== 'mixed' ? '?' : '')
      . $type->getName();
  }
  if ($type instanceof ReflectionUnionType) {
    return implode('|', array_map(
      $typeToString,
      $type->getTypes(),
    ));
  }
  if ($type instanceof ReflectionIntersectionType) {
    return implode('&', array_map(
      $typeToString,
      $type->getTypes(),
    ));
  }
  return (string) $type;
};

$parameterToArray = static function (
  ReflectionParameter $parameter,
) use ($typeToString): array {
  $default = NULL;
  $hasDefault = $parameter->isDefaultValueAvailable();
  if ($hasDefault) {
    try {
      $default = $parameter->getDefaultValue();
    }
    catch (ReflectionException) {
      $default = '__unavailable__';
    }
  }

  return [
    'name' => $parameter->getName(),
    'type' => $typeToString($parameter->getType()),
    'optional' => $parameter->isOptional(),
    'variadic' => $parameter->isVariadic(),
    'by_reference' => $parameter->isPassedByReference(),
    'has_default' => $hasDefault,
    'default' => $default,
  ];
};

$reflectMethods = static function (
  string|object $class,
) use ($parameterToArray, $typeToString): array {
  $reflection = new ReflectionClass($class);
  $methods = [];
  foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
    $name = $method->getName();
    if (!preg_match(
      '/source|component|config|discover|generate|create|update|definition|plugin|setting|version/i',
      $name,
    )) {
      continue;
    }
    $methods[] = [
      'name' => $name,
      'declaring_class' => $method->getDeclaringClass()->getName(),
      'static' => $method->isStatic(),
      'parameters' => array_map(
        $parameterToArray,
        $method->getParameters(),
      ),
      'return_type' => $typeToString($method->getReturnType()),
    ];
  }
  usort(
    $methods,
    static fn(array $left, array $right): int =>
      [$left['name'], $left['declaring_class']]
      <=> [$right['name'], $right['declaring_class']],
  );
  return $methods;
};

$entityTypeManager = \Drupal::entityTypeManager();
$componentEntityTypeIds = [];
foreach ($entityTypeManager->getDefinitions() as $entityTypeId => $definition) {
  if (!$definition instanceof ConfigEntityTypeInterface) {
    continue;
  }
  if ($definition->getConfigPrefix() === 'canvas.component') {
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
$componentDefinition = $entityTypeManager->getDefinition($componentEntityTypeId);
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
    'unexpected' => array_values(array_diff(
      $repositoryCanvasFrNames,
      $manifestNames,
    )),
  ];
}

$sourceCounts = [
  'block' => 0,
  'sdc' => 0,
];
$items = [];
$sourceClasses = [];
foreach ($manifestNames as $configName) {
  $path = $configDirectory . '/' . $configName . '.yml';
  if (!is_file($path)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_config_missing',
    ];
    continue;
  }
  $repositoryData = Yaml::parseFile($path);
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

  $entityId = $repositoryData['id'] ?? NULL;
  $expectedSource = $repositoryData['source'] ?? NULL;
  $expectedSourceLocalId = $repositoryData['source_local_id'] ?? NULL;
  $expectedProvider = $repositoryData['provider'] ?? NULL;
  if (
    !is_string($entityId)
    || $entityId === ''
    || !is_string($expectedSource)
    || !isset($sourceCounts[$expectedSource])
    || !is_string($expectedSourceLocalId)
    || $expectedSourceLocalId === ''
    || !is_string($expectedProvider)
    || $expectedProvider === ''
  ) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_source_identity_invalid',
    ];
    continue;
  }

  $entity = $componentStorage->load($entityId);
  if ($entity === NULL) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'runtime_component_entity_missing',
    ];
    continue;
  }
  if (!is_a($entity, 'Drupal\\canvas\\Entity\\Component')) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'runtime_component_entity_class_unexpected',
      'actual_class' => $entity::class,
    ];
    continue;
  }
  if (!method_exists($entity, 'getComponentSource')) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'get_component_source_method_missing',
    ];
    continue;
  }

  $runtimeData = $entity->toArray();
  foreach ([
    'source' => $expectedSource,
    'source_local_id' => $expectedSourceLocalId,
    'provider' => $expectedProvider,
  ] as $key => $expectedValue) {
    if (($runtimeData[$key] ?? NULL) !== $expectedValue) {
      $baselineProblems[] = [
        'name' => $configName,
        'reason' => 'runtime_source_identity_mismatch',
        'key' => $key,
        'expected' => $expectedValue,
        'actual' => $runtimeData[$key] ?? NULL,
      ];
      continue 2;
    }
  }

  $sourceObject = $entity->getComponentSource();
  if (!is_object($sourceObject)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'component_source_object_missing',
    ];
    continue;
  }

  $sourceClass = $sourceObject::class;
  if (!isset($sourceClasses[$sourceClass])) {
    $sourceClasses[$sourceClass] = [
      'class' => $sourceClass,
      'methods' => $reflectMethods($sourceObject),
      'component_count' => 0,
      'source_kinds' => [],
    ];
  }
  $sourceClasses[$sourceClass]['component_count']++;
  $sourceClasses[$sourceClass]['source_kinds'][$expectedSource] = TRUE;

  $pluginId = NULL;
  if (method_exists($sourceObject, 'getPluginId')) {
    $pluginIdMethod = new ReflectionMethod($sourceObject, 'getPluginId');
    if ($pluginIdMethod->getNumberOfRequiredParameters() === 0) {
      $pluginId = $sourceObject->getPluginId();
    }
  }

  $sourceCounts[$expectedSource]++;
  $items[] = [
    'config_name' => $configName,
    'entity_id' => $entityId,
    'entity_type_id' => $componentEntityTypeId,
    'entity_class' => $entity::class,
    'source' => $expectedSource,
    'source_local_id' => $expectedSourceLocalId,
    'provider' => $expectedProvider,
    'source_class' => $sourceClass,
    'source_plugin_id' => is_scalar($pluginId) ? $pluginId : NULL,
    'active_version' => $repositoryData['active_version'] ?? NULL,
  ];
}

ksort($sourceClasses, SORT_STRING);
foreach ($sourceClasses as &$sourceClass) {
  $sourceKinds = array_keys($sourceClass['source_kinds']);
  sort($sourceKinds, SORT_STRING);
  $sourceClass['source_kinds'] = $sourceKinds;
}
unset($sourceClass);
usort(
  $items,
  static fn(array $left, array $right): int =>
    $left['config_name'] <=> $right['config_name'],
);

$container = \Drupal::getContainer();
$managerClass = 'Drupal\\canvas\\ComponentSource\\ComponentSourceManager';
$managerClassAvailable = class_exists($managerClass);
$managerServiceCandidates = [
  $managerClass,
  'canvas.component_source_manager',
  'canvas.component_source.manager',
  'plugin.manager.canvas.component_source',
];
$managerServicesPresent = [];
foreach ($managerServiceCandidates as $serviceId) {
  if ($container->has($serviceId)) {
    $managerServicesPresent[] = $serviceId;
  }
}
sort($managerServicesPresent, SORT_STRING);

$blockManager = $container->get('plugin.manager.block');
$sdcManager = $container->get('plugin.manager.sdc');
$managerApi = [
  'component_source_manager' => [
    'class' => $managerClass,
    'class_available' => $managerClassAvailable,
    'service_ids_present' => $managerServicesPresent,
    'methods' => $managerClassAvailable ? $reflectMethods($managerClass) : [],
  ],
  'block_plugin_manager' => [
    'service_id' => 'plugin.manager.block',
    'class' => $blockManager::class,
    'methods' => $reflectMethods($blockManager),
  ],
  'sdc_plugin_manager' => [
    'service_id' => 'plugin.manager.sdc',
    'class' => $sdcManager::class,
    'methods' => $reflectMethods($sdcManager),
  ],
];

$entityApi = [
  'entity_type_id' => $componentEntityTypeId,
  'config_prefix' => $componentDefinition instanceof ConfigEntityTypeInterface
    ? $componentDefinition->getConfigPrefix()
    : NULL,
  'entity_class' => $componentDefinition->getClass(),
  'methods' => $reflectMethods($componentDefinition->getClass()),
];

$problems = $baselineProblems;
if (count($items) !== 30) {
  $problems[] = [
    'reason' => 'runtime_component_count_mismatch',
    'actual' => count($items),
    'expected' => 30,
  ];
}
if ($sourceCounts !== ['block' => 26, 'sdc' => 4]) {
  $problems[] = [
    'reason' => 'runtime_source_kind_count_mismatch',
    'actual' => $sourceCounts,
    'expected' => ['block' => 26, 'sdc' => 4],
  ];
}
if (!$managerClassAvailable) {
  $problems[] = [
    'reason' => 'component_source_manager_class_missing',
    'class' => $managerClass,
  ];
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'CANVAS_RUNTIME_SOURCE_API_MAPPED'
  : 'CANVAS_RUNTIME_SOURCE_API_MAPPING_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'runtime' => [
    'canvas_version' => $canvasVersion,
    'component_entity_api' => $entityApi,
    'manager_api' => $managerApi,
    'source_classes' => array_values($sourceClasses),
  ],
  'counts' => [
    'candidate_total' => 30,
    'mapped' => count($items),
    'block' => $sourceCounts['block'],
    'sdc' => $sourceCounts['sdc'],
    'source_class_count' => count($sourceClasses),
    'baseline_problem' => count($baselineProblems),
    'problem_count' => count($problems),
  ],
  'manifest_names_sha256' => $manifestHash,
  'items' => $items,
  'problems' => $problems,
  'constraints' => [
    'read_only' => TRUE,
    'migration_allowed_by_this_proof' => FALSE,
    'source_generation_executed' => FALSE,
    'config_entity_creation_executed' => FALSE,
    'config_entity_update_executed' => FALSE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_access_allowed' => FALSE,
    'provider_secret_used' => FALSE,
    'natural_language_heuristic_used' => FALSE,
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
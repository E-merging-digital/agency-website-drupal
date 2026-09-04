<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot
  . '/docs/evidence/'
  . 'configuration-language-canvas-runtime-source-api-cohort-766.yml';

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #774 source baseline is incomplete.');
}

$manifest = Yaml::parseFile($manifestPath);
if (!is_array($manifest)) {
  throw new RuntimeException('Issue #766 manifest must be a mapping.');
}
if (($manifest['issue'] ?? NULL) !== 766 || ($manifest['parent_issue'] ?? NULL) !== 609) {
  throw new RuntimeException('Issue #774 must reuse the exact #766 cohort.');
}

$manifestNames = $manifest['cohort']['names'] ?? NULL;
if (!is_array($manifestNames) || count($manifestNames) !== 30) {
  throw new RuntimeException('Issue #766 manifest names are incomplete.');
}
$blockNames = array_values(array_filter(
  array_map('strval', $manifestNames),
  static fn(string $name): bool => str_starts_with(
    $name,
    'canvas.component.block.',
  ),
));
sort($blockNames, SORT_STRING);
if (count($blockNames) !== 26 || count(array_unique($blockNames)) !== 26) {
  throw new RuntimeException('Issue #774 requires exactly 26 unique Block names.');
}
$blockNamesHash = hash('sha256', implode("\n", $blockNames) . "\n");
if (
  $blockNamesHash
  !== 'e388542385fb7cd490c79ef0783dd1ace930ab1b1db1a0762fd50df783db247c'
) {
  throw new RuntimeException('Issue #774 Block cohort hash mismatch.');
}

$canvasVersion = InstalledVersions::getPrettyVersion('drupal/canvas');
if ($canvasVersion !== '1.10.1') {
  throw new RuntimeException(sprintf(
    'Issue #774 requires Canvas 1.10.1, got %s.',
    $canvasVersion ?? 'unknown',
  ));
}

$entityTypeManager = \Drupal::entityTypeManager();
$activeStorage = \Drupal::service('config.storage');
$blockManager = \Drupal::service('plugin.manager.block');
$stringTranslation = \Drupal::translation();

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

$baselineProblems = [];
$repositoryDataByName = [];
foreach ($blockNames as $configName) {
  $path = $configDirectory . '/' . $configName . '.yml';
  if (!is_file($path)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_config_missing',
    ];
    continue;
  }
  $data = Yaml::parseFile($path);
  if (!is_array($data)) {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_config_invalid',
    ];
    continue;
  }
  $repositoryDataByName[$configName] = $data;
  if (($data['langcode'] ?? NULL) !== 'fr') {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_langcode_not_fr',
      'actual' => $data['langcode'] ?? NULL,
    ];
  }
  if (($data['source'] ?? NULL) !== 'block') {
    $baselineProblems[] = [
      'name' => $configName,
      'reason' => 'repository_source_not_block',
      'actual' => $data['source'] ?? NULL,
    ];
  }
}

$activeDistribution = [
  '__none__' => 0,
  'en' => 0,
  'fr' => 0,
  'und' => 0,
];
$activeNames = $activeStorage->listAll();
sort($activeNames, SORT_STRING);
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
$expectedDistribution = [
  '__none__' => 59,
  'en' => 466,
  'fr' => 69,
  'und' => 1,
];
ksort($expectedDistribution, SORT_STRING);
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

$renderTranslation = static function (
  TranslatableMarkup $label,
  string $langcode,
) use ($stringTranslation): string {
  $options = $label->getOptions();
  $options['langcode'] = $langcode;
  return (string) $stringTranslation->translate(
    $label->getUntranslatedString(),
    $label->getArguments(),
    $options,
  );
};

$items = [];
$problems = $baselineProblems;
$definitionResolved = 0;
$translatableLabelCount = 0;
$scalarLabelCount = 0;
$currentMatchesUntranslated = 0;
$currentMatchesRuntimeRender = 0;
$currentMatchesFrRender = 0;
$currentMatchesEnRender = 0;
$currentMatchesScalar = 0;
$currentNoExactNativeMatch = 0;
$currentDiffersFromNativeSource = [];
$settingsDiffersFromCurrent = [];
$labelsWithArguments = 0;

foreach ($blockNames as $configName) {
  $repositoryData = $repositoryDataByName[$configName] ?? NULL;
  if (!is_array($repositoryData)) {
    continue;
  }

  $activeData = $activeStorage->read($configName);
  if (!is_array($activeData)) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'active_component_config_missing',
    ];
    continue;
  }
  foreach (['source', 'source_local_id', 'provider', 'label', 'id'] as $key) {
    if (($activeData[$key] ?? NULL) !== ($repositoryData[$key] ?? NULL)) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'active_repository_identity_mismatch',
        'key' => $key,
        'repository' => $repositoryData[$key] ?? NULL,
        'active' => $activeData[$key] ?? NULL,
      ];
      continue 2;
    }
  }

  $entityId = $activeData['id'] ?? NULL;
  $sourceLocalId = $activeData['source_local_id'] ?? NULL;
  $provider = $activeData['provider'] ?? NULL;
  $currentLabel = $activeData['label'] ?? NULL;
  $settingsLabel = $activeData['versioned_properties']['active']['settings']
    ['default_settings']['label'] ?? NULL;
  if (
    !is_string($entityId)
    || $entityId === ''
    || !is_string($sourceLocalId)
    || $sourceLocalId === ''
    || !is_string($provider)
    || $provider === ''
    || !is_string($currentLabel)
    || !is_string($settingsLabel)
  ) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'component_identity_or_label_invalid',
    ];
    continue;
  }

  $entity = $componentStorage->load($entityId);
  if ($entity === NULL || !method_exists($entity, 'getComponentSource')) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'runtime_component_entity_unavailable',
    ];
    continue;
  }
  $runtimeData = $entity->toArray();
  if (
    ($runtimeData['source'] ?? NULL) !== 'block'
    || ($runtimeData['source_local_id'] ?? NULL) !== $sourceLocalId
  ) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'runtime_component_source_identity_mismatch',
    ];
    continue;
  }
  $sourceObject = $entity->getComponentSource();
  if (!is_object($sourceObject)) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'component_source_object_missing',
    ];
    continue;
  }

  if (!$blockManager->hasDefinition($sourceLocalId)) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'block_definition_missing',
      'source_local_id' => $sourceLocalId,
    ];
    continue;
  }
  $definition = $blockManager->getDefinition($sourceLocalId, FALSE);
  if (!is_array($definition)) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'block_definition_invalid',
      'source_local_id' => $sourceLocalId,
    ];
    continue;
  }
  $definitionResolved++;

  $instanceIdentity = [
    'plugin_id' => NULL,
    'base_plugin_id' => NULL,
    'derivative_id' => NULL,
  ];
  try {
    $instance = $blockManager->createInstance($sourceLocalId, []);
    if (method_exists($instance, 'getPluginId')) {
      $instanceIdentity['plugin_id'] = $instance->getPluginId();
    }
    if (method_exists($instance, 'getBaseId')) {
      $instanceIdentity['base_plugin_id'] = $instance->getBaseId();
    }
    if (method_exists($instance, 'getDerivativeId')) {
      $instanceIdentity['derivative_id'] = $instance->getDerivativeId();
    }
  }
  catch (Throwable $exception) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'block_instance_identity_unavailable',
      'source_local_id' => $sourceLocalId,
      'exception_class' => $exception::class,
      'message' => $exception->getMessage(),
    ];
    continue;
  }

  $adminLabel = $definition['admin_label'] ?? NULL;
  $labelEvidence = [
    'kind' => NULL,
    'untranslated_source' => NULL,
    'arguments' => [],
    'options' => [],
    'rendered_runtime' => NULL,
    'rendered_fr' => NULL,
    'rendered_en' => NULL,
    'scalar_definition' => NULL,
  ];
  $matches = [
    'current_equals_untranslated_source' => FALSE,
    'current_equals_rendered_runtime' => FALSE,
    'current_equals_rendered_fr' => FALSE,
    'current_equals_rendered_en' => FALSE,
    'current_equals_scalar_definition' => FALSE,
    'settings_equals_untranslated_source' => FALSE,
    'settings_equals_rendered_runtime' => FALSE,
    'settings_equals_rendered_fr' => FALSE,
    'settings_equals_rendered_en' => FALSE,
    'settings_equals_scalar_definition' => FALSE,
  ];

  if ($adminLabel instanceof TranslatableMarkup) {
    $translatableLabelCount++;
    $untranslated = $adminLabel->getUntranslatedString();
    $arguments = $adminLabel->getArguments();
    $options = $adminLabel->getOptions();
    if ($arguments !== []) {
      $labelsWithArguments++;
    }
    $renderedRuntime = (string) $adminLabel;
    $renderedFr = $renderTranslation($adminLabel, 'fr');
    $renderedEn = $renderTranslation($adminLabel, 'en');
    $labelEvidence = [
      'kind' => 'translatable_markup',
      'untranslated_source' => $untranslated,
      'arguments' => $arguments,
      'options' => $options,
      'rendered_runtime' => $renderedRuntime,
      'rendered_fr' => $renderedFr,
      'rendered_en' => $renderedEn,
      'scalar_definition' => NULL,
    ];
    $matches = [
      'current_equals_untranslated_source' => $currentLabel === $untranslated,
      'current_equals_rendered_runtime' => $currentLabel === $renderedRuntime,
      'current_equals_rendered_fr' => $currentLabel === $renderedFr,
      'current_equals_rendered_en' => $currentLabel === $renderedEn,
      'current_equals_scalar_definition' => FALSE,
      'settings_equals_untranslated_source' => $settingsLabel === $untranslated,
      'settings_equals_rendered_runtime' => $settingsLabel === $renderedRuntime,
      'settings_equals_rendered_fr' => $settingsLabel === $renderedFr,
      'settings_equals_rendered_en' => $settingsLabel === $renderedEn,
      'settings_equals_scalar_definition' => FALSE,
    ];
    $currentMatchesUntranslated += (int) $matches['current_equals_untranslated_source'];
    $currentMatchesRuntimeRender += (int) $matches['current_equals_rendered_runtime'];
    $currentMatchesFrRender += (int) $matches['current_equals_rendered_fr'];
    $currentMatchesEnRender += (int) $matches['current_equals_rendered_en'];
    if (!$matches['current_equals_untranslated_source']) {
      $currentDiffersFromNativeSource[] = $configName;
    }
  }
  elseif (is_scalar($adminLabel)) {
    $scalarLabelCount++;
    $scalar = (string) $adminLabel;
    $labelEvidence = [
      'kind' => 'scalar_definition_literal',
      'untranslated_source' => NULL,
      'arguments' => [],
      'options' => [],
      'rendered_runtime' => NULL,
      'rendered_fr' => NULL,
      'rendered_en' => NULL,
      'scalar_definition' => $scalar,
    ];
    $matches['current_equals_scalar_definition'] = $currentLabel === $scalar;
    $matches['settings_equals_scalar_definition'] = $settingsLabel === $scalar;
    $currentMatchesScalar += (int) $matches['current_equals_scalar_definition'];
    if (!$matches['current_equals_scalar_definition']) {
      $currentDiffersFromNativeSource[] = $configName;
    }
  }
  else {
    $problems[] = [
      'name' => $configName,
      'reason' => 'admin_label_type_unsupported',
      'actual_type' => get_debug_type($adminLabel),
    ];
    continue;
  }

  $anyCurrentMatch = in_array(TRUE, [
    $matches['current_equals_untranslated_source'],
    $matches['current_equals_rendered_runtime'],
    $matches['current_equals_rendered_fr'],
    $matches['current_equals_rendered_en'],
    $matches['current_equals_scalar_definition'],
  ], TRUE);
  if (!$anyCurrentMatch) {
    $currentNoExactNativeMatch++;
  }
  if ($currentLabel !== $settingsLabel) {
    $settingsDiffersFromCurrent[] = $configName;
  }

  $items[] = [
    'config_name' => $configName,
    'entity_id' => $entityId,
    'provider' => $provider,
    'source_local_id' => $sourceLocalId,
    'component_source_class' => $sourceObject::class,
    'block_definition' => [
      'definition_id' => $definition['id'] ?? NULL,
      'provider' => $definition['provider'] ?? NULL,
      'class' => $definition['class'] ?? NULL,
      'deriver' => $definition['deriver'] ?? NULL,
      'category_type' => isset($definition['category'])
        ? get_debug_type($definition['category'])
        : NULL,
    ],
    'instance_identity' => $instanceIdentity,
    'canvas_labels' => [
      'label' => $currentLabel,
      'active_default_settings_label' => $settingsLabel,
      'labels_equal' => $currentLabel === $settingsLabel,
    ],
    'native_admin_label' => $labelEvidence,
    'strict_matches' => $matches,
    'current_has_any_exact_native_label_match' => $anyCurrentMatch,
  ];
}

sort($currentDiffersFromNativeSource, SORT_STRING);
sort($settingsDiffersFromCurrent, SORT_STRING);
usort(
  $items,
  static fn(array $left, array $right): int =>
    $left['config_name'] <=> $right['config_name'],
);

if (count($items) !== 26) {
  $problems[] = [
    'reason' => 'analyzed_component_count_mismatch',
    'actual' => count($items),
    'expected' => 26,
  ];
}
if ($definitionResolved !== 26) {
  $problems[] = [
    'reason' => 'resolved_definition_count_mismatch',
    'actual' => $definitionResolved,
    'expected' => 26,
  ];
}
if (($translatableLabelCount + $scalarLabelCount) !== 26) {
  $problems[] = [
    'reason' => 'native_label_kind_count_mismatch',
    'translatable' => $translatableLabelCount,
    'scalar' => $scalarLabelCount,
  ];
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'CANVAS_BLOCK_NATIVE_LABEL_PROVENANCE_ANALYZED'
  : 'CANVAS_BLOCK_NATIVE_LABEL_PROVENANCE_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'runtime' => [
    'canvas_version' => $canvasVersion,
    'component_entity_type_id' => $componentEntityTypeId,
    'block_manager_class' => $blockManager::class,
    'active_total' => count($activeNames),
    'active_distribution' => $activeDistribution,
  ],
  'counts' => [
    'candidate_total' => 26,
    'analyzed' => count($items),
    'definition_resolved' => $definitionResolved,
    'translatable_admin_label' => $translatableLabelCount,
    'scalar_admin_label' => $scalarLabelCount,
    'admin_labels_with_arguments' => $labelsWithArguments,
    'current_matches_untranslated_source' => $currentMatchesUntranslated,
    'current_matches_rendered_runtime' => $currentMatchesRuntimeRender,
    'current_matches_rendered_fr' => $currentMatchesFrRender,
    'current_matches_rendered_en' => $currentMatchesEnRender,
    'current_matches_scalar_definition' => $currentMatchesScalar,
    'current_no_exact_native_label_match' => $currentNoExactNativeMatch,
    'current_differs_from_native_source' => count($currentDiffersFromNativeSource),
    'settings_differs_from_current' => count($settingsDiffersFromCurrent),
    'baseline_problem' => count($baselineProblems),
    'problem_count' => count($problems),
  ],
  'cohort_names_sha256' => $blockNamesHash,
  'current_differs_from_native_source_names' => $currentDiffersFromNativeSource,
  'settings_differs_from_current_names' => $settingsDiffersFromCurrent,
  'items' => $items,
  'problems' => $problems,
  'constraints' => [
    'read_only' => TRUE,
    'source_local_ids_from_runtime_only' => TRUE,
    'block_definition_surface' => 'plugin.manager.block',
    'strict_equality_only' => TRUE,
    'natural_language_heuristic_used' => FALSE,
    'automatic_translation_used' => FALSE,
    'fuzzy_matching_used' => FALSE,
    'migration_allowed_by_this_proof' => FALSE,
    'source_generation_executed' => FALSE,
    'block_build_executed' => FALSE,
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

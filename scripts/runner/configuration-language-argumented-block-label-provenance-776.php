<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';

$names = [
  'canvas.component.block.language_block.language_content',
  'canvas.component.block.language_block.language_interface',
  'canvas.component.block.views_block.content_recent-block_1',
];
sort($names, SORT_STRING);
$namesHash = hash('sha256', implode("\n", $names) . "\n");
if (
  $namesHash
  !== 'ad40d7521d300bd8b77978997d3a290440e4aaa775179ec4009491e328a78f14'
) {
  throw new RuntimeException('Issue #776 cohort hash mismatch.');
}

$canvasVersion = InstalledVersions::getPrettyVersion('drupal/canvas');
if ($canvasVersion !== '1.10.1') {
  throw new RuntimeException(sprintf(
    'Issue #776 requires Canvas 1.10.1, got %s.',
    $canvasVersion ?? 'unknown',
  ));
}

$activeStorage = \Drupal::service('config.storage');
$blockManager = \Drupal::service('plugin.manager.block');
$languageManager = \Drupal::languageManager();
$stringTranslation = \Drupal::translation();
$entityTypeManager = \Drupal::entityTypeManager();
$viewStorage = $entityTypeManager->getStorage('view');

$renderTranslation = static function (
  string $source,
  array $arguments,
  string $langcode,
) use ($stringTranslation): string {
  return (string) $stringTranslation->translate(
    $source,
    $arguments,
    ['langcode' => $langcode],
  );
};

$renderMarkup = static function (
  TranslatableMarkup $markup,
  string $langcode,
) use ($stringTranslation): string {
  $options = $markup->getOptions();
  $options['langcode'] = $langcode;
  return (string) $stringTranslation->translate(
    $markup->getUntranslatedString(),
    $markup->getArguments(),
    $options,
  );
};

$argumentEvidence = static function (mixed $value) use ($renderMarkup): array {
  if ($value instanceof TranslatableMarkup) {
    return [
      'kind' => 'translatable_markup',
      'source' => $value->getUntranslatedString(),
      'fr' => $renderMarkup($value, 'fr'),
      'en' => $renderMarkup($value, 'en'),
      'arguments' => $value->getArguments(),
      'options' => $value->getOptions(),
    ];
  }
  if (is_scalar($value)) {
    $literal = (string) $value;
    return [
      'kind' => 'scalar_literal',
      'source' => $literal,
      'fr' => $literal,
      'en' => $literal,
      'arguments' => [],
      'options' => [],
    ];
  }
  throw new RuntimeException(sprintf(
    'Unsupported argument provenance type: %s.',
    get_debug_type($value),
  ));
};

$baselineProblems = [];
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

$items = [];
$problems = $baselineProblems;
$deterministic = 0;
$reviewRequired = 0;

foreach ($names as $configName) {
  $path = $configDirectory . '/' . $configName . '.yml';
  if (!is_file($path)) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'repository_config_missing',
    ];
    continue;
  }
  $repositoryData = Yaml::parseFile($path);
  $activeData = $activeStorage->read($configName);
  if (!is_array($repositoryData) || !is_array($activeData)) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'component_config_unreadable',
    ];
    continue;
  }
  if ($repositoryData !== $activeData) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'active_repository_component_mismatch',
    ];
    continue;
  }
  if (($activeData['langcode'] ?? NULL) !== 'fr') {
    $problems[] = [
      'name' => $configName,
      'reason' => 'component_langcode_not_fr',
    ];
    continue;
  }

  $sourceLocalId = $activeData['source_local_id'] ?? NULL;
  $currentLabel = $activeData['label'] ?? NULL;
  $settingsLabel = $activeData['versioned_properties']['active']['settings']
    ['default_settings']['label'] ?? NULL;
  if (
    !is_string($sourceLocalId)
    || $sourceLocalId === ''
    || !is_string($currentLabel)
    || !is_string($settingsLabel)
  ) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'component_identity_or_label_invalid',
    ];
    continue;
  }
  if ($currentLabel !== $settingsLabel) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'canvas_label_settings_label_mismatch',
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
    ];
    continue;
  }
  $adminLabel = $definition['admin_label'] ?? NULL;
  if (!$adminLabel instanceof TranslatableMarkup) {
    $problems[] = [
      'name' => $configName,
      'reason' => 'argumented_admin_label_not_translatable_markup',
      'actual_type' => get_debug_type($adminLabel),
    ];
    continue;
  }

  $adminSource = $adminLabel->getUntranslatedString();
  $adminArguments = $adminLabel->getArguments();
  $adminFr = $renderMarkup($adminLabel, 'fr');
  $adminEnWithRuntimeArguments = $renderMarkup($adminLabel, 'en');

  $argumentSources = [];
  $provenance = NULL;

  if (str_starts_with($sourceLocalId, 'language_block:')) {
    $derivativeId = substr($sourceLocalId, strlen('language_block:'));
    if (!in_array($derivativeId, ['language_content', 'language_interface'], TRUE)) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'unexpected_language_block_derivative',
        'derivative_id' => $derivativeId,
      ];
      continue;
    }
    $definedInfo = $languageManager->getDefinedLanguageTypesInfo();
    $typeInfo = $definedInfo[$derivativeId] ?? NULL;
    $typeName = is_array($typeInfo) ? ($typeInfo['name'] ?? NULL) : NULL;
    if (!$typeName instanceof TranslatableMarkup) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'language_type_name_provenance_missing',
        'derivative_id' => $derivativeId,
        'actual_type' => get_debug_type($typeName),
      ];
      continue;
    }
    $typeEvidence = $argumentEvidence($typeName);
    $argumentSources['@type'] = $typeEvidence;
    $provenance = [
      'kind' => 'language_type_info',
      'derivative_id' => $derivativeId,
      'service' => 'language_manager',
      'source_method' => 'getDefinedLanguageTypesInfo',
    ];
  }
  elseif ($sourceLocalId === 'views_block:content_recent-block_1') {
    $dependencies = $definition['config_dependencies']['config'] ?? NULL;
    if (!is_array($dependencies)) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'views_config_dependency_missing',
      ];
      continue;
    }
    $viewDependencies = array_values(array_filter(
      array_map('strval', $dependencies),
      static fn(string $dependency): bool => str_starts_with(
        $dependency,
        'views.view.',
      ),
    ));
    if (count($viewDependencies) !== 1) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'views_config_dependency_not_unique',
        'dependencies' => $viewDependencies,
      ];
      continue;
    }
    $viewId = substr($viewDependencies[0], strlen('views.view.'));
    $view = $viewStorage->load($viewId);
    if ($view === NULL) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'view_entity_missing',
        'view_id' => $viewId,
      ];
      continue;
    }
    $derivativeId = substr($sourceLocalId, strlen('views_block:'));
    $displayData = $view->get('display');
    if (!is_array($displayData)) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'view_display_data_invalid',
      ];
      continue;
    }
    $matchingDisplayIds = [];
    foreach (array_keys($displayData) as $displayId) {
      if ($viewId . '-' . $displayId === $derivativeId) {
        $matchingDisplayIds[] = (string) $displayId;
      }
    }
    if (count($matchingDisplayIds) !== 1) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'view_display_identity_not_unique',
        'view_id' => $viewId,
        'derivative_id' => $derivativeId,
        'matches' => $matchingDisplayIds,
      ];
      continue;
    }
    $displayId = $matchingDisplayIds[0];
    $displayTitle = $displayData[$displayId]['display_title'] ?? NULL;
    $viewLabel = $view->label();
    try {
      $viewEvidence = $argumentEvidence($viewLabel);
      $displayEvidence = $argumentEvidence($displayTitle);
    }
    catch (RuntimeException $exception) {
      $problems[] = [
        'name' => $configName,
        'reason' => 'view_argument_provenance_unsupported',
        'message' => $exception->getMessage(),
      ];
      continue;
    }
    $argumentSources['@view'] = $viewEvidence;
    $argumentSources['@display'] = $displayEvidence;
    $provenance = [
      'kind' => 'views_config_entity',
      'view_id' => $viewId,
      'display_id' => $displayId,
      'config_dependency' => $viewDependencies[0],
      'source_method' => 'view label + display.display_title',
    ];
  }
  else {
    $problems[] = [
      'name' => $configName,
      'reason' => 'unexpected_argumented_source_local_id',
      'source_local_id' => $sourceLocalId,
    ];
    continue;
  }

  $sourceArguments = [];
  $frArguments = [];
  $enArguments = [];
  foreach ($argumentSources as $placeholder => $evidence) {
    $sourceArguments[$placeholder] = $evidence['source'];
    $frArguments[$placeholder] = $evidence['fr'];
    $enArguments[$placeholder] = $evidence['en'];
  }

  $sourceFormatted = (string) new FormattableMarkup(
    $adminSource,
    $sourceArguments,
  );
  $frReconstructed = $renderTranslation($adminSource, $frArguments, 'fr');
  $enReconstructed = $renderTranslation($adminSource, $enArguments, 'en');

  $runtimeArgumentMatch = TRUE;
  foreach ($adminArguments as $placeholder => $runtimeArgument) {
    if (!array_key_exists($placeholder, $argumentSources)) {
      $runtimeArgumentMatch = FALSE;
      break;
    }
    $runtimeString = $runtimeArgument instanceof TranslatableMarkup
      ? (string) $runtimeArgument
      : (is_scalar($runtimeArgument) ? (string) $runtimeArgument : NULL);
    if ($runtimeString !== $argumentSources[$placeholder]['fr']) {
      $runtimeArgumentMatch = FALSE;
      break;
    }
  }
  if (count($adminArguments) !== count($argumentSources)) {
    $runtimeArgumentMatch = FALSE;
  }

  $isDeterministic = $runtimeArgumentMatch
    && $currentLabel === $frReconstructed
    && $sourceFormatted !== ''
    && $enReconstructed !== '';
  if ($isDeterministic) {
    $deterministic++;
  }
  else {
    $reviewRequired++;
  }

  $items[] = [
    'config_name' => $configName,
    'source_local_id' => $sourceLocalId,
    'current_canvas_label' => $currentLabel,
    'native_admin_label' => [
      'source_format' => $adminSource,
      'runtime_arguments' => array_map(
        static fn(mixed $value): mixed => is_scalar($value)
          ? $value
          : (is_object($value) ? ['class' => $value::class, 'string' => (string) $value] : get_debug_type($value)),
        $adminArguments,
      ),
      'rendered_fr_with_runtime_arguments' => $adminFr,
      'rendered_en_with_runtime_arguments' => $adminEnWithRuntimeArguments,
    ],
    'argument_provenance' => $provenance,
    'arguments' => $argumentSources,
    'reconstructed' => [
      'source_formatted' => $sourceFormatted,
      'fr' => $frReconstructed,
      'en' => $enReconstructed,
    ],
    'strict_matches' => [
      'runtime_arguments_equal_provenance_fr' => $runtimeArgumentMatch,
      'current_equals_reconstructed_fr' => $currentLabel === $frReconstructed,
      'current_equals_reconstructed_source' => $currentLabel === $sourceFormatted,
      'current_equals_reconstructed_en' => $currentLabel === $enReconstructed,
      'native_runtime_fr_equals_reconstructed_fr' => $adminFr === $frReconstructed,
      'native_runtime_en_equals_reconstructed_en' =>
        $adminEnWithRuntimeArguments === $enReconstructed,
    ],
    'future_base_label' => $isDeterministic ? $sourceFormatted : NULL,
    'future_fr_override_label' => $isDeterministic ? $frReconstructed : NULL,
    'future_en_rendered_label' => $isDeterministic ? $enReconstructed : NULL,
    'decision' => $isDeterministic
      ? 'deterministic_native_argument_provenance'
      : 'review_required',
  ];
}

usort(
  $items,
  static fn(array $left, array $right): int =>
    $left['config_name'] <=> $right['config_name'],
);

if (count($items) !== 3) {
  $problems[] = [
    'reason' => 'analyzed_count_mismatch',
    'actual' => count($items),
    'expected' => 3,
  ];
}
if (($deterministic + $reviewRequired) !== 3) {
  $problems[] = [
    'reason' => 'decision_count_mismatch',
    'deterministic' => $deterministic,
    'review_required' => $reviewRequired,
  ];
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'ARGUMENTED_BLOCK_LABEL_PROVENANCE_ANALYZED'
  : 'ARGUMENTED_BLOCK_LABEL_PROVENANCE_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'runtime' => [
    'canvas_version' => $canvasVersion,
    'block_manager_class' => $blockManager::class,
    'language_manager_class' => $languageManager::class,
    'active_total' => count($activeNames),
    'active_distribution' => $activeDistribution,
  ],
  'counts' => [
    'candidate_total' => 3,
    'analyzed' => count($items),
    'deterministic_native_argument_provenance' => $deterministic,
    'review_required' => $reviewRequired,
    'baseline_problem' => count($baselineProblems),
    'problem_count' => count($problems),
  ],
  'cohort_names_sha256' => $namesHash,
  'items' => $items,
  'problems' => $problems,
  'constraints' => [
    'read_only' => TRUE,
    'strict_equality_only' => TRUE,
    'language_rendering_only_via_drupal_translation_api' => TRUE,
    'natural_language_heuristic_used' => FALSE,
    'automatic_arbitrary_text_translation_used' => FALSE,
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

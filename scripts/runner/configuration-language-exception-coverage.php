<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';

$expected = [
  'core.entity_form_display.node.page.default' => [
    'content.field_home_components.settings.title' => [
      'segments' => ['content', 'field_home_components', 'settings', 'title'],
      'source' => 'Paragraphe',
      'english' => 'Paragraph',
    ],
    'content.field_home_components.settings.title_plural' => [
      'segments' => [
        'content',
        'field_home_components',
        'settings',
        'title_plural',
      ],
      'source' => 'Paragraphes',
      'english' => 'Paragraphs',
    ],
  ],
  'field.storage.node.ai_automator_status' => [
    'settings.allowed_values.0.label' => [
      'segments' => ['settings', 'allowed_values', 0, 'label'],
      'source' => 'Pending',
      'english' => 'Pending',
    ],
    'settings.allowed_values.1.label' => [
      'segments' => ['settings', 'allowed_values', 1, 'label'],
      'source' => 'Processing',
      'english' => 'Processing',
    ],
    'settings.allowed_values.2.label' => [
      'segments' => ['settings', 'allowed_values', 2, 'label'],
      'source' => 'Failed',
      'english' => 'Failed',
    ],
    'settings.allowed_values.3.label' => [
      'segments' => ['settings', 'allowed_values', 3, 'label'],
      'source' => 'Finished',
      'english' => 'Finished',
    ],
  ],
];

$configFactory = \Drupal::service('config.factory');
$typedConfigManager = \Drupal::service('config.typed');
$overrideFactory = \Drupal::service('language.config_factory_override');

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
        $collectTranslatableLeaves($child, [...$segments, $key]) as $leaf
      ) {
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

$collectRawLeafPaths = NULL;
$collectRawLeafPaths = static function (
  mixed $value,
  array $segments = [],
) use (&$collectRawLeafPaths, $displayPath): array {
  if (is_array($value)) {
    $paths = [];
    foreach ($value as $key => $child) {
      foreach ($collectRawLeafPaths($child, [...$segments, $key]) as $path) {
        $paths[] = $path;
      }
    }
    return $paths;
  }

  return [$displayPath($segments)];
};

$items = [];
$problems = [];
$totalMaterial = 0;
$totalCovered = 0;
$totalSourceEqual = 0;

foreach ($expected as $name => $expectedPaths) {
  $basePath = $configDirectory . '/' . $name . '.yml';
  $englishPath = $configDirectory . '/language/en/' . $name . '.yml';
  if (!is_file($basePath) || !is_file($englishPath)) {
    $problems[] = [
      'name' => $name,
      'reason' => 'base_or_en_override_missing',
    ];
    continue;
  }

  $sourceData = Yaml::parseFile($basePath);
  $englishData = Yaml::parseFile($englishPath);
  if (!is_array($sourceData) || !is_array($englishData)) {
    $problems[] = [
      'name' => $name,
      'reason' => 'base_or_en_override_not_mapping',
    ];
    continue;
  }

  if (($sourceData['langcode'] ?? NULL) !== 'fr') {
    $problems[] = [
      'name' => $name,
      'reason' => 'repository_source_langcode_not_fr',
    ];
    continue;
  }

  $activeSource = $configFactory->getEditable($name);
  if (
    $activeSource->isNew()
    || ($activeSource->get('langcode') ?? NULL) !== 'fr'
  ) {
    $problems[] = [
      'name' => $name,
      'reason' => 'active_source_missing_or_not_fr',
    ];
    continue;
  }

  if (!$typedConfigManager->hasConfigSchema($name)) {
    $problems[] = [
      'name' => $name,
      'reason' => 'config_schema_missing',
    ];
    continue;
  }

  try {
    $typedSource = $typedConfigManager->createFromNameAndData(
      $name,
      $sourceData,
    );
    $materialPaths = [];
    foreach ($collectTranslatableLeaves($typedSource) as $leaf) {
      if ($isMaterial($leaf['value'])) {
        $materialPaths[] = $leaf['path'];
      }
    }
  }
  catch (Throwable $exception) {
    $problems[] = [
      'name' => $name,
      'reason' => 'typed_config_traversal_failed',
      'error_class' => $exception::class,
    ];
    continue;
  }

  sort($materialPaths, SORT_STRING);
  $expectedMaterialPaths = array_keys($expectedPaths);
  sort($expectedMaterialPaths, SORT_STRING);
  $rawOverridePaths = $collectRawLeafPaths($englishData);
  sort($rawOverridePaths, SORT_STRING);

  if ($materialPaths !== $expectedMaterialPaths) {
    $problems[] = [
      'name' => $name,
      'reason' => 'material_translatable_paths_changed',
      'actual_paths' => $materialPaths,
      'expected_paths' => $expectedMaterialPaths,
    ];
    continue;
  }

  if ($rawOverridePaths !== $expectedMaterialPaths) {
    $problems[] = [
      'name' => $name,
      'reason' => 'en_override_not_minimal_or_incomplete',
      'actual_paths' => $rawOverridePaths,
      'expected_paths' => $expectedMaterialPaths,
    ];
    continue;
  }

  $activeEnglish = $overrideFactory->getOverride('en', $name);
  if ($activeEnglish->isNew()) {
    $problems[] = [
      'name' => $name,
      'reason' => 'active_en_override_missing',
    ];
    continue;
  }
  $activeEnglishData = $activeEnglish->get();

  $sourceEqualCount = 0;
  foreach ($expectedPaths as $path => $expectation) {
    $sourceExists = FALSE;
    $sourceValue = NestedArray::getValue(
      $sourceData,
      $expectation['segments'],
      $sourceExists,
    );
    $englishExists = FALSE;
    $englishValue = NestedArray::getValue(
      $englishData,
      $expectation['segments'],
      $englishExists,
    );
    $activeEnglishExists = FALSE;
    $activeEnglishValue = NestedArray::getValue(
      $activeEnglishData,
      $expectation['segments'],
      $activeEnglishExists,
    );

    if (
      !$sourceExists
      || !$englishExists
      || !$activeEnglishExists
      || $sourceValue !== $expectation['source']
      || $englishValue !== $expectation['english']
      || $activeEnglishValue !== $expectation['english']
    ) {
      $problems[] = [
        'name' => $name,
        'reason' => 'expected_translation_value_mismatch',
        'path' => $path,
      ];
      continue 2;
    }

    if ($sourceValue === $englishValue) {
      $sourceEqualCount++;
    }
  }

  $itemMaterialCount = count($expectedMaterialPaths);
  $totalMaterial += $itemMaterialCount;
  $totalCovered += $itemMaterialCount;
  $totalSourceEqual += $sourceEqualCount;
  $items[] = [
    'name' => $name,
    'classification' => 'en_override_complete_and_minimal',
    'material_translatable_leaf_count' => $itemMaterialCount,
    'explicit_en_coverage_count' => $itemMaterialCount,
    'source_equal_count' => $sourceEqualCount,
    'covered_paths' => $expectedMaterialPaths,
  ];
}

usort(
  $items,
  static fn(array $left, array $right): int => $left['name'] <=> $right['name'],
);

$status = 'PASS';
$verdict = 'TWO_EXCEPTIONS_EXPLICITLY_COVERED';
if (
  count($items) !== 2
  || $totalMaterial !== 6
  || $totalCovered !== 6
  || $problems !== []
) {
  $status = 'FAIL';
  $verdict = 'EXCEPTION_COVERAGE_FAILED';
}

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'counts' => [
    'expected_configurations' => 2,
    'classified' => count($items),
    'material_translatable_leaf_count' => $totalMaterial,
    'explicit_en_coverage_count' => $totalCovered,
    'source_equal_count' => $totalSourceEqual,
    'problem_count' => count($problems),
  ],
  'problems' => $problems,
  'items' => $items,
  'constraints' => [
    'read_only' => TRUE,
    'base_langcode_migration_allowed_by_this_proof' => FALSE,
    'bulk_langcode_replacement_allowed' => FALSE,
    'config_language_lock_activation_allowed_by_this_proof' => FALSE,
    'config_export_allowed_by_this_proof' => FALSE,
    'production_mutation_allowed_by_this_proof' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

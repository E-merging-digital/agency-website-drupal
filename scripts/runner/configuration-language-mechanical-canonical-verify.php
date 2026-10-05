<?php

declare(strict_types=1);

use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$cohortPath = $projectRoot . '/docs/evidence/configuration-language-mechanical-cohort-713.yml';

if (!is_dir($configDirectory) || !is_file($cohortPath)) {
  throw new RuntimeException('Required canonical verification inputs are missing.');
}

$cohort = Yaml::parseFile($cohortPath);
if (!is_array($cohort) || (int) ($cohort['expected_count'] ?? -1) !== 39) {
  throw new RuntimeException('Expected the exact 39-object #713 cohort.');
}

$excluded = [
  'core.entity_form_display.node.page.default',
  'field.storage.node.ai_automator_status',
];
if (($cohort['excluded_review_required'] ?? NULL) !== $excluded) {
  throw new RuntimeException('The excluded #711 exception set drifted.');
}

$items = $cohort['items'] ?? NULL;
if (!is_array($items) || count($items) !== 39) {
  throw new RuntimeException('The #713 cohort inventory is invalid.');
}

$typedConfigManager = \Drupal::service('config.typed');
$configFactory = \Drupal::service('config.factory');
$configManager = \Drupal::service('config.manager');

$collectTranslatableLeaves = NULL;
$collectTranslatableLeaves = static function (
  TypedDataInterface $element,
  array $segments = [],
) use (&$collectTranslatableLeaves): array {
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
    'path' => implode('.', array_map(static fn($part): string => (string) $part, $segments)),
    'value' => $element->getValue(),
  ]];
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

$problems = [];
$resultItems = [];
$seen = [];
$materialLeafCount = 0;

foreach ($items as $item) {
  if (!is_array($item) || !is_string($item['name'] ?? NULL) || !is_string($item['type'] ?? NULL)) {
    throw new RuntimeException('Invalid #713 cohort item.');
  }

  $name = $item['name'];
  $expectedType = $item['type'];
  if (isset($seen[$name]) || in_array($name, $excluded, TRUE)) {
    throw new RuntimeException("Duplicate or excluded cohort item: $name");
  }
  $seen[$name] = TRUE;

  $repositoryPath = $configDirectory . '/' . $name . '.yml';
  $repositoryData = is_file($repositoryPath) ? Yaml::parseFile($repositoryPath) : NULL;
  $activeConfig = $configFactory->getEditable($name);
  $activeData = $activeConfig->get();

  $itemProblems = [];
  if (!is_array($repositoryData)) {
    $itemProblems[] = 'repository_config_missing';
  }
  elseif (($repositoryData['langcode'] ?? NULL) !== 'en') {
    $itemProblems[] = 'repository_langcode_not_en';
  }

  if ($activeConfig->isNew()) {
    $itemProblems[] = 'active_config_missing';
  }
  elseif (($activeData['langcode'] ?? NULL) !== 'en') {
    $itemProblems[] = 'active_langcode_not_en';
  }

  $entityTypeId = $configManager->getEntityTypeIdByName($name);
  if ($entityTypeId !== $expectedType) {
    $itemProblems[] = 'entity_type_mismatch';
  }

  $materialPaths = [];
  if (!$typedConfigManager->hasConfigSchema($name)) {
    $itemProblems[] = 'config_schema_missing';
  }
  elseif (!$activeConfig->isNew()) {
    try {
      $typed = $typedConfigManager->createFromNameAndData($name, $activeData);
      foreach ($collectTranslatableLeaves($typed) as $leaf) {
        if ($isMaterial($leaf['value'])) {
          $materialPaths[] = $leaf['path'];
        }
      }
      sort($materialPaths, SORT_STRING);
      $materialLeafCount += count($materialPaths);
      if ($materialPaths !== []) {
        $itemProblems[] = 'material_translatable_source_detected';
      }
    }
    catch (Throwable $exception) {
      $itemProblems[] = 'typed_config_traversal_failed:' . $exception::class;
    }
  }

  if ($itemProblems !== []) {
    $problems[] = [
      'name' => $name,
      'reasons' => $itemProblems,
      'material_paths' => $materialPaths,
    ];
  }

  $resultItems[] = [
    'name' => $name,
    'type' => $expectedType,
    'repository_langcode' => is_array($repositoryData) ? ($repositoryData['langcode'] ?? NULL) : NULL,
    'active_langcode' => $activeConfig->isNew() ? NULL : ($activeData['langcode'] ?? NULL),
    'material_translatable_leaf_count' => count($materialPaths),
    'classification' => $itemProblems === []
      ? 'canonical_en_no_material_translatable_source'
      : 'problem',
  ];
}

$exceptionItems = [];
foreach ($excluded as $name) {
  $repositoryPath = $configDirectory . '/' . $name . '.yml';
  $overridePath = $configDirectory . '/language/en/' . $name . '.yml';
  $repositoryData = is_file($repositoryPath) ? Yaml::parseFile($repositoryPath) : NULL;
  $activeConfig = $configFactory->getEditable($name);

  $baseFr = is_array($repositoryData)
    && ($repositoryData['langcode'] ?? NULL) === 'fr'
    && !$activeConfig->isNew()
    && ($activeConfig->get('langcode') ?? NULL) === 'fr';
  $overridePresent = is_file($overridePath);

  if (!$baseFr || !$overridePresent) {
    $problems[] = [
      'name' => $name,
      'reasons' => array_values(array_filter([
        $baseFr ? NULL : 'excluded_exception_base_not_fr',
        $overridePresent ? NULL : 'excluded_exception_en_override_missing',
      ])),
    ];
  }

  $exceptionItems[] = [
    'name' => $name,
    'base_langcode' => $activeConfig->isNew() ? NULL : $activeConfig->get('langcode'),
    'en_override_present' => $overridePresent,
  ];
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'THIRTY_NINE_MECHANICAL_CANONICAL_MIGRATION_VERIFIED'
  : 'MECHANICAL_CANONICAL_MIGRATION_VERIFICATION_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'counts' => [
    'cohort_expected' => 39,
    'cohort_verified_en' => count(array_filter(
      $resultItems,
      static fn(array $item): bool => $item['classification'] === 'canonical_en_no_material_translatable_source',
    )),
    'material_translatable_leaf_count' => $materialLeafCount,
    'excluded_exceptions_expected' => 2,
    'excluded_exceptions_preserved_fr' => count(array_filter(
      $exceptionItems,
      static fn(array $item): bool => $item['base_langcode'] === 'fr' && $item['en_override_present'] === TRUE,
    )),
    'problem_count' => count($problems),
  ],
  'problems' => $problems,
  'items' => $resultItems,
  'excluded_exceptions' => $exceptionItems,
  'constraints' => [
    'read_only' => TRUE,
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

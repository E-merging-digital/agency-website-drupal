<?php

declare(strict_types=1);

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TraversableTypedDataInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot . '/docs/evidence/configuration-language-base-field-runtime-cohort-760.yml';

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #760 configuration or cohort manifest is missing.');
}

$manifest = Yaml::parseFile($manifestPath);
if (!is_array($manifest)) {
  throw new RuntimeException('Issue #760 cohort manifest must be a mapping.');
}

$expectedDistribution = [
  '__none__' => 59,
  'en' => 466,
  'fr' => 69,
  'und' => 1,
];
$expectedException = 'core.base_field_override.canvas_page.canvas_page.components';
$exactNames = $manifest['cohort']['exact_match_names'] ?? NULL;
$exception = $manifest['cohort']['exception'] ?? NULL;
if (($manifest['issue'] ?? NULL) !== 760
  || ($manifest['cohort']['total'] ?? NULL) !== 53
  || ($manifest['cohort']['exact_runtime_source_match'] ?? NULL) !== 52
  || ($manifest['cohort']['review_exception'] ?? NULL) !== 1
  || ($manifest['cohort']['unresolved'] ?? NULL) !== 0
  || ($manifest['target']['total_config'] ?? NULL) !== 595
  || ($manifest['target']['distribution'] ?? NULL) !== $expectedDistribution
  || !is_array($exactNames)
  || count($exactNames) !== 52
  || !is_array($exception)
  || ($exception['name'] ?? NULL) !== $expectedException) {
  throw new RuntimeException('Issue #760 verifier contract drifted.');
}
foreach ($exactNames as $name) {
  if (!is_string($name) || $name === '') {
    throw new RuntimeException('Issue #760 verifier found an invalid cohort name.');
  }
}
$allNames = [...$exactNames, $expectedException];
sort($allNames, SORT_STRING);
if (count($allNames) !== 53 || count(array_unique($allNames)) !== 53) {
  throw new RuntimeException('Issue #760 verifier requires 53 unique names.');
}

$countByLangcode = static function (array $configs): array {
  $counts = [];
  foreach ($configs as $data) {
    if (!is_array($data)) {
      continue;
    }
    $langcode = isset($data['langcode']) && is_string($data['langcode'])
      ? $data['langcode']
      : '__none__';
    $counts[$langcode] = ($counts[$langcode] ?? 0) + 1;
  }
  ksort($counts, SORT_STRING);
  return $counts;
};

$repositoryConfigs = [];
$baseFiles = glob($configDirectory . '/*.yml');
if ($baseFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate repository configuration.');
}
sort($baseFiles, SORT_STRING);
foreach ($baseFiles as $path) {
  $name = basename($path, '.yml');
  $data = Yaml::parseFile($path);
  if (!is_array($data)) {
    throw new RuntimeException("Repository configuration $name is not a mapping.");
  }
  $repositoryConfigs[$name] = $data;
}
ksort($repositoryConfigs, SORT_STRING);

$activeStorage = \Drupal::service('config.storage');
if (!$activeStorage instanceof StorageInterface) {
  throw new RuntimeException('Active configuration storage is unavailable.');
}
$activeConfigs = [];
$activeNames = $activeStorage->listAll();
sort($activeNames, SORT_STRING);
foreach ($activeNames as $name) {
  $data = $activeStorage->read($name);
  if (!is_array($data)) {
    throw new RuntimeException("Active configuration $name cannot be read.");
  }
  $activeConfigs[$name] = $data;
}
$activeEnglish = $activeStorage->createCollection('language.en');
$activeFrench = $activeStorage->createCollection('language.fr');

$repositoryDistribution = $countByLangcode($repositoryConfigs);
$activeDistribution = $countByLangcode($activeConfigs);
$problems = [];
if (count($repositoryConfigs) !== 595) {
  $problems[] = 'repository_total_not_595';
}
if (count($activeConfigs) !== 595) {
  $problems[] = 'active_total_not_595';
}
if ($repositoryDistribution !== $expectedDistribution) {
  $problems[] = 'repository_distribution_mismatch';
}
if ($activeDistribution !== $expectedDistribution) {
  $problems[] = 'active_distribution_mismatch';
}

$coreExtension = $repositoryConfigs['core.extension'] ?? [];
$activeCoreExtension = $activeConfigs['core.extension'] ?? [];
if (isset($coreExtension['module']['config_language_lock'])) {
  $problems[] = 'repository_config_language_lock_persisted';
}
if (isset($activeCoreExtension['module']['config_language_lock'])) {
  $problems[] = 'active_config_language_lock_enabled';
}
if (\Drupal::moduleHandler()->moduleExists('config_language_lock')) {
  $problems[] = 'runtime_config_language_lock_enabled';
}
if (is_file($configDirectory . '/config_language_lock.settings.yml')) {
  $problems[] = 'config_language_lock_settings_persisted';
}

$systemSite = $repositoryConfigs['system.site'] ?? [];
$activeSystemSite = $activeConfigs['system.site'] ?? [];
if (($systemSite['default_langcode'] ?? NULL) !== 'fr'
  || ($activeSystemSite['default_langcode'] ?? NULL) !== 'fr') {
  $problems[] = 'site_default_language_not_fr';
}
$footerMenu = $repositoryConfigs['system.menu.footer'] ?? [];
$activeFooterMenu = $activeConfigs['system.menu.footer'] ?? [];
if (($footerMenu['langcode'] ?? NULL) !== 'und'
  || ($activeFooterMenu['langcode'] ?? NULL) !== 'und') {
  $problems[] = 'footer_menu_special_langcode_not_und';
}
foreach (['und', 'zxx'] as $semanticLanguage) {
  $repositoryLanguage = $repositoryConfigs['language.entity.' . $semanticLanguage] ?? [];
  $activeLanguage = $activeConfigs['language.entity.' . $semanticLanguage] ?? [];
  if (($repositoryLanguage['id'] ?? NULL) !== $semanticLanguage
    || ($activeLanguage['id'] ?? NULL) !== $semanticLanguage) {
    $problems[] = 'semantic_language_identity_changed:' . $semanticLanguage;
  }
}

$typedConfigManager = \Drupal::service('config.typed');
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

$verifiedItems = [];
$verified = 0;
foreach ($allNames as $name) {
  $repositoryData = $repositoryConfigs[$name] ?? NULL;
  $activeData = $activeConfigs[$name] ?? NULL;
  $itemProblems = [];
  $comparisons = [];

  if (!is_array($repositoryData)) {
    $itemProblems[] = 'repository_missing';
  }
  elseif (($repositoryData['langcode'] ?? NULL) !== 'en') {
    $itemProblems[] = 'repository_langcode_not_en';
  }
  if (!is_array($activeData)) {
    $itemProblems[] = 'active_missing';
  }
  elseif (($activeData['langcode'] ?? NULL) !== 'en') {
    $itemProblems[] = 'active_langcode_not_en';
  }
  if (is_array($repositoryData) && is_array($activeData) && $repositoryData != $activeData) {
    $itemProblems[] = 'active_repository_data_mismatch';
  }

  $repoEnPath = $configDirectory . '/language/en/' . $name . '.yml';
  $repoFrPath = $configDirectory . '/language/fr/' . $name . '.yml';
  if (is_file($repoEnPath) || $activeEnglish->exists($name)) {
    $itemProblems[] = 'unexpected_en_override';
  }

  if ($name === $expectedException) {
    if (!is_array($repositoryData) || ($repositoryData['label'] ?? NULL) !== 'Components') {
      $itemProblems[] = 'exception_base_label_not_components';
    }
    $repoFr = is_file($repoFrPath) ? Yaml::parseFile($repoFrPath) : NULL;
    $activeFr = $activeFrench->read($name);
    if ($repoFr !== ['label' => 'Composants']) {
      $itemProblems[] = 'exception_repository_fr_override_not_exact';
    }
    if ($activeFr !== ['label' => 'Composants']) {
      $itemProblems[] = 'exception_active_fr_override_not_exact';
    }
  }
  elseif (is_file($repoFrPath) || $activeFrench->exists($name)) {
    $itemProblems[] = 'unexpected_fr_override';
  }

  if (is_array($repositoryData)) {
    $entityTypeId = isset($repositoryData['entity_type']) && is_string($repositoryData['entity_type'])
      ? $repositoryData['entity_type']
      : '';
    $fieldName = isset($repositoryData['field_name']) && is_string($repositoryData['field_name'])
      ? $repositoryData['field_name']
      : '';
    if ($entityTypeId === '' || $fieldName === '') {
      $itemProblems[] = 'override_identity_incomplete';
    }
    elseif (!$typedConfigManager->hasConfigSchema($name)) {
      $itemProblems[] = 'config_schema_missing';
    }
    else {
      try {
        $typedSource = $typedConfigManager->createFromNameAndData($name, $repositoryData);
        $allLeaves = $collectTranslatableLeaves($typedSource);
        $materialLeaves = array_values(array_filter(
          $allLeaves,
          static fn(array $leaf): bool => $isMaterial($leaf['value']),
        ));
        usort(
          $materialLeaves,
          static fn(array $left, array $right): int => $left['path'] <=> $right['path'],
        );
        if ($materialLeaves === []) {
          $itemProblems[] = 'no_material_translatable_source_leaves';
        }

        $baseDefinitions = $entityFieldManager->getBaseFieldDefinitions($entityTypeId);
        $baseDefinition = $baseDefinitions[$fieldName] ?? NULL;
        if ($baseDefinition === NULL) {
          $itemProblems[] = 'base_field_definition_missing';
        }
        else {
          $settings = $baseDefinition->getSettings();
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
              $itemProblems[] = 'runtime_source_unresolved:' . $leaf['path'];
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

            $resolved = $resolveSourceValue($runtimeRaw);
            $matches = $resolved['resolved'] && $resolved['value'] === $leaf['value'];
            if (!$matches) {
              $itemProblems[] = 'runtime_source_mismatch:' . $leaf['path'];
            }
            $comparisons[] = [
              'path' => $leaf['path'],
              'config_value' => $leaf['value'],
              'runtime_source_value' => $resolved['value'],
              'resolver' => $resolver,
              'source_kind' => $resolved['source_kind'],
              'resolved' => $resolved['resolved'],
              'matches' => $matches,
            ];
          }
        }
      }
      catch (Throwable $exception) {
        $itemProblems[] = 'runtime_verification_exception:' . $exception::class;
      }
    }
  }

  $itemProblems = array_values(array_unique($itemProblems));
  if ($itemProblems === []) {
    $verified++;
  }
  else {
    foreach ($itemProblems as $problem) {
      $problems[] = $name . ':' . $problem;
    }
  }

  $verifiedItems[] = [
    'name' => $name,
    'repository_langcode' => is_array($repositoryData) ? ($repositoryData['langcode'] ?? NULL) : NULL,
    'active_langcode' => is_array($activeData) ? ($activeData['langcode'] ?? NULL) : NULL,
    'runtime_comparisons' => $comparisons,
    'problems' => $itemProblems,
  ];
}

if ($verified !== 53) {
  $problems[] = 'verified_count_not_53';
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $problems === []
  ? 'FIFTY_THREE_BASE_FIELD_RUNTIME_CANONICAL_PROMOTIONS_VERIFIED'
  : 'BASE_FIELD_RUNTIME_CANONICAL_PROMOTION_VERIFICATION_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'verified' => $verified,
  'remaining_fr_review_required' => 69,
  'repository_total' => count($repositoryConfigs),
  'active_total' => count($activeConfigs),
  'repository_distribution' => $repositoryDistribution,
  'active_distribution' => $activeDistribution,
  'items' => $verifiedItems,
  'exception' => [
    'name' => $expectedException,
    'base_label' => $repositoryConfigs[$expectedException]['label'] ?? NULL,
    'fr_override' => is_file($configDirectory . '/language/fr/' . $expectedException . '.yml')
      ? Yaml::parseFile($configDirectory . '/language/fr/' . $expectedException . '.yml')
      : NULL,
  ],
  'problems' => $problems,
  'constraints' => [
    'runtime_source_uses_untranslated_translatable_markup' => TRUE,
    'natural_language_heuristic_used' => FALSE,
    'config_language_lock_enabled_canonically' => FALSE,
    'site_default_language' => 'fr',
    'semantic_und_zxx_preserved' => TRUE,
    'config_export_used' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

if ($status !== 'PASS') {
  exit(1);
}

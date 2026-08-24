<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot
  . '/docs/evidence/configuration-language-safe-provenance-cohort-754.yml';

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #754 configuration or manifest is missing.');
}

$manifest = Yaml::parseFile($manifestPath);
if (!is_array($manifest)) {
  throw new RuntimeException('Issue #754 manifest must be a mapping.');
}

$items = $manifest['items'] ?? [];
if (!is_array($items) || count($items) !== 17) {
  throw new RuntimeException('Issue #754 manifest must contain exactly 17 items.');
}

$names = [];
foreach ($items as $item) {
  if (!is_array($item) || !isset($item['name']) || !is_string($item['name'])) {
    throw new RuntimeException('Issue #754 manifest item is invalid.');
  }
  $names[] = $item['name'];
}
if (count(array_unique($names)) !== 17) {
  throw new RuntimeException('Issue #754 manifest contains duplicate names.');
}
sort($names, SORT_STRING);

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

$expected = [
  '__none__' => 59,
  'en' => 413,
  'fr' => 122,
  'und' => 1,
];
$repositoryDistribution = $countByLangcode($repositoryConfigs);
$activeDistribution = $countByLangcode($activeConfigs);

$problems = [];
if (count($repositoryConfigs) !== 595) {
  $problems[] = 'repository_total_not_595';
}
if (count($activeConfigs) !== 595) {
  $problems[] = 'active_total_not_595';
}
if ($repositoryDistribution !== $expected) {
  $problems[] = 'repository_distribution_mismatch';
}
if ($activeDistribution !== $expected) {
  $problems[] = 'active_distribution_mismatch';
}

$verifiedItems = [];
foreach ($names as $name) {
  $repositoryData = $repositoryConfigs[$name] ?? NULL;
  $activeData = $activeConfigs[$name] ?? NULL;
  $itemProblems = [];

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

  if (is_file($configDirectory . '/language/en/' . $name . '.yml')) {
    $itemProblems[] = 'unexpected_en_override';
  }

  if ($itemProblems !== []) {
    foreach ($itemProblems as $problem) {
      $problems[] = $name . ':' . $problem;
    }
  }

  $verifiedItems[] = [
    'name' => $name,
    'repository_langcode' => is_array($repositoryData)
      ? ($repositoryData['langcode'] ?? NULL)
      : NULL,
    'active_langcode' => is_array($activeData)
      ? ($activeData['langcode'] ?? NULL)
      : NULL,
    'en_override_present' => is_file(
      $configDirectory . '/language/en/' . $name . '.yml',
    ),
    'problems' => $itemProblems,
  ];
}

$coreExtension = $repositoryConfigs['core.extension'] ?? [];
if (isset($coreExtension['module']['config_language_lock'])) {
  $problems[] = 'config_language_lock_persisted';
}
if (is_file($configDirectory . '/config_language_lock.settings.yml')) {
  $problems[] = 'config_language_lock_settings_persisted';
}

$systemSite = $repositoryConfigs['system.site'] ?? [];
if (($systemSite['default_langcode'] ?? NULL) !== 'fr') {
  $problems[] = 'site_default_language_not_fr';
}
$footerMenu = $repositoryConfigs['system.menu.footer'] ?? [];
if (($footerMenu['langcode'] ?? NULL) !== 'und') {
  $problems[] = 'footer_menu_special_langcode_not_und';
}
foreach (['und', 'zxx'] as $semanticLanguage) {
  $language = $repositoryConfigs['language.entity.' . $semanticLanguage] ?? [];
  if (($language['id'] ?? NULL) !== $semanticLanguage) {
    $problems[] = 'semantic_language_identity_changed:' . $semanticLanguage;
  }
}

$excludedName = 'system.action.agency_ai_translate_nodes_bulk_action';
$excluded = $repositoryConfigs[$excludedName] ?? [];
if (($excluded['langcode'] ?? NULL) !== 'en') {
  $problems[] = 'issue_752_excluded_action_not_en';
}
if (!is_file($configDirectory . '/language/fr/' . $excludedName . '.yml')) {
  $problems[] = 'issue_752_french_override_missing';
}

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $problems === []
  ? 'SEVENTEEN_SAFE_PROVENANCE_PROMOTIONS_VERIFIED'
  : 'SAFE_PROVENANCE_PROMOTION_VERIFICATION_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'verified' => count($verifiedItems),
  'remaining_fr_review_required' => 122,
  'repository_total' => count($repositoryConfigs),
  'active_total' => count($activeConfigs),
  'repository_distribution' => $repositoryDistribution,
  'active_distribution' => $activeDistribution,
  'items' => $verifiedItems,
  'problems' => $problems,
  'constraints' => [
    'config_language_lock_enabled_canonically' => FALSE,
    'site_default_language' => 'fr',
    'translation_override_mutation_permitted' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

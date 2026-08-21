<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$policyPath = $projectRoot . '/docs/configuration-language-policy.yml';
$configDirectory = $projectRoot . '/config/sync';

if (!is_file($policyPath) || !is_dir($configDirectory)) {
  throw new RuntimeException('Configuration language policy or config/sync is missing.');
}

$policy = Yaml::parseFile($policyPath);
if (!is_array($policy)) {
  throw new RuntimeException('Configuration language policy must be a YAML mapping.');
}
if (($policy['policy_id'] ?? NULL) !== 'agency-configuration-language-v1') {
  throw new RuntimeException('Unexpected configuration language policy id.');
}
if (($policy['status'] ?? NULL) !== 'migration_required') {
  throw new RuntimeException('Initial audit is restricted to migration_required policy state.');
}
if (($policy['canonical_configuration_language'] ?? NULL) !== 'en') {
  throw new RuntimeException('Initial audit expects canonical configuration language en.');
}
if (($policy['enforce_consistency'] ?? TRUE) !== FALSE) {
  throw new RuntimeException('Initial audit must run before configuration-language enforcement.');
}

$configManager = \Drupal::service('config.manager');
$activeStorage = \Drupal::service('config.storage');

$increment = static function (array &$counts, string $key): void {
  $counts[$key] = ($counts[$key] ?? 0) + 1;
};

$classify = static function (string $name) use ($configManager): array {
  try {
    $entityTypeId = $configManager->getEntityTypeIdByName($name);
  }
  catch (Throwable) {
    $entityTypeId = NULL;
  }

  return [
    'kind' => $entityTypeId === NULL ? 'simple_config' : 'config_entity',
    'entity_type' => $entityTypeId,
  ];
};

$normaliseLangcode = static function (array $data): string {
  if (!array_key_exists('langcode', $data)) {
    return '__none__';
  }
  $value = $data['langcode'];
  if (!is_string($value) || $value === '') {
    return '__invalid__';
  }
  return $value;
};

$buildSummary = static function (array $entries): array {
  $byLangcode = [];
  $byKind = [];
  $byKindAndLangcode = [];

  foreach ($entries as $entry) {
    $langcode = (string) $entry['langcode'];
    $kind = (string) $entry['kind'];
    $byLangcode[$langcode] = ($byLangcode[$langcode] ?? 0) + 1;
    $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
    $byKindAndLangcode[$kind][$langcode] = ($byKindAndLangcode[$kind][$langcode] ?? 0) + 1;
  }

  ksort($byLangcode);
  ksort($byKind);
  ksort($byKindAndLangcode);
  foreach ($byKindAndLangcode as &$counts) {
    ksort($counts);
  }
  unset($counts);

  return [
    'total' => count($entries),
    'by_langcode' => $byLangcode,
    'by_kind' => $byKind,
    'by_kind_and_langcode' => $byKindAndLangcode,
  ];
};

$repositoryEntries = [];
$repositoryByName = [];
$repositoryFiles = glob($configDirectory . '/*.yml');
if ($repositoryFiles === FALSE) {
  throw new RuntimeException('Unable to enumerate repository configuration.');
}
sort($repositoryFiles, SORT_STRING);

foreach ($repositoryFiles as $path) {
  $data = Yaml::parseFile($path);
  if (!is_array($data)) {
    $data = [];
  }
  $name = basename($path, '.yml');
  $classification = $classify($name);
  $entry = [
    'name' => $name,
    'langcode' => $normaliseLangcode($data),
    'kind' => $classification['kind'],
    'entity_type' => $classification['entity_type'],
  ];
  $repositoryEntries[] = $entry;
  $repositoryByName[$name] = $entry;
}

$activeEntries = [];
$activeByName = [];
$activeNames = $activeStorage->listAll();
sort($activeNames, SORT_STRING);
foreach ($activeNames as $name) {
  $data = $activeStorage->read($name);
  if (!is_array($data)) {
    $data = [];
  }
  $classification = $classify($name);
  $entry = [
    'name' => $name,
    'langcode' => $normaliseLangcode($data),
    'kind' => $classification['kind'],
    'entity_type' => $classification['entity_type'],
  ];
  $activeEntries[] = $entry;
  $activeByName[$name] = $entry;
}

$missingActive = array_values(array_diff(array_keys($repositoryByName), array_keys($activeByName)));
$missingRepository = array_values(array_diff(array_keys($activeByName), array_keys($repositoryByName)));
sort($missingActive, SORT_STRING);
sort($missingRepository, SORT_STRING);

$langcodeMismatches = [];
foreach (array_intersect(array_keys($repositoryByName), array_keys($activeByName)) as $name) {
  $repositoryLangcode = $repositoryByName[$name]['langcode'];
  $activeLangcode = $activeByName[$name]['langcode'];
  if ($repositoryLangcode !== $activeLangcode) {
    $langcodeMismatches[] = [
      'name' => $name,
      'repository' => $repositoryLangcode,
      'active' => $activeLangcode,
    ];
  }
}
usort($langcodeMismatches, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

$translationDirectories = [];
$languageDirectories = glob($configDirectory . '/language/*', GLOB_ONLYDIR);
if ($languageDirectories === FALSE) {
  throw new RuntimeException('Unable to enumerate configuration translation directories.');
}
sort($languageDirectories, SORT_STRING);
foreach ($languageDirectories as $directory) {
  $files = glob($directory . '/*.yml');
  if ($files === FALSE) {
    throw new RuntimeException('Unable to enumerate configuration translation files.');
  }
  sort($files, SORT_STRING);
  $translationDirectories[] = [
    'language' => basename($directory),
    'count' => count($files),
    'config_names' => array_map(
      static fn(string $path): string => basename($path, '.yml'),
      $files,
    ),
  ];
}

$watchlist = [];
foreach (($policy['migration_watchlist'] ?? []) as $relativePath) {
  if (!is_string($relativePath) || $relativePath === '') {
    continue;
  }
  $absolutePath = $projectRoot . '/' . ltrim($relativePath, '/');
  $entry = [
    'path' => $relativePath,
    'exists' => file_exists($absolutePath),
    'type' => is_dir($absolutePath) ? 'directory' : (is_file($absolutePath) ? 'file' : 'missing'),
  ];
  if (is_file($absolutePath) && str_ends_with($absolutePath, '.yml')) {
    $data = Yaml::parseFile($absolutePath);
    if (!is_array($data)) {
      $data = [];
    }
    $entry['langcode'] = $normaliseLangcode($data);
  }
  $watchlist[] = $entry;
}

$repositorySummary = $buildSummary($repositoryEntries);
$activeSummary = $buildSummary($activeEntries);
$observedBaseLangcodes = array_keys($repositorySummary['by_langcode']);
$technicalBaseLangcodes = array_values(array_filter(
  $observedBaseLangcodes,
  static fn(string $langcode): bool => !in_array($langcode, ['__none__', 'und', 'zxx'], TRUE),
));
sort($technicalBaseLangcodes, SORT_STRING);

$snapshot = [
  'schema_version' => 1,
  'policy' => [
    'policy_id' => $policy['policy_id'],
    'status' => $policy['status'],
    'canonical_configuration_language' => $policy['canonical_configuration_language'],
    'site_default_language' => $policy['site_default_language'] ?? NULL,
    'site_languages' => $policy['site_languages'] ?? [],
    'enforce_consistency' => $policy['enforce_consistency'],
  ],
  'drupal' => [
    'version' => \Drupal::VERSION,
    'site_default_language' => \Drupal::languageManager()->getDefaultLanguage()->getId(),
  ],
  'repository' => [
    'summary' => $repositorySummary,
    'entries' => $repositoryEntries,
  ],
  'active' => [
    'summary' => $activeSummary,
    'entries' => $activeEntries,
  ],
  'repository_active_comparison' => [
    'missing_from_active' => $missingActive,
    'missing_from_repository' => $missingRepository,
    'langcode_mismatches' => $langcodeMismatches,
  ],
  'translations' => $translationDirectories,
  'migration_watchlist' => $watchlist,
  'observations' => [
    'observed_base_langcodes' => $observedBaseLangcodes,
    'technical_base_langcodes' => $technicalBaseLangcodes,
    'mixed_technical_base_languages' => count($technicalBaseLangcodes) > 1,
    'canonical_language_already_uniform' => $technicalBaseLangcodes === ['en'],
  ],
];

echo json_encode(
  $snapshot,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

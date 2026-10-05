<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

$stage = getenv('AGENCY_CONFIG_LANGUAGE_PROVENANCE_STAGE');
if (!in_array($stage, ['repository-source', 'pre-manager', 'post-manager', 'final-candidate'], TRUE)) {
  throw new RuntimeException('Unsupported repository EN provenance stage.');
}

$syncRoot = $root . '/config/sync';
$artifactRoot = $root . '/artifacts/config-language-repository-en-intent-1316';
$reviewArtifactRoot = $root . '/artifacts/config-language-policy-migration-1316';

$normalize = NULL;
$normalize = static function (mixed $value) use (&$normalize): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (array_is_list($value)) {
    return array_map($normalize, $value);
  }
  $keys = array_keys($value);
  usort($keys, static fn(int|string $a, int|string $b): int => strcmp((string) $a, (string) $b));
  $normalized = [];
  foreach ($keys as $key) {
    $normalized[$key] = $normalize($value[$key]);
  }
  return $normalized;
};

$fingerprint = static function (mixed $value) use ($normalize): string {
  return hash('sha256', json_encode(
    $normalize($value),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  ));
};

$readYaml = static function (string $path): ?array {
  if (!is_file($path)) {
    return NULL;
  }
  $parsed = Yaml::parseFile($path);
  if ($parsed === NULL) {
    return [];
  }
  if (!is_array($parsed)) {
    throw new RuntimeException('Configuration YAML is not an array: ' . $path);
  }
  return $parsed;
};

$deepMerge = NULL;
$deepMerge = static function (array $base, array $override) use (&$deepMerge): array {
  foreach ($override as $key => $value) {
    if (
      array_key_exists($key, $base)
      && is_array($base[$key])
      && is_array($value)
    ) {
      $base[$key] = $deepMerge($base[$key], $value);
      continue;
    }
    $base[$key] = $value;
  }
  return $base;
};

$effectiveEnFromSync = static function (string $name) use (
  $syncRoot,
  $readYaml,
  $deepMerge,
): ?array {
  $base = $readYaml($syncRoot . '/' . $name . '.yml');
  if ($base === NULL) {
    return NULL;
  }
  $override = $readYaml($syncRoot . '/language/en/' . $name . '.yml');
  return $override === NULL ? $base : $deepMerge($base, $override);
};

$listSyncNames = static function () use ($syncRoot): array {
  $names = [];
  foreach (glob($syncRoot . '/*.yml') ?: [] as $path) {
    $names[] = basename($path, '.yml');
  }
  sort($names, SORT_STRING);
  return $names;
};

$pathValue = static function (array $data, string $path): array {
  $current = $data;
  foreach (explode('.', $path) as $segment) {
    if (!is_array($current) || !array_key_exists($segment, $current)) {
      return ['exists' => FALSE, 'value' => NULL];
    }
    $current = $current[$segment];
  }
  return ['exists' => TRUE, 'value' => $current];
};

$pathEvidence = static function (?array $data, string $path) use (
  $pathValue,
  $fingerprint,
): array {
  if ($data === NULL) {
    return [
      'exists' => FALSE,
      'sha256' => $fingerprint(['__missing_config__' => TRUE]),
    ];
  }
  $value = $pathValue($data, $path);
  return [
    'exists' => $value['exists'],
    'sha256' => $value['exists']
      ? $fingerprint($value['value'])
      : $fingerprint(['__missing_path__' => $path]),
  ];
};

$semanticObjectFingerprint = static function (?array $data) use ($fingerprint): string {
  if ($data === NULL) {
    return $fingerprint(['__missing_config__' => TRUE]);
  }
  unset($data['langcode']);
  return $fingerprint($data);
};

$targetDefinitions = [
  'language_entity_fr_label' => [
    'config' => 'language.entity.fr',
    'path' => 'label',
  ],
  'language_entity_fr_dependencies' => [
    'config' => 'language.entity.fr',
    'path' => 'dependencies',
  ],
  'llms_txt_content' => [
    'config' => 'llms_txt.settings',
    'path' => 'content',
  ],
  'taxonomy_tags_description' => [
    'config' => 'taxonomy.vocabulary.tags',
    'path' => 'description',
  ],
];

$targetEvidenceFromSync = static function () use (
  $targetDefinitions,
  $effectiveEnFromSync,
  $pathEvidence,
): array {
  $targets = [];
  foreach ($targetDefinitions as $key => $definition) {
    $targets[$key] = [
      'config' => $definition['config'],
      'path' => $definition['path'],
      ...$pathEvidence(
        $effectiveEnFromSync($definition['config']),
        $definition['path'],
      ),
    ];
  }
  return $targets;
};

$classifyPath = static function (string $configName, string $path): array {
  $typedManager = \Drupal::service('config.typed');
  try {
    $current = $typedManager->get($configName);
    foreach (explode('.', $path) as $segment) {
      if (!is_object($current) || !method_exists($current, 'get')) {
        throw new RuntimeException('Typed config path is not traversable.');
      }
      $current = $current->get($segment);
      if ($current === NULL) {
        throw new RuntimeException('Typed config path is absent.');
      }
    }
    if (!is_object($current) || !method_exists($current, 'getDataDefinition')) {
      throw new RuntimeException('Typed config data definition is absent.');
    }
    $definition = $current->getDataDefinition();
    if (!is_object($definition) || !method_exists($definition, 'getDataType')) {
      throw new RuntimeException('Typed config data type is absent.');
    }

    $type = $definition->getDataType();
    $translatable = NULL;
    if (method_exists($definition, 'getSetting')) {
      $setting = $definition->getSetting('translatable');
      if (is_bool($setting)) {
        $translatable = $setting;
      }
    }
    if ($translatable === NULL && is_string($type) && $type !== '') {
      try {
        $typeDefinition = $typedManager->getDefinition($type, FALSE);
        if (
          is_array($typeDefinition)
          && array_key_exists('translatable', $typeDefinition)
          && is_bool($typeDefinition['translatable'])
        ) {
          $translatable = $typeDefinition['translatable'];
        }
      }
      catch (Throwable) {
        // UNKNOWN remains intentional when runtime metadata is inconclusive.
      }
    }

    return [
      'schema_type' => is_string($type) && $type !== '' ? $type : 'UNKNOWN',
      'schema_definition_present' => 'YES',
      'schema_translatability' => $translatable === TRUE
        ? 'YES'
        : ($translatable === FALSE ? 'NO' : 'UNKNOWN'),
    ];
  }
  catch (Throwable) {
    return [
      'schema_type' => 'UNKNOWN',
      'schema_definition_present' => 'NO',
      'schema_translatability' => 'UNKNOWN',
    ];
  }
};

if ($stage === 'repository-source') {
  $objectFingerprints = [];
  foreach ($listSyncNames() as $name) {
    $objectFingerprints[$name] = $semanticObjectFingerprint(
      $effectiveEnFromSync($name),
    );
  }
  ksort($objectFingerprints, SORT_STRING);

  echo json_encode([
    'schema_version' => 1,
    'stage' => $stage,
    'object_count' => count($objectFingerprints),
    'object_fingerprints' => $objectFingerprints,
    'targets' => $targetEvidenceFromSync(),
    'raw_config_values_exposed' => FALSE,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
  return;
}

if (in_array($stage, ['pre-manager', 'post-manager'], TRUE)) {
  $configFactory = \Drupal::service('config.factory');
  $overrideFactory = \Drupal::service('language.config_factory_override');
  $language = \Drupal\language\Entity\ConfigurableLanguage::load('en');
  if (!$language instanceof \Drupal\language\Entity\ConfigurableLanguage) {
    throw new RuntimeException('English configurable language is unavailable.');
  }
  $overrideFactory->setLanguage($language);
  $configFactory->reset();

  $targets = [];
  foreach ($targetDefinitions as $key => $definition) {
    $data = $configFactory->get($definition['config'])->get();
    if (!is_array($data)) {
      throw new RuntimeException('Effective EN config is not an array: ' . $definition['config']);
    }
    $targets[$key] = [
      'config' => $definition['config'],
      'path' => $definition['path'],
      ...$pathEvidence($data, $definition['path']),
      ...$classifyPath($definition['config'], $definition['path']),
    ];
  }

  echo json_encode([
    'schema_version' => 1,
    'stage' => $stage,
    'targets' => $targets,
    'raw_config_values_exposed' => FALSE,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
  return;
}

$sourcePath = $artifactRoot . '/repository-source.json';
$prePath = $artifactRoot . '/pre-manager.json';
$postPath = $artifactRoot . '/post-manager.json';
$statusPath = $reviewArtifactRoot . '/config-name-status.txt';

foreach ([$sourcePath, $prePath, $postPath, $statusPath] as $requiredPath) {
  if (!is_file($requiredPath)) {
    throw new RuntimeException('Missing provenance input: ' . $requiredPath);
  }
}

$source = json_decode(file_get_contents($sourcePath), TRUE, 512, JSON_THROW_ON_ERROR);
$pre = json_decode(file_get_contents($prePath), TRUE, 512, JSON_THROW_ON_ERROR);
$post = json_decode(file_get_contents($postPath), TRUE, 512, JSON_THROW_ON_ERROR);
foreach ([$source, $pre, $post] as $snapshot) {
  if (($snapshot['raw_config_values_exposed'] ?? NULL) !== FALSE) {
    throw new RuntimeException('A provenance input exposed raw config values.');
  }
}

$cohort = [];
foreach (file($statusPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
  $parts = preg_split('/\t+/', $line);
  $path = $parts[count($parts) - 1] ?? '';
  if (!is_string($path)) {
    continue;
  }
  if (preg_match('#^config/sync/(?:language/[^/]+/)?(.+)\.yml$#', $path, $matches) !== 1) {
    continue;
  }
  $cohort[$matches[1]] = TRUE;
}
$cohortNames = array_keys($cohort);
sort($cohortNames, SORT_STRING);

$finalFingerprints = [];
foreach ($cohortNames as $name) {
  $finalFingerprints[$name] = $semanticObjectFingerprint(
    $effectiveEnFromSync($name),
  );
}

$mismatchObjects = [];
foreach ($cohortNames as $name) {
  if ($name === 'config_language_lock.settings') {
    continue;
  }
  $sourceFingerprint = $source['object_fingerprints'][$name]
    ?? $fingerprint(['__missing_source_object__' => $name]);
  if ($sourceFingerprint !== $finalFingerprints[$name]) {
    $mismatchObjects[] = $name;
  }
}
sort($mismatchObjects, SORT_STRING);

$firstDivergence = static function (
  string $sourceHash,
  string $preHash,
  string $postHash,
  string $finalHash,
): string {
  if ($sourceHash !== $preHash) {
    return 'SITE_INSTALL_MODULE_NORMALIZATION_BEFORE_MANAGER';
  }
  if ($preHash !== $postHash) {
    return 'CONFIG_LANGUAGE_LOCK_MANAGER';
  }
  if ($postHash !== $finalHash) {
    return 'FINAL_MATERIALIZATION';
  }
  return 'NONE';
};

$targets = [];
foreach ($targetDefinitions as $key => $definition) {
  $sourceTarget = $source['targets'][$key] ?? NULL;
  $preTarget = $pre['targets'][$key] ?? NULL;
  $postTarget = $post['targets'][$key] ?? NULL;
  if (!is_array($sourceTarget) || !is_array($preTarget) || !is_array($postTarget)) {
    throw new RuntimeException('Missing target provenance stage: ' . $key);
  }

  $finalTarget = [
    'config' => $definition['config'],
    'path' => $definition['path'],
    ...$pathEvidence(
      $effectiveEnFromSync($definition['config']),
      $definition['path'],
    ),
  ];

  $sourceHash = $sourceTarget['sha256'];
  $preHash = $preTarget['sha256'];
  $postHash = $postTarget['sha256'];
  $finalHash = $finalTarget['sha256'];

  $targets[$key] = [
    'config' => $definition['config'],
    'path' => $definition['path'],
    'repository_source_sha256' => $sourceHash,
    'pre_manager_effective_en_sha256' => $preHash,
    'post_manager_effective_en_sha256' => $postHash,
    'final_candidate_effective_en_sha256' => $finalHash,
    'source_equals_pre_manager' => $sourceHash === $preHash,
    'pre_manager_equals_post_manager' => $preHash === $postHash,
    'post_manager_equals_final' => $postHash === $finalHash,
    'source_equals_final' => $sourceHash === $finalHash,
    'first_divergence_stage' => $firstDivergence(
      $sourceHash,
      $preHash,
      $postHash,
      $finalHash,
    ),
    'schema_type' => $postTarget['schema_type'] ?? 'UNKNOWN',
    'schema_definition_present' => $postTarget['schema_definition_present'] ?? 'NO',
    'schema_translatability' => $postTarget['schema_translatability'] ?? 'UNKNOWN',
  ];
}

$materialKeys = [
  'language_entity_fr_label',
  'llms_txt_content',
  'taxonomy_tags_description',
];
$materialStages = [];
foreach ($materialKeys as $key) {
  $materialStages[$targets[$key]['first_divergence_stage']] = TRUE;
}
if (count($materialStages) === 1) {
  $overallStage = array_key_first($materialStages);
}
else {
  $stages = array_keys($materialStages);
  sort($stages, SORT_STRING);
  $overallStage = 'MIXED:' . implode(',', $stages);
}

echo json_encode([
  'schema_version' => 1,
  'stage' => $stage,
  'cohort_object_count' => count($cohortNames),
  'historical_en_mismatch_object_count' => count($mismatchObjects),
  'historical_en_mismatch_objects' => $mismatchObjects,
  'targets' => $targets,
  'first_divergence_stage' => $overallStage,
  'raw_config_values_exposed' => FALSE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

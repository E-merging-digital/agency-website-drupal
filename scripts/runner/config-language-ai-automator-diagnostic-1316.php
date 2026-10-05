<?php

declare(strict_types=1);

use Drupal\Core\Config\StorageInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Symfony\Component\Yaml\Yaml;

$targets = [
  'ai_automators.ai_automator.node.article.field_feature_image.default',
  'ai_automators.ai_automator.node.article.field_short_description.default',
];

$storage = \Drupal::service('config.storage');
$configFactory = \Drupal::service('config.factory');
$overrideFactory = \Drupal::service('language.config_factory_override');
$typedManager = \Drupal::service('config.typed');
$moduleList = \Drupal::service('extension.list.module');

if (!$storage instanceof StorageInterface) {
  throw new RuntimeException('Active configuration storage is unavailable.');
}

$normalize = NULL;
$normalize = static function (mixed $value) use (&$normalize): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (array_is_list($value)) {
    return array_map($normalize, $value);
  }
  ksort($value, SORT_STRING);
  foreach ($value as $key => $child) {
    $value[$key] = $normalize($child);
  }
  return $value;
};

$flatten = NULL;
$flatten = static function (
  mixed $value,
  string $prefix = '',
) use (&$flatten, $normalize): array {
  if (!is_array($value) || $value === []) {
    if ($prefix === '') {
      throw new RuntimeException('A configuration layer cannot flatten to an empty root path.');
    }
    return [
      $prefix => hash(
        'sha256',
        json_encode(
          $normalize($value),
          JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR,
        ),
      ),
    ];
  }

  $result = [];
  $keys = array_keys($value);
  sort($keys, SORT_STRING);
  foreach ($keys as $key) {
    $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
    foreach ($flatten($value[$key], $path) as $leaf => $hash) {
      $result[$leaf] = $hash;
    }
  }
  ksort($result, SORT_STRING);
  return $result;
};

$snapshot = static function (?array $data) use ($flatten): array {
  if ($data === NULL) {
    return [
      'object_exists' => FALSE,
      'leaf_path_names' => [],
      'per_leaf_path_sha256' => [],
      'whole_layer_sha256' => NULL,
    ];
  }

  $fingerprints = $flatten($data);
  return [
    'object_exists' => TRUE,
    'leaf_path_names' => array_keys($fingerprints),
    'per_leaf_path_sha256' => $fingerprints,
    'whole_layer_sha256' => hash(
      'sha256',
      json_encode(
        $fingerprints,
        JSON_UNESCAPED_SLASHES
          | JSON_UNESCAPED_UNICODE
          | JSON_THROW_ON_ERROR,
      ),
    ),
  ];
};

$effective = static function (
  string $target,
  string $langcode,
) use ($overrideFactory, $configFactory): array {
  $language = ConfigurableLanguage::load($langcode);
  if (!$language instanceof ConfigurableLanguage) {
    throw new RuntimeException('Missing configurable language ' . $langcode . '.');
  }
  $overrideFactory->setLanguage($language);
  $configFactory->reset();
  $data = $configFactory->get($target)->get();
  if (!is_array($data)) {
    throw new RuntimeException('Effective config is not an array for ' . $target . '.');
  }
  return $data;
};

$schemaFiles = [];
$aiPath = $moduleList->getPath('ai');
$aiAbsolute = DRUPAL_ROOT . '/' . $aiPath;
if (!is_dir($aiAbsolute)) {
  throw new RuntimeException('Installed AI module path is unavailable.');
}

$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator(
    $aiAbsolute,
    FilesystemIterator::SKIP_DOTS,
  ),
);
foreach ($iterator as $file) {
  if (!$file instanceof SplFileInfo || !$file->isFile()) {
    continue;
  }
  $path = $file->getPathname();
  if (!str_ends_with($path, '.schema.yml')) {
    continue;
  }
  $parsed = Yaml::parseFile($path);
  if (!is_array($parsed)) {
    continue;
  }
  foreach (array_keys($parsed) as $schemaKey) {
    if (is_string($schemaKey) && str_starts_with($schemaKey, 'ai_automators.ai_automator')) {
      $schemaFiles[$path] = TRUE;
      break;
    }
  }
}

$schemaPaths = array_keys($schemaFiles);
sort($schemaPaths, SORT_STRING);
if (count($schemaPaths) !== 1) {
  throw new RuntimeException(
    'Expected exactly one installed AI Automator schema source, found '
    . count($schemaPaths)
    . '.',
  );
}
$schemaAbsolute = $schemaPaths[0];
$schemaRelative = str_starts_with($schemaAbsolute, DRUPAL_ROOT . '/')
  ? substr($schemaAbsolute, strlen(DRUPAL_ROOT) + 1)
  : $schemaAbsolute;

$aiInfo = $moduleList->getExtensionInfo('ai');
$aiVersion = $aiInfo['version'] ?? NULL;
if (!is_string($aiVersion) || $aiVersion === '') {
  throw new RuntimeException('Installed AI module version is unavailable.');
}

$classifyPath = static function (
  string $target,
  string $path,
) use ($typedManager): array {
  try {
    $current = $typedManager->get($target);
  }
  catch (Throwable) {
    return [
      'schema_path' => $path,
      'schema_type' => 'UNKNOWN',
      'schema_definition_present' => 'NO',
      'schema_translatability' => 'UNKNOWN',
    ];
  }

  foreach (explode('.', $path) as $segment) {
    if (!is_object($current) || !method_exists($current, 'get')) {
      return [
        'schema_path' => $path,
        'schema_type' => 'UNKNOWN',
        'schema_definition_present' => 'NO',
        'schema_translatability' => 'UNKNOWN',
      ];
    }
    try {
      $current = $current->get($segment);
    }
    catch (Throwable) {
      return [
        'schema_path' => $path,
        'schema_type' => 'UNKNOWN',
        'schema_definition_present' => 'NO',
        'schema_translatability' => 'UNKNOWN',
      ];
    }
    if ($current === NULL) {
      return [
        'schema_path' => $path,
        'schema_type' => 'UNKNOWN',
        'schema_definition_present' => 'NO',
        'schema_translatability' => 'UNKNOWN',
      ];
    }
  }

  if (!is_object($current) || !method_exists($current, 'getDataDefinition')) {
    return [
      'schema_path' => $path,
      'schema_type' => 'UNKNOWN',
      'schema_definition_present' => 'NO',
      'schema_translatability' => 'UNKNOWN',
    ];
  }

  $definition = $current->getDataDefinition();
  if (!is_object($definition) || !method_exists($definition, 'getDataType')) {
    return [
      'schema_path' => $path,
      'schema_type' => 'UNKNOWN',
      'schema_definition_present' => 'NO',
      'schema_translatability' => 'UNKNOWN',
    ];
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
      if (is_array($typeDefinition)
        && array_key_exists('translatable', $typeDefinition)
        && is_bool($typeDefinition['translatable'])) {
        $translatable = $typeDefinition['translatable'];
      }
    }
    catch (Throwable) {
      // UNKNOWN is intentional when typed-config metadata is inconclusive.
    }
  }

  return [
    'schema_path' => $path,
    'schema_type' => is_string($type) && $type !== '' ? $type : 'UNKNOWN',
    'schema_definition_present' => 'YES',
    'schema_translatability' => $translatable === TRUE
      ? 'YES'
      : ($translatable === FALSE ? 'NO' : 'UNKNOWN'),
  ];
};

$frStorage = $storage->createCollection('language.fr');
$enStorage = $storage->createCollection('language.en');

$resultTargets = [];
foreach ($targets as $target) {
  $base = $storage->read($target);
  $frOverride = $frStorage->read($target);
  $enOverride = $enStorage->read($target);
  $effectiveFr = $effective($target, 'fr');
  $effectiveEn = $effective($target, 'en');

  $layers = [
    'base' => $snapshot(is_array($base) ? $base : NULL),
    'fr_override' => $snapshot(is_array($frOverride) ? $frOverride : NULL),
    'en_override' => $snapshot(is_array($enOverride) ? $enOverride : NULL),
    'effective_fr' => $snapshot($effectiveFr),
    'effective_en' => $snapshot($effectiveEn),
  ];

  $allPaths = [];
  foreach ($layers as $layer) {
    foreach ($layer['leaf_path_names'] as $path) {
      $allPaths[$path] = TRUE;
    }
  }
  $pathNames = array_keys($allPaths);
  sort($pathNames, SORT_STRING);

  $schemaByPath = [];
  foreach ($pathNames as $path) {
    $schemaByPath[$path] = $classifyPath($target, $path);
  }

  $resultTargets[$target] = [
    'layers' => $layers,
    'schema_by_path' => $schemaByPath,
  ];
}

echo json_encode([
  'schema_version' => 1,
  'ai_version' => $aiVersion,
  'ai_automator_schema_source_path' => $schemaRelative,
  'ai_automator_schema_source_sha256' => hash_file('sha256', $schemaAbsolute),
  'targets' => $resultTargets,
  'raw_config_values_exposed' => FALSE,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

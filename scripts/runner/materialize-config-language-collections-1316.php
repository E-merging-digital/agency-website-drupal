<?php

declare(strict_types=1);

use Drupal\Core\Config\StorageInterface;

$mode = getenv('AGENCY_CONFIG_LANGUAGE_COLLECTION_MODE');
if (!in_array($mode, ['MATERIALIZE', 'VERIFY'], TRUE)) {
  throw new RuntimeException('Unsupported collection materialization mode.');
}

$diagnosticOnly =
  getenv('AGENCY_CONFIG_LANGUAGE_COLLECTION_DIAGNOSTIC_ONLY') === '1';
if ($diagnosticOnly && $mode !== 'VERIFY') {
  throw new RuntimeException(
    'Collection diagnostic-only mode is supported only with VERIFY.',
  );
}

$active = \Drupal::service('config.storage');
$sync = \Drupal::service('config.storage.sync');
if (!$active instanceof StorageInterface || !$sync instanceof StorageInterface) {
  throw new RuntimeException('Configuration storage services are unavailable.');
}

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

$fingerprint = static function (mixed $value) use ($normalize): string {
  return hash('sha256', json_encode(
    $normalize($value),
    JSON_UNESCAPED_SLASHES
      | JSON_UNESCAPED_UNICODE
      | JSON_THROW_ON_ERROR,
  ));
};

$storedFingerprint = static function (
  StorageInterface $storage,
  string $name,
  string $side,
) use ($fingerprint): string {
  $data = $storage->read($name);
  if (!is_array($data)) {
    return hash('sha256', "UNREADABLE:$side:$name");
  }
  return $fingerprint($data);
};

$classify = static function (
  array $activeOnly,
  array $syncOnly,
  array $valueMismatches,
): string {
  $dimensions = (int) ($activeOnly !== [])
    + (int) ($syncOnly !== [])
    + (int) ($valueMismatches !== []);

  if ($dimensions === 0) {
    return 'MATCH';
  }
  if ($dimensions > 1) {
    return 'MIXED';
  }
  if ($activeOnly !== []) {
    return 'ACTIVE_ONLY';
  }
  if ($syncOnly !== []) {
    return 'SYNC_ONLY';
  }
  return 'VALUE_MISMATCH';
};

$result = [
  'schema_version' => 1,
  'mode' => $mode,
  'diagnostic_only' => $diagnosticOnly,
  'status' => 'PASS',
  'raw_config_values_exposed' => FALSE,
  'prod_access' => 'NONE',
  'preprod_access' => 'NONE',
  'collections' => [],
];

foreach (['language.fr', 'language.en'] as $collection) {
  $activeCollection = $active->createCollection($collection);
  $syncCollection = $sync->createCollection($collection);

  $activeNames = $activeCollection->listAll();
  $syncBefore = $syncCollection->listAll();
  sort($activeNames, SORT_STRING);
  sort($syncBefore, SORT_STRING);

  $written = 0;
  $deleted = 0;
  if ($mode === 'MATERIALIZE') {
    foreach (array_diff($syncBefore, $activeNames) as $name) {
      if (!$syncCollection->delete($name)) {
        throw new RuntimeException(
          "Unable to delete stale $collection override $name.",
        );
      }
      $deleted++;
    }

    foreach ($activeNames as $name) {
      $data = $activeCollection->read($name);
      if (!is_array($data)) {
        throw new RuntimeException(
          "Unable to read active $collection override $name.",
        );
      }
      if (!$syncCollection->write($name, $data)) {
        throw new RuntimeException(
          "Unable to write sync $collection override $name.",
        );
      }
      $written++;
    }
  }

  $syncAfter = $syncCollection->listAll();
  sort($syncAfter, SORT_STRING);

  $activeOnly = array_values(array_diff($activeNames, $syncAfter));
  $syncOnly = array_values(array_diff($syncAfter, $activeNames));
  $commonNames = array_values(array_intersect($activeNames, $syncAfter));
  sort($activeOnly, SORT_STRING);
  sort($syncOnly, SORT_STRING);
  sort($commonNames, SORT_STRING);

  $activeFingerprints = [];
  foreach ($activeNames as $name) {
    $activeFingerprints[$name] = $storedFingerprint(
      $activeCollection,
      $name,
      'active',
    );
  }

  $syncFingerprints = [];
  foreach ($syncAfter as $name) {
    $syncFingerprints[$name] = $storedFingerprint(
      $syncCollection,
      $name,
      'sync',
    );
  }

  $valueMismatchNames = [];
  $valueMismatchFingerprints = [];
  foreach ($commonNames as $name) {
    if ($activeFingerprints[$name] === $syncFingerprints[$name]) {
      continue;
    }

    $valueMismatchNames[] = $name;
    $valueMismatchFingerprints[] = [
      'name' => $name,
      'active_sha256' => $activeFingerprints[$name],
      'sync_sha256' => $syncFingerprints[$name],
    ];
  }

  $match =
    $activeOnly === []
    && $syncOnly === []
    && $valueMismatchNames === [];

  if (!$match) {
    $result['status'] = 'FAIL';
  }

  $result['collections'][$collection] = [
    'active_count' => count($activeNames),
    'sync_count' => count($syncAfter),
    'sync_before_count' => count($syncBefore),
    'sync_after_count' => count($syncAfter),
    'written' => $written,
    'deleted' => $deleted,
    'active_only_count' => count($activeOnly),
    'active_only_names' => $activeOnly,
    'sync_only_count' => count($syncOnly),
    'sync_only_names' => $syncOnly,
    'value_mismatch_count' => count($valueMismatchNames),
    'value_mismatch_names' => $valueMismatchNames,
    'value_mismatch_fingerprints' => $valueMismatchFingerprints,
    'active_names_sha256' => hash(
      'sha256',
      implode("\n", $activeNames),
    ),
    'sync_names_sha256' => hash(
      'sha256',
      implode("\n", $syncAfter),
    ),
    'active_values_sha256' => hash(
      'sha256',
      json_encode($activeFingerprints, JSON_THROW_ON_ERROR),
    ),
    'sync_values_sha256' => hash(
      'sha256',
      json_encode($syncFingerprints, JSON_THROW_ON_ERROR),
    ),
    'match' => $match,
    'classification' => $classify(
      $activeOnly,
      $syncOnly,
      $valueMismatchNames,
    ),
  ];
}

if ($result['status'] !== 'PASS' && !$diagnosticOnly) {
  throw new RuntimeException(
    'Active/sync language override collections differ.',
  );
}

echo json_encode(
  $result,
  JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_THROW_ON_ERROR,
) . PHP_EOL;

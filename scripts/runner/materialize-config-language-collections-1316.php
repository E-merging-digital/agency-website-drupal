<?php

declare(strict_types=1);

use Drupal\Core\Config\StorageInterface;

$mode = getenv('AGENCY_CONFIG_LANGUAGE_COLLECTION_MODE');
if (!in_array($mode, ['MATERIALIZE', 'VERIFY'], TRUE)) {
  throw new RuntimeException('Unsupported collection materialization mode.');
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
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  ));
};

$result = [
  'schema_version' => 1,
  'mode' => $mode,
  'status' => 'PASS',
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
        throw new RuntimeException("Unable to delete stale $collection override $name.");
      }
      $deleted++;
    }

    foreach ($activeNames as $name) {
      $data = $activeCollection->read($name);
      if (!is_array($data)) {
        throw new RuntimeException("Unable to read active $collection override $name.");
      }
      if (!$syncCollection->write($name, $data)) {
        throw new RuntimeException("Unable to write sync $collection override $name.");
      }
      $written++;
    }
  }

  $syncAfter = $syncCollection->listAll();
  sort($syncAfter, SORT_STRING);
  $match = $activeNames === $syncAfter;

  $activeFingerprints = [];
  $syncFingerprints = [];
  foreach ($activeNames as $name) {
    $activeData = $activeCollection->read($name);
    $syncData = $syncCollection->read($name);
    if (!is_array($activeData) || !is_array($syncData)) {
      $match = FALSE;
      continue;
    }
    $activeFingerprints[$name] = $fingerprint($activeData);
    $syncFingerprints[$name] = $fingerprint($syncData);
    if ($activeFingerprints[$name] !== $syncFingerprints[$name]) {
      $match = FALSE;
    }
  }

  if (!$match) {
    $result['status'] = 'FAIL';
  }

  $result['collections'][$collection] = [
    'active_count' => count($activeNames),
    'sync_before_count' => count($syncBefore),
    'sync_after_count' => count($syncAfter),
    'written' => $written,
    'deleted' => $deleted,
    'active_names_sha256' => hash('sha256', implode("\n", $activeNames)),
    'sync_names_sha256' => hash('sha256', implode("\n", $syncAfter)),
    'active_values_sha256' => hash('sha256', json_encode($activeFingerprints, JSON_THROW_ON_ERROR)),
    'sync_values_sha256' => hash('sha256', json_encode($syncFingerprints, JSON_THROW_ON_ERROR)),
    'match' => $match,
  ];
}

if ($result['status'] !== 'PASS') {
  throw new RuntimeException('Active/sync language override collections differ.');
}

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

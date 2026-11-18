<?php

/**
 * @file
 * #1417: private, read-only NL source export and PROD identity preflight.
 *
 * This script NEVER imports, creates, translates, saves, or publishes entities.
 * Run via Drush php:script as the owner on the appropriate existing host.
 * The source manifest stays on private server disks; never commit or upload it.
 */

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;

$mode = getenv('AGENCY1417_MODE') ?: '';
$expected = [
  'export' => ['agency-preprod-01', 'agency-preprod', '/var/www/agency-preprod/releases/', '/home/agency-preprod/'],
  'plan' => ['emergingdigital', 'deploy', '/var/www/agency/releases/', '/home/deploy/'],
];

function agency1417_stop(string $why): never {
  throw new \RuntimeException('CONTROLLED_STOP=' . $why);
}

if (!isset($expected[$mode])) {
  agency1417_stop('INVALID_MODE_EXPORT_OR_PLAN_ONLY');
}

[$host, $user, $root, $privateHome] = $expected[$mode];
$account = posix_getpwuid(posix_geteuid());
$cwd = realpath(getcwd());
if (gethostname() !== $host || ($account['name'] ?? '') !== $user ||
    $cwd === FALSE || !str_starts_with($cwd, $root)) {
  agency1417_stop('WRONG_HOST_USER_OR_RELEASE');
}

$file = getenv('AGENCY1417_PRIVATE_FILE') ?: '';
if (!str_starts_with($file, $privateHome) ||
    dirname($file) !== rtrim($privateHome, '/') ||
    str_contains($file, '..') || is_link($file) || !preg_match('~^[a-zA-Z0-9/._-]+$~', $file)) {
  agency1417_stop('INVALID_PRIVATE_PATH');
}

// The first 38 entities have the same UUID; the nine later entities must
// be matched by type + FR title + FR alias AND complete FR/EN field snapshot.
// PREPROD 43 (new MCP service) and 46 (alternate AI article) are excluded.
// Neither may be silently added to, or used to overwrite, PROD.
$map = array_combine(range(1, 38), range(1, 38));
$map += [39 => 39, 40 => 41, 41 => 42, 42 => 45, 44 => 43,
  45 => 44, 47 => 46, 48 => 47, 49 => 48];
$expectedCount = 47;

function agency1417_fields(ContentEntityInterface $entity, string $langcode, bool $source, array $map, int $depth = 0): array {
  if ($depth > 3 || !$entity->hasTranslation($langcode)) {
    agency1417_stop('MISSING_TRANSLATION_OR_NESTED_REFERENCE');
  }
  $translation = $entity->getTranslation($langcode);
  $out = [];
  foreach ($translation->getFields() as $name => $field) {
    if (!$field->getFieldDefinition()->isTranslatable() ||
        in_array($name, ['langcode', 'default_langcode', 'status', 'path',
          'changed', 'created', 'uid', 'revision_log', 'revision_timestamp',
          'revision_uid', 'revision_translation_affected', 'content_translation_source',
          'content_translation_outdated', 'content_translation_uid',
          'content_translation_created', 'parent_id', 'parent_type',
          'parent_field_name', 'behavior_settings'], TRUE)) {
      continue;
    }
    $type = $field->getFieldDefinition()->getType();
    if ($type === 'entity_reference' || $type === 'entity_reference_revisions') {
      $refs = [];
      $entities = $field->referencedEntities();
      if (count($entities) !== $field->count()) {
        agency1417_stop('UNRESOLVED_REFERENCE_' . $entity->getEntityTypeId() . '_' . $name);
      }
      foreach ($entities as $ref) {
        if (!$ref instanceof ContentEntityInterface) {
          agency1417_stop('NON_CONTENT_REFERENCE_' . $name);
        }
        $typeId = $ref->getEntityTypeId();
        $refKey = $ref->uuid();
        if ($typeId === 'node') {
          $id = (int) $ref->id();
          $refKey = $source ? (string) ($map[$id] ?? '') : (string) $id;
          if ($refKey === '') {
            agency1417_stop('UNMAPPED_NODE_REFERENCE_' . $id);
          }
        }
        $entry = ['type' => $typeId, 'bundle' => $ref->bundle(), 'identity' => $refKey];
        if ($typeId === 'paragraph') {
          $entry['fields'] = agency1417_fields($ref, $langcode, $source, $map, $depth + 1);
        }
        $refs[] = $entry;
      }
      $out[$name] = ['reference' => $refs];
    }
    else {
      $out[$name] = ['value' => $field->getValue()];
    }
  }
  ksort($out);
  return $out;
}

function agency1417_digest(ContentEntityInterface $entity, string $langcode, bool $source, array $map): string {
  return hash('sha256', json_encode(
    agency1417_fields($entity, $langcode, $source, $map),
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
  ));
}

function agency1417_alias(int $nid, string $langcode): string {
  $alias = \Drupal::service('path_alias.repository')
    ->lookupBySystemPath('/node/' . $nid, $langcode);
  return $alias['alias'] ?? '';
}

$storage = \Drupal::entityTypeManager()->getStorage('node');

if ($mode === 'export') {
  if (file_exists($file)) {
    agency1417_stop('PRIVATE_FILE_ALREADY_EXISTS');
  }
  $items = [];
  foreach ($map as $sourceNid => $targetNid) {
    $node = $storage->load($sourceNid);
    if (!$node instanceof ContentEntityInterface ||
        !$node->hasTranslation('nl') ||
        !$node->getTranslation('nl')->isPublished()) {
      agency1417_stop('MISSING_PUBLISHED_NL_' . $sourceNid);
    }
    $items[] = [
      'source_nid' => $sourceNid,
      'target_nid' => $targetNid,
      'bundle' => $node->bundle(),
      'uuid' => $node->uuid(),
      'title_fr' => $node->getTranslation('fr')->label(),
      'alias_fr' => agency1417_alias($sourceNid, 'fr'),
      'alias_nl' => agency1417_alias($sourceNid, 'nl'),
      'fingerprint_fr' => agency1417_digest($node, 'fr', TRUE, $map),
      'fingerprint_en' => agency1417_digest($node, 'en', TRUE, $map),
      'nl_fields' => agency1417_fields($node, 'nl', TRUE, $map),
    ];
  }
  if (count($items) !== $expectedCount) {
    agency1417_stop('SOURCE_COUNT_MISMATCH');
  }
  $payload = json_encode([
    'contract' => 'agency-1417-private-nl-preflight-v1',
    'source_count' => $expectedCount,
    'exceptions' => [43, 46],
    'items' => $items,
  ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  umask(0077);
  $handle = fopen($file, 'x');
  if ($handle === FALSE) {
    agency1417_stop('PRIVATE_FILE_CREATE_FAILED');
  }
  try {
    if (fwrite($handle, $payload . "\n") !== strlen($payload) + 1) {
      agency1417_stop('PRIVATE_FILE_WRITE_FAILED');
    }
  }
  finally {
    fclose($handle);
  }
  chmod($file, 0600);
  echo 'EXPORT=PASS NODES=' . $expectedCount . ' EXCEPTIONS=2' . PHP_EOL;
  echo 'SHA256=' . hash_file('sha256', $file) . ' WRITE=PRIVATE_FILE_ONLY' . PHP_EOL;
  return;
}

// PROD plan: compare immutable entity identity and every translatable FR/EN
// field, including normalized references and Paragraph translation structures.
$sha256 = getenv('AGENCY1417_EXPECTED_SHA256') ?: '';
if (!preg_match('/^[a-f0-9]{64}$/', $sha256) ||
    !is_file($file) || is_link($file) ||
    hash_file('sha256', $file) !== $sha256) {
  agency1417_stop('MANIFEST_MISSING_OR_HASH_MISMATCH');
}
$data = json_decode(file_get_contents($file), TRUE, 512, JSON_THROW_ON_ERROR);
if (($data['contract'] ?? '') !== 'agency-1417-private-nl-preflight-v1' ||
    ($data['source_count'] ?? NULL) !== $expectedCount ||
    ($data['exceptions'] ?? NULL) !== [43, 46] ||
    count($data['items'] ?? []) !== $expectedCount) {
  agency1417_stop('MANIFEST_CONTRACT_MISMATCH');
}
$seen = [];
$errors = [];
foreach ($data['items'] as $item) {
  $sid = $item['source_nid'] ?? 0;
  $tid = $item['target_nid'] ?? 0;
  if (!isset($map[$sid]) || $map[$sid] !== $tid || isset($seen[$tid])) {
    agency1417_stop('MAPPING_DRIFT_OR_DUPLICATE');
  }
  $seen[$tid] = TRUE;
  $node = $storage->load($tid);
  if (!$node instanceof ContentEntityInterface || $node->bundle() !== $item['bundle']) {
    $errors[] = "TARGET_BUNDLE_$sid";
    continue;
  }
  if ($sid <= 38 && $node->uuid() !== $item['uuid']) {
    $errors[] = "UUID_DRIFT_$sid";
  }
  if ($node->hasTranslation('nl')) {
    $errors[] = "EXISTING_NL_$sid";
  }
  if ($node->getTranslation('fr')->label() !== $item['title_fr'] ||
      agency1417_alias($tid, 'fr') !== $item['alias_fr']) {
    $errors[] = "FR_TITLE_ALIAS_DRIFT_$sid";
  }
  foreach (['fr', 'en'] as $lang) {
    try {
      if (agency1417_digest($node, $lang, FALSE, $map) !== $item['fingerprint_' . $lang]) {
        $errors[] = strtoupper($lang) . "_FIELD_DRIFT_$sid";
      }
    }
    catch (\Throwable $e) {
      $errors[] = strtoupper($lang) . "_REFERENCE_ERROR_$sid";
    }
  }
}
if (count($seen) !== $expectedCount) {
  agency1417_stop('TARGET_COUNT_MISMATCH');
}
if ($errors) {
  echo 'PLAN=CONTROLLED_STOP ERRORS=' . implode(',', $errors) . ' NO_WRITE=YES' . PHP_EOL;
  return;
}
echo 'PLAN=PASS MATCHED=' . $expectedCount . ' EXCEPTIONS=2' . PHP_EOL;
echo 'CONTENT_IMPORT_NOT_IMPLEMENTED=YES PROD_WRITE=NONE' . PHP_EOL;

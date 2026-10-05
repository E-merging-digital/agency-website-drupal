<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(__DIR__, 2);
require $projectRoot . '/vendor/autoload.php';

$snapshotPath = $projectRoot . '/artifacts/configuration-language-audit/snapshot.json';
$baselinePath = $projectRoot . '/docs/evidence/configuration-language-baseline-609.yml';
$configDirectory = $projectRoot . '/config/sync';

if (!is_file($snapshotPath) || !is_file($baselinePath) || !is_dir($configDirectory)) {
  throw new RuntimeException('Required configuration-language evidence is missing.');
}

$snapshotRaw = file_get_contents($snapshotPath);
if ($snapshotRaw === FALSE) {
  throw new RuntimeException('Unable to read configuration-language snapshot.');
}
$snapshot = json_decode($snapshotRaw, TRUE, 512, JSON_THROW_ON_ERROR);
$baseline = Yaml::parseFile($baselinePath);
if (!is_array($snapshot) || !is_array($baseline)) {
  throw new RuntimeException('Configuration-language evidence must be structured data.');
}

if (($snapshot['schema_version'] ?? NULL) !== 1) {
  throw new RuntimeException('Unexpected snapshot schema.');
}
if (($snapshot['policy']['policy_id'] ?? NULL) !== 'agency-configuration-language-v1') {
  throw new RuntimeException('Unexpected configuration-language policy.');
}
if (($snapshot['policy']['status'] ?? NULL) !== 'migration_required') {
  throw new RuntimeException('Migration dry-run requires migration_required policy state.');
}
if (($snapshot['policy']['canonical_configuration_language'] ?? NULL) !== 'en') {
  throw new RuntimeException('Migration dry-run expects canonical language en.');
}
if (($snapshot['policy']['enforce_consistency'] ?? TRUE) !== FALSE) {
  throw new RuntimeException('Migration dry-run must precede enforcement.');
}
if (($baseline['baseline_id'] ?? NULL) !== 'agency-config-language-609-pre-migration-v1') {
  throw new RuntimeException('Unexpected durable baseline.');
}

$expectedSnapshotSha = (string) ($baseline['source']['snapshot_sha256'] ?? '');
$currentSnapshotSha = hash('sha256', $snapshotRaw);
$expectedFrWithoutEn = (int) ($baseline['migration_analysis']['fr_base_without_en_override'] ?? -1);
$expectedFrWithEn = (int) ($baseline['migration_analysis']['fr_base_with_en_override'] ?? -1);
$baselineMatch = $expectedSnapshotSha !== '' && hash_equals($expectedSnapshotSha, $currentSnapshotSha);

$entries = $snapshot['repository']['entries'] ?? NULL;
$translations = $snapshot['translations'] ?? NULL;
if (!is_array($entries) || !is_array($translations)) {
  throw new RuntimeException('Snapshot repository entries or translations are missing.');
}

$enOverrides = [];
foreach ($translations as $translation) {
  if (!is_array($translation) || ($translation['language'] ?? NULL) !== 'en') {
    continue;
  }
  foreach (($translation['config_names'] ?? []) as $configName) {
    if (is_string($configName) && $configName !== '') {
      $enOverrides[$configName] = TRUE;
    }
  }
}

$technicalEntityTypes = [
  'entity_form_display' => TRUE,
  'entity_view_display' => TRUE,
  'field_storage_config' => TRUE,
  'language_content_settings' => TRUE,
];
$specialConfigNames = [
  'language.entity.und' => TRUE,
  'language.entity.zxx' => TRUE,
  'system.menu.footer' => TRUE,
];

$categories = [
  'translation_override_present' => [],
  'technical_candidate_without_en_override' => [],
  'editorial_or_semantic_review_required' => [],
  'locked_or_special_preserve' => [],
  'unknown' => [],
];
$frWithoutEn = [];
$byEntityTypeWithoutEn = [];

foreach ($entries as $entry) {
  if (!is_array($entry)) {
    $categories['unknown'][] = ['reason' => 'entry_not_mapping'];
    continue;
  }

  $name = $entry['name'] ?? NULL;
  $langcode = $entry['langcode'] ?? NULL;
  $kind = $entry['kind'] ?? NULL;
  $entityType = $entry['entity_type'] ?? NULL;
  if (!is_string($name) || $name === '' || !is_string($langcode) || !is_string($kind)) {
    $categories['unknown'][] = [
      'name' => is_string($name) ? $name : NULL,
      'reason' => 'invalid_entry_shape',
    ];
    continue;
  }

  $summary = [
    'name' => $name,
    'langcode' => $langcode,
    'kind' => $kind,
    'entity_type' => is_string($entityType) ? $entityType : NULL,
  ];

  if (isset($specialConfigNames[$name]) || in_array($langcode, ['und', 'zxx'], TRUE)) {
    $categories['locked_or_special_preserve'][] = $summary;
    continue;
  }
  if ($langcode !== 'fr') {
    continue;
  }
  if (isset($enOverrides[$name])) {
    $categories['translation_override_present'][] = $summary;
    continue;
  }

  $frWithoutEn[] = $summary;
  $entityTypeKey = is_string($entityType) && $entityType !== '' ? $entityType : 'simple_config';
  $byEntityTypeWithoutEn[$entityTypeKey] = ($byEntityTypeWithoutEn[$entityTypeKey] ?? 0) + 1;

  if (is_string($entityType) && isset($technicalEntityTypes[$entityType])) {
    $categories['technical_candidate_without_en_override'][] = $summary;
    continue;
  }

  // Conservative default: configurations carrying labels, editorial semantics,
  // or unknown user-facing values require explicit review rather than being
  // silently promoted to an English source language.
  $categories['editorial_or_semantic_review_required'][] = $summary;
}

foreach ($categories as &$categoryEntries) {
  usort(
    $categoryEntries,
    static fn(array $left, array $right): int => ($left['name'] ?? '') <=> ($right['name'] ?? ''),
  );
}
unset($categoryEntries);
ksort($byEntityTypeWithoutEn);

$specialChecks = [];
foreach (['und', 'zxx'] as $lockedId) {
  $path = $configDirectory . '/language.entity.' . $lockedId . '.yml';
  $data = is_file($path) ? Yaml::parseFile($path) : NULL;
  $specialChecks['language.entity.' . $lockedId] = [
    'exists' => is_file($path),
    'id_preserved' => is_array($data) && ($data['id'] ?? NULL) === $lockedId,
  ];
}
$footerPath = $configDirectory . '/system.menu.footer.yml';
$footer = is_file($footerPath) ? Yaml::parseFile($footerPath) : NULL;
$specialChecks['system.menu.footer'] = [
  'exists' => is_file($footerPath),
  'langcode_preserved' => is_array($footer) && ($footer['langcode'] ?? NULL) === 'und',
];
$specialChecksPass = TRUE;
foreach ($specialChecks as $check) {
  if (($check['exists'] ?? FALSE) !== TRUE) {
    $specialChecksPass = FALSE;
  }
  foreach ($check as $key => $value) {
    if ($key !== 'exists' && $value !== TRUE) {
      $specialChecksPass = FALSE;
    }
  }
}

$frWithEnCount = count($categories['translation_override_present']);
$frWithoutEnCount = count($frWithoutEn);
$technicalCount = count($categories['technical_candidate_without_en_override']);
$reviewCount = count($categories['editorial_or_semantic_review_required']);
$unknownCount = count($categories['unknown']);
$classifiedWithoutEn = $technicalCount + $reviewCount;

$focus = [
  'canvas' => array_values(array_filter(
    $frWithoutEn,
    static fn(array $entry): bool => str_starts_with((string) $entry['name'], 'canvas.'),
  )),
  'language_content_settings' => array_values(array_filter(
    $frWithoutEn,
    static fn(array $entry): bool => ($entry['entity_type'] ?? NULL) === 'language_content_settings',
  )),
  'fields_and_displays' => array_values(array_filter(
    $frWithoutEn,
    static fn(array $entry): bool => preg_match('/^(core\.(?:base_field_override|entity_form_display|entity_view_display)|field\.(?:field|storage))\./', (string) $entry['name']) === 1,
  )),
  'simple_config' => array_values(array_filter(
    $frWithoutEn,
    static fn(array $entry): bool => ($entry['kind'] ?? NULL) === 'simple_config',
  )),
];

$status = 'PASS';
$verdict = 'DRY_RUN_READY_FOR_TRANSFORMATION_TESTS';
if (!$baselineMatch || $frWithoutEnCount !== $expectedFrWithoutEn || $frWithEnCount !== $expectedFrWithEn) {
  $status = 'FAIL';
  $verdict = 'BASELINE_DRIFT';
}
elseif (!$specialChecksPass) {
  $status = 'FAIL';
  $verdict = 'SPECIAL_LANGUAGE_INVARIANT_BROKEN';
}
elseif ($unknownCount > 0 || $classifiedWithoutEn !== $frWithoutEnCount) {
  $status = 'FAIL';
  $verdict = 'UNKNOWN_CLASSIFICATION';
}
elseif ($reviewCount > 0) {
  $verdict = 'REVIEW_REQUIRED';
}

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'policy' => [
    'policy_id' => $snapshot['policy']['policy_id'],
    'status' => $snapshot['policy']['status'],
    'canonical_configuration_language' => $snapshot['policy']['canonical_configuration_language'],
    'enforce_consistency' => $snapshot['policy']['enforce_consistency'],
  ],
  'baseline' => [
    'baseline_id' => $baseline['baseline_id'],
    'expected_snapshot_sha256' => $expectedSnapshotSha,
    'current_snapshot_sha256' => $currentSnapshotSha,
    'match' => $baselineMatch,
  ],
  'counts' => [
    'fr_base_total' => $frWithEnCount + $frWithoutEnCount,
    'fr_base_with_en_override' => $frWithEnCount,
    'fr_base_without_en_override' => $frWithoutEnCount,
    'classified_fr_without_en_override' => $classifiedWithoutEn,
    'technical_candidate_without_en_override' => $technicalCount,
    'editorial_or_semantic_review_required' => $reviewCount,
    'unknown' => $unknownCount,
  ],
  'fr_without_en_override_by_entity_type' => $byEntityTypeWithoutEn,
  'categories' => array_map(
    static fn(array $items): array => [
      'count' => count($items),
      'items' => $items,
    ],
    $categories,
  ),
  'focus' => array_map(
    static fn(array $items): array => [
      'count' => count($items),
      'items' => $items,
    ],
    $focus,
  ),
  'special_invariants' => [
    'pass' => $specialChecksPass,
    'checks' => $specialChecks,
  ],
  'constraints' => [
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

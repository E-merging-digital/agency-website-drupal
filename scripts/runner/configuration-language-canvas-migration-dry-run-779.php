<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot
  . '/docs/evidence/configuration-language-canvas-runtime-source-api-cohort-766.yml';

$runProbe = static function (string $path): array {
  if (!is_file($path)) {
    throw new RuntimeException(sprintf('Required provenance probe missing: %s', $path));
  }

  return (static function (string $isolatedPath): array {
    ob_start();
    try {
      require $isolatedPath;
      $raw = (string) ob_get_clean();
    }
    catch (Throwable $throwable) {
      ob_end_clean();
      throw $throwable;
    }

    $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
      throw new RuntimeException('Provenance probe did not return a mapping.');
    }
    return $decoded;
  })($path);
};

$canonicalize = NULL;
$canonicalize = static function (mixed $value) use (&$canonicalize): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (array_is_list($value)) {
    return array_map($canonicalize, $value);
  }
  ksort($value, SORT_STRING);
  foreach ($value as $key => $child) {
    $value[$key] = $canonicalize($child);
  }
  return $value;
};

$setPath = static function (array &$target, array $segments, mixed $value): void {
  $cursor = &$target;
  foreach ($segments as $index => $segment) {
    if ($index === count($segments) - 1) {
      $cursor[$segment] = $value;
      return;
    }
    if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
      $cursor[$segment] = [];
    }
    $cursor = &$cursor[$segment];
  }
};

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #779 repository baseline is unavailable.');
}

$manifest = Yaml::parseFile($manifestPath);
if (
  !is_array($manifest)
  || ($manifest['issue'] ?? NULL) !== 766
  || ($manifest['parent_issue'] ?? NULL) !== 609
  || ($manifest['cohort']['total'] ?? NULL) !== 30
  || ($manifest['cohort']['block'] ?? NULL) !== 26
  || ($manifest['cohort']['sdc'] ?? NULL) !== 4
) {
  throw new RuntimeException('Issue #779 requires the exact #766 Canvas cohort.');
}

$names = $manifest['cohort']['names'] ?? NULL;
if (!is_array($names) || count($names) !== 30) {
  throw new RuntimeException('Issue #766 Canvas cohort names are incomplete.');
}
$names = array_values(array_map('strval', $names));
sort($names, SORT_STRING);
$namesHash = hash('sha256', implode("\n", $names) . "\n");
if (
  $namesHash !== 'b2ad9dcff4b65e56e2a76efefc55b508cf4012b6aaa18cba0c4879cf2f3dec23'
  || $namesHash !== ($manifest['cohort']['names_sha256'] ?? NULL)
) {
  throw new RuntimeException('Issue #779 Canvas cohort hash mismatch.');
}

$sdcProof = $runProbe(
  $projectRoot . '/scripts/runner/configuration-language-sdc-native-metadata-provenance-772.php',
);
$blockProof = $runProbe(
  $projectRoot . '/scripts/runner/configuration-language-canvas-block-native-label-provenance-774.php',
);
$argumentProof = $runProbe(
  $projectRoot . '/scripts/runner/configuration-language-argumented-block-plain-text-provenance-776.php',
);

$proofProblems = [];
if (
  ($sdcProof['status'] ?? NULL) !== 'PASS'
  || ($sdcProof['verdict'] ?? NULL) !== 'SDC_NATIVE_METADATA_PROVENANCE_ANALYZED'
  || ($sdcProof['counts']['candidate_total'] ?? NULL) !== 4
  || ($sdcProof['counts']['material_translatable_leaves'] ?? NULL) !== 36
  || ($sdcProof['counts']['final_matched_leaves'] ?? NULL) !== 36
  || ($sdcProof['counts']['final_unmatched_leaves'] ?? NULL) !== 0
  || ($sdcProof['counts']['problem_count'] ?? NULL) !== 0
) {
  $proofProblems[] = ['reason' => 'sdc_provenance_contract_not_satisfied'];
}
if (
  ($blockProof['status'] ?? NULL) !== 'PASS'
  || ($blockProof['verdict'] ?? NULL) !== 'CANVAS_BLOCK_NATIVE_LABEL_PROVENANCE_ANALYZED'
  || ($blockProof['counts']['candidate_total'] ?? NULL) !== 26
  || ($blockProof['counts']['definition_resolved'] ?? NULL) !== 26
  || ($blockProof['counts']['current_no_exact_native_label_match'] ?? NULL) !== 0
  || ($blockProof['counts']['settings_differs_from_current'] ?? NULL) !== 0
  || ($blockProof['counts']['problem_count'] ?? NULL) !== 0
) {
  $proofProblems[] = ['reason' => 'block_provenance_contract_not_satisfied'];
}
if (
  ($argumentProof['status'] ?? NULL) !== 'PASS'
  || ($argumentProof['verdict'] ?? NULL) !== 'ARGUMENTED_BLOCK_PLAIN_TEXT_PROVENANCE_ANALYZED'
  || ($argumentProof['counts']['candidate_total'] ?? NULL) !== 3
  || ($argumentProof['counts']['deterministic_native_argument_provenance'] ?? NULL) !== 3
  || ($argumentProof['counts']['review_required'] ?? NULL) !== 0
  || ($argumentProof['counts']['problem_count'] ?? NULL) !== 0
) {
  $proofProblems[] = ['reason' => 'argumented_block_provenance_contract_not_satisfied'];
}

$blockByName = [];
foreach (($blockProof['items'] ?? []) as $item) {
  if (is_array($item) && is_string($item['config_name'] ?? NULL)) {
    $blockByName[$item['config_name']] = $item;
  }
}
$argumentByName = [];
foreach (($argumentProof['items'] ?? []) as $item) {
  if (is_array($item) && is_string($item['config_name'] ?? NULL)) {
    $argumentByName[$item['config_name']] = $item;
  }
}
$sdcByName = [];
foreach (($sdcProof['items'] ?? []) as $item) {
  if (is_array($item) && is_string($item['config_name'] ?? NULL)) {
    $sdcByName[$item['config_name']] = $item;
  }
}

$currentDistribution = [];
$totalConfig = 0;
foreach (glob($configDirectory . '/*.yml') ?: [] as $path) {
  $data = Yaml::parseFile($path);
  if (!is_array($data)) {
    continue;
  }
  $totalConfig++;
  $langcode = isset($data['langcode']) && is_string($data['langcode'])
    ? $data['langcode']
    : '__none__';
  $currentDistribution[$langcode] = ($currentDistribution[$langcode] ?? 0) + 1;
}
ksort($currentDistribution, SORT_STRING);
$expectedCurrentDistribution = [
  '__none__' => 59,
  'en' => 466,
  'fr' => 69,
  'und' => 1,
];
if ($totalConfig !== 595 || $currentDistribution !== $expectedCurrentDistribution) {
  $proofProblems[] = [
    'reason' => 'repository_distribution_drifted',
    'total' => $totalConfig,
    'actual' => $currentDistribution,
    'expected' => $expectedCurrentDistribution,
  ];
}

$basePlans = [];
$overridePlans = [];
$labelChangedFiles = 0;
$blockFiles = 0;
$sdcFiles = 0;
$existingOverrideFiles = 0;
$reviewRequired = [];

foreach ($names as $configName) {
  $basePath = $configDirectory . '/' . $configName . '.yml';
  if (!is_file($basePath)) {
    $reviewRequired[] = [
      'config_name' => $configName,
      'reason' => 'base_config_missing',
    ];
    continue;
  }

  $before = Yaml::parseFile($basePath);
  if (!is_array($before) || ($before['langcode'] ?? NULL) !== 'fr') {
    $reviewRequired[] = [
      'config_name' => $configName,
      'reason' => 'base_config_not_expected_fr_mapping',
    ];
    continue;
  }

  $after = $before;
  $after['langcode'] = 'en';
  $operations = [[
    'path' => 'langcode',
    'before' => 'fr',
    'after' => 'en',
    'provenance' => '#766 exact remaining-FR Canvas cohort',
  ]];
  $sourceKind = $before['source'] ?? NULL;

  if ($sourceKind === 'block') {
    $blockFiles++;
    $proof = $blockByName[$configName] ?? NULL;
    if (!is_array($proof)) {
      $reviewRequired[] = [
        'config_name' => $configName,
        'reason' => 'block_provenance_item_missing',
      ];
      continue;
    }

    $currentLabel = $proof['canvas_labels']['label'] ?? NULL;
    $settingsLabel = $proof['canvas_labels']['active_default_settings_label'] ?? NULL;
    $kind = $proof['native_admin_label']['kind'] ?? NULL;
    $arguments = $proof['native_admin_label']['arguments'] ?? NULL;
    if (
      !is_string($currentLabel)
      || $settingsLabel !== $currentLabel
      || !is_string($kind)
      || !is_array($arguments)
      || ($before['label'] ?? NULL) !== $currentLabel
      || ($before['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL)
        !== $currentLabel
    ) {
      $reviewRequired[] = [
        'config_name' => $configName,
        'reason' => 'block_current_label_contract_mismatch',
      ];
      continue;
    }

    $canonicalLabel = NULL;
    $labelProvenance = NULL;
    if ($kind === 'scalar_definition_literal') {
      $scalar = $proof['native_admin_label']['scalar_definition'] ?? NULL;
      if (is_string($scalar)) {
        $canonicalLabel = $scalar;
        $labelProvenance = '#774 plugin.manager.block scalar definition';
      }
    }
    elseif ($kind === 'translatable_markup' && $arguments === []) {
      $source = $proof['native_admin_label']['untranslated_source'] ?? NULL;
      if (is_string($source) && $source !== '') {
        $canonicalLabel = $source;
        $labelProvenance = '#774 plugin.manager.block untranslated source';
      }
    }
    elseif ($kind === 'translatable_markup' && $arguments !== []) {
      $argumentItem = $argumentByName[$configName] ?? NULL;
      $source = is_array($argumentItem)
        ? ($argumentItem['future_base_label'] ?? NULL)
        : NULL;
      if (
        is_string($source)
        && $source !== ''
        && ($argumentItem['decision'] ?? NULL)
          === 'deterministic_native_argument_provenance'
        && ($argumentItem['future_fr_override_label'] ?? NULL) === $currentLabel
      ) {
        $canonicalLabel = $source;
        $labelProvenance = '#776 structured argument provenance + PlainTextOutput';
      }
    }

    if (!is_string($canonicalLabel) || $canonicalLabel === '') {
      $reviewRequired[] = [
        'config_name' => $configName,
        'reason' => 'canonical_block_label_unresolved',
      ];
      continue;
    }

    if ($canonicalLabel !== $currentLabel) {
      $labelChangedFiles++;
      $after['label'] = $canonicalLabel;
      $setPath(
        $after,
        ['versioned_properties', 'active', 'settings', 'default_settings', 'label'],
        $canonicalLabel,
      );
      $operations[] = [
        'path' => 'label',
        'before' => $currentLabel,
        'after' => $canonicalLabel,
        'provenance' => $labelProvenance,
      ];
      $operations[] = [
        'path' => 'versioned_properties.active.settings.default_settings.label',
        'before' => $settingsLabel,
        'after' => $canonicalLabel,
        'provenance' => $labelProvenance,
      ];

      $relativeOverride = 'language/fr/' . $configName . '.yml';
      $overridePath = $configDirectory . '/' . $relativeOverride;
      $overrideBefore = [];
      if (is_file($overridePath)) {
        $existingOverrideFiles++;
        $parsed = Yaml::parseFile($overridePath);
        if (!is_array($parsed)) {
          $reviewRequired[] = [
            'config_name' => $configName,
            'reason' => 'existing_fr_override_invalid',
          ];
          continue;
        }
        $overrideBefore = $parsed;
      }
      $overrideAfter = $overrideBefore;
      $overrideAfter['label'] = $currentLabel;
      $setPath(
        $overrideAfter,
        ['versioned_properties', 'active', 'settings', 'default_settings', 'label'],
        $currentLabel,
      );
      $overridePlans[] = [
        'config_name' => $configName,
        'path' => $relativeOverride,
        'action' => $overrideBefore === [] ? 'create' : 'update',
        'before' => $canonicalize($overrideBefore),
        'after' => $canonicalize($overrideAfter),
        'provenance' => 'preserve exact current FR Canvas labels after canonical base promotion',
      ];
    }
  }
  elseif ($sourceKind === 'sdc') {
    $sdcFiles++;
    $proof = $sdcByName[$configName] ?? NULL;
    if (
      !is_array($proof)
      || ($proof['classification'] ?? NULL)
        !== 'all_material_values_present_in_combined_native_sources'
      || ($proof['final_unmatched_leaf_count'] ?? NULL) !== 0
    ) {
      $reviewRequired[] = [
        'config_name' => $configName,
        'reason' => 'sdc_native_value_coverage_incomplete',
      ];
      continue;
    }
    // #772 proves all 36 current translatable values are already present in
    // admitted native source surfaces. #779 therefore changes no SDC value:
    // source-value presence is a preservation guard, not a rewrite signal.
  }
  else {
    $reviewRequired[] = [
      'config_name' => $configName,
      'reason' => 'unexpected_canvas_source_kind',
      'source' => $sourceKind,
    ];
    continue;
  }

  $basePlans[] = [
    'config_name' => $configName,
    'path' => $configName . '.yml',
    'source_kind' => $sourceKind,
    'before_hash' => hash(
      'sha256',
      json_encode($canonicalize($before), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ),
    'after_hash' => hash(
      'sha256',
      json_encode($canonicalize($after), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ),
    'operations' => $operations,
    'before' => $canonicalize($before),
    'after' => $canonicalize($after),
    'historical_versions_modified' => FALSE,
  ];
}

usort(
  $basePlans,
  static fn(array $left, array $right): int =>
    $left['config_name'] <=> $right['config_name'],
);
usort(
  $overridePlans,
  static fn(array $left, array $right): int =>
    $left['config_name'] <=> $right['config_name'],
);
usort(
  $reviewRequired,
  static fn(array $left, array $right): int =>
    ($left['config_name'] ?? '') <=> ($right['config_name'] ?? ''),
);

$simulatedDistribution = $currentDistribution;
$simulatedDistribution['fr'] -= count($basePlans);
$simulatedDistribution['en'] += count($basePlans);
ksort($simulatedDistribution, SORT_STRING);
$expectedTargetDistribution = [
  '__none__' => 59,
  'en' => 496,
  'fr' => 39,
  'und' => 1,
];

if (count($basePlans) !== 30) {
  $reviewRequired[] = [
    'reason' => 'base_plan_count_mismatch',
    'actual' => count($basePlans),
    'expected' => 30,
  ];
}
if ($blockFiles !== 26 || $sdcFiles !== 4) {
  $reviewRequired[] = [
    'reason' => 'source_kind_count_mismatch',
    'block' => $blockFiles,
    'sdc' => $sdcFiles,
  ];
}
if ($labelChangedFiles !== 15) {
  $reviewRequired[] = [
    'reason' => 'block_label_change_count_mismatch',
    'actual' => $labelChangedFiles,
    'expected' => 15,
  ];
}
if (count($overridePlans) !== 15) {
  $reviewRequired[] = [
    'reason' => 'fr_override_plan_count_mismatch',
    'actual' => count($overridePlans),
    'expected' => 15,
  ];
}
if ($simulatedDistribution !== $expectedTargetDistribution) {
  $reviewRequired[] = [
    'reason' => 'simulated_distribution_mismatch',
    'actual' => $simulatedDistribution,
    'expected' => $expectedTargetDistribution,
  ];
}
foreach ($basePlans as $plan) {
  if (($plan['historical_versions_modified'] ?? TRUE) !== FALSE) {
    $reviewRequired[] = [
      'config_name' => $plan['config_name'] ?? NULL,
      'reason' => 'historical_version_mutation_planned',
    ];
  }
}

$planPayload = [
  'cohort_names_sha256' => $namesHash,
  'base_files' => $basePlans,
  'fr_overrides' => $overridePlans,
  'simulated_distribution' => $simulatedDistribution,
];
$planCanonical = $canonicalize($planPayload);
$planHash = hash(
  'sha256',
  json_encode(
    $planCanonical,
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
  ),
);

$allProblems = [...$proofProblems, ...$reviewRequired];
$status = $proofProblems === [] ? 'PASS' : 'FAIL';
$ready = $status === 'PASS' && $reviewRequired === [];
$verdict = $status !== 'PASS'
  ? 'CANVAS_CANONICAL_MIGRATION_DRY_RUN_FAILED'
  : ($ready
    ? 'CANVAS_CANONICAL_MIGRATION_DRY_RUN_READY'
    : 'CANVAS_CANONICAL_MIGRATION_DRY_RUN_REVIEW_REQUIRED');

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'ready_for_migration' => $ready,
  'cohort' => [
    'total' => 30,
    'block' => 26,
    'sdc' => 4,
    'names_sha256' => $namesHash,
  ],
  'proofs' => [
    'sdc' => [
      'issue' => 772,
      'verdict' => $sdcProof['verdict'] ?? NULL,
      'material_translatable_leaves' => $sdcProof['counts']['material_translatable_leaves'] ?? NULL,
      'final_matched_leaves' => $sdcProof['counts']['final_matched_leaves'] ?? NULL,
    ],
    'block' => [
      'issue' => 774,
      'verdict' => $blockProof['verdict'] ?? NULL,
      'definition_resolved' => $blockProof['counts']['definition_resolved'] ?? NULL,
    ],
    'argumented_block' => [
      'issue' => 776,
      'verdict' => $argumentProof['verdict'] ?? NULL,
      'deterministic' => $argumentProof['counts']['deterministic_native_argument_provenance'] ?? NULL,
    ],
  ],
  'counts' => [
    'base_files_planned' => count($basePlans),
    'block_files_planned' => $blockFiles,
    'sdc_files_planned' => $sdcFiles,
    'langcode_changes' => count($basePlans),
    'block_label_files_changed' => $labelChangedFiles,
    'block_label_value_changes' => $labelChangedFiles * 2,
    'fr_override_files_planned' => count($overridePlans),
    'existing_fr_override_files' => $existingOverrideFiles,
    'review_required' => count($reviewRequired),
    'proof_problem' => count($proofProblems),
    'problem_count' => count($allProblems),
  ],
  'distribution' => [
    'before' => $currentDistribution,
    'after_simulated' => $simulatedDistribution,
    'total' => $totalConfig,
  ],
  'plan_sha256' => $planHash,
  'plan' => $planCanonical,
  'review_required' => $reviewRequired,
  'problems' => $allProblems,
  'constraints' => [
    'read_only' => TRUE,
    'in_memory_simulation_only' => TRUE,
    'repository_config_mutated' => FALSE,
    'historical_versions_rewritten' => FALSE,
    'sdc_values_rewritten' => FALSE,
    'sdc_native_value_presence_used_as_preservation_guard_only' => TRUE,
    'block_labels_from_native_provenance_only' => TRUE,
    'generic_normalization_used' => FALSE,
    'natural_language_heuristic_used' => FALSE,
    'automatic_arbitrary_text_translation_used' => FALSE,
    'fuzzy_matching_used' => FALSE,
    'source_generation_executed' => FALSE,
    'block_build_executed' => FALSE,
    'config_entity_creation_executed' => FALSE,
    'config_entity_update_executed' => FALSE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_access_allowed' => FALSE,
    'provider_secret_used' => FALSE,
    'migration_allowed_by_this_dry_run' => FALSE,
    'executed_mutating_methods' => [],
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT
  | JSON_UNESCAPED_SLASHES
  | JSON_UNESCAPED_UNICODE
  | JSON_THROW_ON_ERROR,
) . PHP_EOL;

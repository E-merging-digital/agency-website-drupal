<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(DRUPAL_ROOT);
$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot
  . '/docs/evidence/configuration-language-canvas-migration-plan-779.yml';
$dryRunPath = $projectRoot
  . '/scripts/runner/configuration-language-canvas-migration-dry-run-779.php';

$expectedPlanSha = '8627ce9ec73a45f5501a410e15c4e56fcbc45239ee2c1c166e5d5445337e1a24';
$expectedDistributionBefore = [
  '__none__' => 59,
  'en' => 466,
  'fr' => 69,
  'und' => 1,
];
$expectedDistributionAfter = [
  '__none__' => 59,
  'en' => 496,
  'fr' => 39,
  'und' => 1,
];
$allowedOperationPaths = [
  'langcode',
  'label',
  'versioned_properties.active.settings.default_settings.label',
];

if (!is_dir($configDirectory) || !is_file($manifestPath) || !is_file($dryRunPath)) {
  throw new RuntimeException('Issue #781 migration prerequisites are unavailable.');
}

$manifest = Yaml::parseFile($manifestPath);
if (
  !is_array($manifest)
  || ($manifest['issue'] ?? NULL) !== 779
  || ($manifest['application_issue'] ?? NULL) !== 781
  || ($manifest['source']['plan_sha256'] ?? NULL) !== $expectedPlanSha
  || ($manifest['cohort']['total'] ?? NULL) !== 30
  || ($manifest['cohort']['block'] ?? NULL) !== 26
  || ($manifest['cohort']['sdc'] ?? NULL) !== 4
  || ($manifest['target']['distribution'] ?? NULL) !== $expectedDistributionAfter
  || ($manifest['target']['base_files_modified'] ?? NULL) !== 30
  || ($manifest['target']['block_label_files_modified'] ?? NULL) !== 15
  || ($manifest['target']['fr_overrides_created'] ?? NULL) !== 15
  || ($manifest['constraints']['config_language_lock_enabled'] ?? TRUE) !== FALSE
  || ($manifest['constraints']['system_site_default_langcode'] ?? NULL) !== 'fr'
) {
  throw new RuntimeException('Issue #781 trusted migration contract drifted.');
}

$runDryRun = static function (string $path): array {
  ob_start();
  try {
    require $path;
    $raw = (string) ob_get_clean();
  }
  catch (Throwable $throwable) {
    ob_end_clean();
    throw $throwable;
  }

  $decoded = json_decode($raw, TRUE, 512, JSON_THROW_ON_ERROR);
  if (!is_array($decoded)) {
    throw new RuntimeException('Issue #779 dry-run did not return a mapping.');
  }
  return $decoded;
};

$plan = $runDryRun($dryRunPath);
if (
  ($plan['status'] ?? NULL) !== 'PASS'
  || ($plan['verdict'] ?? NULL) !== 'CANVAS_CANONICAL_MIGRATION_DRY_RUN_READY'
  || ($plan['ready_for_migration'] ?? NULL) !== TRUE
  || ($plan['plan_sha256'] ?? NULL) !== $expectedPlanSha
  || ($plan['counts']['base_files_planned'] ?? NULL) !== 30
  || ($plan['counts']['block_label_files_changed'] ?? NULL) !== 15
  || ($plan['counts']['fr_override_files_planned'] ?? NULL) !== 15
  || ($plan['counts']['existing_fr_override_files'] ?? NULL) !== 0
  || ($plan['counts']['review_required'] ?? NULL) !== 0
  || ($plan['counts']['proof_problem'] ?? NULL) !== 0
  || ($plan['counts']['problem_count'] ?? NULL) !== 0
  || ($plan['distribution']['before'] ?? NULL) !== $expectedDistributionBefore
  || ($plan['distribution']['after_simulated'] ?? NULL) !== $expectedDistributionAfter
) {
  throw new RuntimeException('Issue #781 refuses to write because the trusted #779 plan no longer matches exactly.');
}

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

$semanticHash = static function (array $data) use ($canonicalize): string {
  return hash(
    'sha256',
    json_encode(
      $canonicalize($data),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ),
  );
};

$yamlScalar = static function (string $value): string {
  $dump = Yaml::dump(['value' => $value], 1, 2);
  if (!preg_match('/^value:\s*(.+)\n$/u', $dump, $matches)) {
    throw new RuntimeException('Unable to serialize a one-line YAML scalar for #781.');
  }
  return $matches[1];
};

$replaceScalarPath = static function (
  string $content,
  string $targetPath,
  string $before,
  string $after,
) use ($yamlScalar): string {
  if (str_contains($content, "\r")) {
    throw new RuntimeException('Issue #781 refuses CR line endings.');
  }
  $lines = explode("\n", $content);
  $stack = [];
  $matches = 0;

  foreach ($lines as $index => $line) {
    if (!preg_match('/^( *)([A-Za-z0-9_.-]+):(.*)$/u', $line, $parts)) {
      continue;
    }
    $indent = strlen($parts[1]);
    if (($indent % 2) !== 0) {
      continue;
    }
    $depth = intdiv($indent, 2);
    $stack = array_slice($stack, 0, $depth);
    $stack[$depth] = $parts[2];
    $path = implode('.', $stack);
    if ($path !== $targetPath) {
      continue;
    }

    $parsed = Yaml::parse("value:" . $parts[3] . "\n");
    if (!is_array($parsed) || ($parsed['value'] ?? NULL) !== $before) {
      throw new RuntimeException(sprintf(
        'Issue #781 scalar baseline mismatch at %s.',
        $targetPath,
      ));
    }
    $lines[$index] = $parts[1] . $parts[2] . ': ' . $yamlScalar($after);
    $matches++;
  }

  if ($matches !== 1) {
    throw new RuntimeException(sprintf(
      'Issue #781 expected exactly one scalar at %s, found %d.',
      $targetPath,
      $matches,
    ));
  }
  return implode("\n", $lines);
};

$countDistribution = static function (string $directory): array {
  $counts = [];
  $files = glob($directory . '/*.yml');
  if ($files === FALSE) {
    throw new RuntimeException('Unable to enumerate configuration for #781.');
  }
  sort($files, SORT_STRING);
  foreach ($files as $path) {
    $data = Yaml::parseFile($path);
    if (!is_array($data)) {
      throw new RuntimeException('Invalid configuration mapping: ' . basename($path));
    }
    $langcode = isset($data['langcode']) && is_string($data['langcode'])
      ? $data['langcode']
      : '__none__';
    $counts[$langcode] = ($counts[$langcode] ?? 0) + 1;
  }
  ksort($counts, SORT_STRING);
  return [count($files), $counts];
};

[$beforeTotal, $beforeDistribution] = $countDistribution($configDirectory);
if ($beforeTotal !== 595 || $beforeDistribution !== $expectedDistributionBefore) {
  throw new RuntimeException('Issue #781 repository distribution drifted before write.');
}

$coreExtension = Yaml::parseFile($configDirectory . '/core.extension.yml');
$systemSite = Yaml::parseFile($configDirectory . '/system.site.yml');
if (
  !is_array($coreExtension)
  || isset($coreExtension['module']['config_language_lock'])
  || is_file($configDirectory . '/config_language_lock.settings.yml')
  || !is_array($systemSite)
  || ($systemSite['default_langcode'] ?? NULL) !== 'fr'
) {
  throw new RuntimeException('Issue #781 lock/site-default boundary is not satisfied.');
}

$basePlans = $plan['plan']['base_files'] ?? NULL;
$overridePlans = $plan['plan']['fr_overrides'] ?? NULL;
if (!is_array($basePlans) || count($basePlans) !== 30 || !is_array($overridePlans) || count($overridePlans) !== 15) {
  throw new RuntimeException('Issue #781 plan membership is incomplete.');
}

$writtenBases = [];
$baseEvidence = [];
foreach ($basePlans as $basePlan) {
  if (!is_array($basePlan)) {
    throw new RuntimeException('Issue #781 contains an invalid base plan item.');
  }
  $configName = $basePlan['config_name'] ?? NULL;
  $relativePath = $basePlan['path'] ?? NULL;
  $sourceKind = $basePlan['source_kind'] ?? NULL;
  $operations = $basePlan['operations'] ?? NULL;
  if (
    !is_string($configName)
    || $configName === ''
    || $relativePath !== $configName . '.yml'
    || !in_array($sourceKind, ['block', 'sdc'], TRUE)
    || !is_array($operations)
    || ($basePlan['historical_versions_modified'] ?? TRUE) !== FALSE
  ) {
    throw new RuntimeException('Issue #781 base plan identity drifted.');
  }
  if ($sourceKind === 'sdc' && (count($operations) !== 1 || ($operations[0]['path'] ?? NULL) !== 'langcode')) {
    throw new RuntimeException('Issue #781 refuses any SDC value rewrite.');
  }

  $path = $configDirectory . '/' . $relativePath;
  if (!is_file($path)) {
    throw new RuntimeException('Issue #781 base config is missing: ' . $configName);
  }
  $content = file_get_contents($path);
  if (!is_string($content)) {
    throw new RuntimeException('Unable to read #781 base config: ' . $configName);
  }
  $parsedBefore = Yaml::parse($content);
  if (!is_array($parsedBefore) || $semanticHash($parsedBefore) !== ($basePlan['before_hash'] ?? NULL)) {
    throw new RuntimeException('Issue #781 base semantic before-hash mismatch: ' . $configName);
  }
  if ($canonicalize($parsedBefore) !== ($basePlan['before'] ?? NULL)) {
    throw new RuntimeException('Issue #781 base before-state mismatch: ' . $configName);
  }

  $afterContent = $content;
  foreach ($operations as $operation) {
    if (!is_array($operation)) {
      throw new RuntimeException('Issue #781 contains an invalid operation.');
    }
    $operationPath = $operation['path'] ?? NULL;
    $operationBefore = $operation['before'] ?? NULL;
    $operationAfter = $operation['after'] ?? NULL;
    if (
      !is_string($operationPath)
      || !in_array($operationPath, $allowedOperationPaths, TRUE)
      || !is_string($operationBefore)
      || !is_string($operationAfter)
    ) {
      throw new RuntimeException('Issue #781 refuses an unrecognized migration operation.');
    }
    $afterContent = $replaceScalarPath(
      $afterContent,
      $operationPath,
      $operationBefore,
      $operationAfter,
    );
  }

  $parsedAfter = Yaml::parse($afterContent);
  if (!is_array($parsedAfter)
    || $semanticHash($parsedAfter) !== ($basePlan['after_hash'] ?? NULL)
    || $canonicalize($parsedAfter) !== ($basePlan['after'] ?? NULL)) {
    throw new RuntimeException('Issue #781 base after-state mismatch: ' . $configName);
  }
  if (file_put_contents($path, $afterContent, LOCK_EX) !== strlen($afterContent)) {
    throw new RuntimeException('Unable to write #781 base config: ' . $configName);
  }
  $writtenBases[] = 'config/sync/' . $relativePath;
  $baseEvidence[] = [
    'config_name' => $configName,
    'path' => 'config/sync/' . $relativePath,
    'source_kind' => $sourceKind,
    'operation_paths' => array_values(array_map(
      static fn(array $operation): string => (string) $operation['path'],
      $operations,
    )),
    'before_file_sha256' => hash('sha256', $content),
    'after_file_sha256' => hash('sha256', $afterContent),
    'before_semantic_sha256' => $basePlan['before_hash'],
    'after_semantic_sha256' => $basePlan['after_hash'],
  ];
}

$writtenOverrides = [];
$overrideEvidence = [];
foreach ($overridePlans as $overridePlan) {
  if (!is_array($overridePlan)) {
    throw new RuntimeException('Issue #781 contains an invalid override plan item.');
  }
  $configName = $overridePlan['config_name'] ?? NULL;
  $relativePath = $overridePlan['path'] ?? NULL;
  $after = $overridePlan['after'] ?? NULL;
  if (
    !is_string($configName)
    || !is_string($relativePath)
    || $relativePath !== 'language/fr/' . $configName . '.yml'
    || ($overridePlan['action'] ?? NULL) !== 'create'
    || ($overridePlan['before'] ?? NULL) !== []
    || !is_array($after)
  ) {
    throw new RuntimeException('Issue #781 override plan identity drifted.');
  }
  $expectedLabel = $after['label'] ?? NULL;
  $expectedActiveLabel = $after['versioned_properties']['active']['settings']['default_settings']['label'] ?? NULL;
  if (!is_string($expectedLabel) || $expectedActiveLabel !== $expectedLabel) {
    throw new RuntimeException('Issue #781 override labels are not exact and minimal.');
  }

  $path = $configDirectory . '/' . $relativePath;
  if (is_file($path)) {
    throw new RuntimeException('Issue #781 refuses to overwrite a pre-existing FR override: ' . $configName);
  }
  $content = Yaml::dump($after, 99, 2);
  if (file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
    throw new RuntimeException('Unable to create #781 FR override: ' . $configName);
  }
  $parsed = Yaml::parseFile($path);
  if (!is_array($parsed) || $canonicalize($parsed) !== $canonicalize($after)) {
    throw new RuntimeException('Issue #781 FR override serialization drifted: ' . $configName);
  }
  $writtenOverrides[] = 'config/sync/' . $relativePath;
  $overrideEvidence[] = [
    'config_name' => $configName,
    'path' => 'config/sync/' . $relativePath,
    'label' => $expectedLabel,
    'file_sha256' => hash('sha256', $content),
  ];
}

sort($writtenBases, SORT_STRING);
sort($writtenOverrides, SORT_STRING);
usort($baseEvidence, static fn(array $left, array $right): int => $left['config_name'] <=> $right['config_name']);
usort($overrideEvidence, static fn(array $left, array $right): int => $left['config_name'] <=> $right['config_name']);

[$afterTotal, $afterDistribution] = $countDistribution($configDirectory);
if ($afterTotal !== 595 || $afterDistribution !== $expectedDistributionAfter) {
  throw new RuntimeException('Issue #781 target repository distribution is not exact.');
}

$coreExtensionAfter = Yaml::parseFile($configDirectory . '/core.extension.yml');
$systemSiteAfter = Yaml::parseFile($configDirectory . '/system.site.yml');
if (
  !is_array($coreExtensionAfter)
  || isset($coreExtensionAfter['module']['config_language_lock'])
  || is_file($configDirectory . '/config_language_lock.settings.yml')
  || !is_array($systemSiteAfter)
  || ($systemSiteAfter['default_langcode'] ?? NULL) !== 'fr'
) {
  throw new RuntimeException('Issue #781 lock/site-default boundary changed during write.');
}

$result = [
  'schema_version' => 1,
  'status' => 'PASS',
  'verdict' => 'CANVAS_CANONICAL_MIGRATION_PATCH_PREPARED',
  'source' => [
    'issue' => 779,
    'plan_sha256_expected' => $expectedPlanSha,
    'plan_sha256_observed' => $plan['plan_sha256'],
  ],
  'counts' => [
    'base_files_modified' => count($writtenBases),
    'block_label_files_modified' => 15,
    'block_label_values_modified' => 30,
    'fr_overrides_created' => count($writtenOverrides),
    'problem_count' => 0,
  ],
  'distribution' => [
    'before' => $beforeDistribution,
    'after' => $afterDistribution,
    'total' => $afterTotal,
  ],
  'paths' => [
    'modified_base' => $writtenBases,
    'created_fr_overrides' => $writtenOverrides,
  ],
  'base_evidence' => $baseEvidence,
  'override_evidence' => $overrideEvidence,
  'problems' => [],
  'constraints' => [
    'exact_hashed_plan_replayed_before_write' => TRUE,
    'textual_scalar_paths_only' => TRUE,
    'historical_versions_rewritten' => FALSE,
    'sdc_values_rewritten' => FALSE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'system_site_default_langcode' => 'fr',
    'natural_language_heuristic_used' => FALSE,
    'arbitrary_translation_used' => FALSE,
    'fuzzy_matching_used' => FALSE,
    'production_access_used' => FALSE,
    'provider_secret_used' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(__DIR__, 2);
$autoload = $projectRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
  throw new RuntimeException('Composer autoload is missing.');
}
require_once $autoload;

$configDirectory = $projectRoot . '/config/sync';
$manifestPath = $projectRoot . '/docs/evidence/configuration-language-base-field-runtime-cohort-760.yml';

if (!is_dir($configDirectory) || !is_file($manifestPath)) {
  throw new RuntimeException('Issue #760 configuration or cohort manifest is missing.');
}

$manifest = Yaml::parseFile($manifestPath);
if (!is_array($manifest)) {
  throw new RuntimeException('Issue #760 cohort manifest must be a mapping.');
}

$expectedBaseline = [
  '__none__' => 59,
  'en' => 413,
  'fr' => 122,
  'und' => 1,
];
$expectedTarget = [
  '__none__' => 59,
  'en' => 466,
  'fr' => 69,
  'und' => 1,
];
$expectedException = 'core.base_field_override.canvas_page.canvas_page.components';
$expectedNamesHash = '72d2e6f904c99bfd426f6ca58e2c3e7c277beb36eff62eed269a793ff5846535';
$expectedExactHash = 'fc71d904d034fb657ce0dc6d61528d2e8e50e9ede1b838ab4d91842f821cfcbf';

if (($manifest['issue'] ?? NULL) !== 760
  || ($manifest['parent_issue'] ?? NULL) !== 609
  || ($manifest['cohort']['total'] ?? NULL) !== 53
  || ($manifest['cohort']['exact_runtime_source_match'] ?? NULL) !== 52
  || ($manifest['cohort']['review_exception'] ?? NULL) !== 1
  || ($manifest['cohort']['unresolved'] ?? NULL) !== 0
  || ($manifest['cohort']['names_sha256'] ?? NULL) !== $expectedNamesHash
  || ($manifest['cohort']['exact_match_names_sha256'] ?? NULL) !== $expectedExactHash
  || ($manifest['baseline']['total_config'] ?? NULL) !== 595
  || ($manifest['baseline']['distribution'] ?? NULL) !== $expectedBaseline
  || ($manifest['target']['total_config'] ?? NULL) !== 595
  || ($manifest['target']['distribution'] ?? NULL) !== $expectedTarget
  || ($manifest['constraints']['exact_match_mutation'] ?? NULL) !== 'langcode_only'
  || ($manifest['constraints']['exception_mutation'] ?? NULL) !== 'langcode_and_label_with_fr_override'
  || ($manifest['constraints']['natural_language_heuristic_used'] ?? TRUE) !== FALSE
  || ($manifest['constraints']['config_export_allowed'] ?? TRUE) !== FALSE
  || ($manifest['constraints']['config_language_lock_activation_allowed'] ?? TRUE) !== FALSE) {
  throw new RuntimeException('Issue #760 cohort contract drifted.');
}

$exactNames = $manifest['cohort']['exact_match_names'] ?? NULL;
$exception = $manifest['cohort']['exception'] ?? NULL;
if (!is_array($exactNames) || count($exactNames) !== 52 || !is_array($exception)) {
  throw new RuntimeException('Issue #760 cohort membership is invalid.');
}
foreach ($exactNames as $name) {
  if (!is_string($name) || $name === '') {
    throw new RuntimeException('Issue #760 exact-match cohort contains an invalid name.');
  }
}
if (count(array_unique($exactNames)) !== 52) {
  throw new RuntimeException('Issue #760 exact-match cohort contains duplicate names.');
}
sort($exactNames, SORT_STRING);

$exceptionName = $exception['name'] ?? NULL;
if ($exceptionName !== $expectedException
  || ($exception['path'] ?? NULL) !== 'label'
  || ($exception['current_config_value'] ?? NULL) !== 'Composants'
  || ($exception['runtime_untranslated_source_value'] ?? NULL) !== 'Components'
  || ($exception['target_base_value'] ?? NULL) !== 'Components'
  || ($exception['target_fr_override_value'] ?? NULL) !== 'Composants'
  || in_array($exceptionName, $exactNames, TRUE)) {
  throw new RuntimeException('Issue #760 localized exception contract drifted.');
}

$allNames = [...$exactNames, $exceptionName];
sort($allNames, SORT_STRING);
if (count($allNames) !== 53 || count(array_unique($allNames)) !== 53) {
  throw new RuntimeException('Issue #760 must contain exactly 53 unique names.');
}
$hashNames = static fn(array $names): string => hash('sha256', implode("\n", $names) . "\n");
if ($hashNames($allNames) !== $expectedNamesHash || $hashNames($exactNames) !== $expectedExactHash) {
  throw new RuntimeException('Issue #760 cohort identity hash mismatch.');
}

$countRepositoryDistribution = static function (string $directory): array {
  $files = glob($directory . '/*.yml');
  if ($files === FALSE) {
    throw new RuntimeException('Unable to enumerate repository configuration.');
  }
  sort($files, SORT_STRING);
  $counts = [];
  foreach ($files as $path) {
    $data = Yaml::parseFile($path);
    if (!is_array($data)) {
      throw new RuntimeException('Repository configuration is not a mapping: ' . basename($path));
    }
    $langcode = isset($data['langcode']) && is_string($data['langcode'])
      ? $data['langcode']
      : '__none__';
    $counts[$langcode] = ($counts[$langcode] ?? 0) + 1;
  }
  ksort($counts, SORT_STRING);
  return [count($files), $counts];
};

[$baselineTotal, $baselineDistribution] = $countRepositoryDistribution($configDirectory);
if ($baselineTotal !== 595 || $baselineDistribution !== $expectedBaseline) {
  throw new RuntimeException('Issue #760 repository baseline distribution drifted.');
}

$coreExtension = Yaml::parseFile($configDirectory . '/core.extension.yml');
if (!is_array($coreExtension)) {
  throw new RuntimeException('core.extension configuration is invalid.');
}
if (isset($coreExtension['module']['config_language_lock'])
  || is_file($configDirectory . '/config_language_lock.settings.yml')) {
  throw new RuntimeException('Configuration Language Lock must remain disabled for #760.');
}
$systemSite = Yaml::parseFile($configDirectory . '/system.site.yml');
if (!is_array($systemSite) || ($systemSite['default_langcode'] ?? NULL) !== 'fr') {
  throw new RuntimeException('system.site.default_langcode must remain fr.');
}

$prepared = [];
foreach ($allNames as $name) {
  $path = $configDirectory . '/' . $name . '.yml';
  if (!is_file($path)) {
    throw new RuntimeException("Missing #760 base configuration: $name");
  }
  $content = file_get_contents($path);
  if (!is_string($content)) {
    throw new RuntimeException("Unable to read #760 base configuration: $name");
  }
  if (str_contains($content, "\r")) {
    throw new RuntimeException("Unexpected CR line endings in #760 base configuration: $name");
  }
  if (preg_match_all('/^langcode: fr$/m', $content) !== 1) {
    throw new RuntimeException("Expected exactly one top-level langcode: fr line in $name");
  }
  $data = Yaml::parse($content);
  if (!is_array($data) || ($data['langcode'] ?? NULL) !== 'fr') {
    throw new RuntimeException("Unexpected parsed baseline langcode in $name");
  }

  $enOverride = $configDirectory . '/language/en/' . $name . '.yml';
  if (is_file($enOverride)) {
    throw new RuntimeException("Unexpected EN override for #760 target: $name");
  }
  $frOverride = $configDirectory . '/language/fr/' . $name . '.yml';
  if (is_file($frOverride)) {
    throw new RuntimeException("Unexpected pre-existing FR override for #760 target: $name");
  }

  if ($name === $exceptionName) {
    if (($data['label'] ?? NULL) !== 'Composants'
      || preg_match_all('/^label: Composants$/m', $content) !== 1) {
      throw new RuntimeException('The #760 Canvas components exception no longer has the exact French label.');
    }
  }

  $prepared[$name] = [
    'path' => $path,
    'relative_path' => 'config/sync/' . $name . '.yml',
    'content' => $content,
    'data' => $data,
  ];
}

$items = [];
foreach ($exactNames as $name) {
  $entry = $prepared[$name];
  $count = 0;
  $after = preg_replace('/^langcode: fr$/m', 'langcode: en', $entry['content'], 1, $count);
  if (!is_string($after) || $count !== 1) {
    throw new RuntimeException("Unable to prepare exact langcode replacement for $name");
  }
  $afterData = Yaml::parse($after);
  $expectedData = $entry['data'];
  $expectedData['langcode'] = 'en';
  if (!is_array($afterData) || $afterData !== $expectedData) {
    throw new RuntimeException("Unexpected semantic change while preparing $name");
  }
  if (file_put_contents($entry['path'], $after) !== strlen($after)) {
    throw new RuntimeException("Unable to write prepared #760 configuration: $name");
  }
  $items[] = [
    'name' => $name,
    'path' => $entry['relative_path'],
    'classification' => 'runtime_exact_match',
    'before_langcode' => 'fr',
    'after_langcode' => 'en',
    'text_mutation' => 'langcode_only',
  ];
}

$entry = $prepared[$exceptionName];
$countLangcode = 0;
$after = preg_replace('/^langcode: fr$/m', 'langcode: en', $entry['content'], 1, $countLangcode);
$countLabel = 0;
$after = is_string($after)
  ? preg_replace('/^label: Composants$/m', 'label: Components', $after, 1, $countLabel)
  : NULL;
if (!is_string($after) || $countLangcode !== 1 || $countLabel !== 1) {
  throw new RuntimeException('Unable to prepare the exact #760 Canvas exception replacements.');
}
$afterData = Yaml::parse($after);
$expectedData = $entry['data'];
$expectedData['langcode'] = 'en';
$expectedData['label'] = 'Components';
if (!is_array($afterData) || $afterData !== $expectedData) {
  throw new RuntimeException('Unexpected semantic change in the #760 Canvas exception.');
}
if (file_put_contents($entry['path'], $after) !== strlen($after)) {
  throw new RuntimeException('Unable to write the #760 Canvas exception base configuration.');
}

$frOverridePath = $configDirectory . '/language/fr/' . $exceptionName . '.yml';
$frOverrideRelative = 'config/sync/language/fr/' . $exceptionName . '.yml';
$frOverrideContent = "label: Composants\n";
if (is_file($frOverridePath)) {
  throw new RuntimeException('Refusing to overwrite a pre-existing #760 French exception override.');
}
if (file_put_contents($frOverridePath, $frOverrideContent, LOCK_EX) !== strlen($frOverrideContent)) {
  throw new RuntimeException('Unable to create the #760 French exception override.');
}
$overrideData = Yaml::parseFile($frOverridePath);
if ($overrideData !== ['label' => 'Composants']) {
  throw new RuntimeException('The #760 French exception override is not minimal and exact.');
}
$items[] = [
  'name' => $exceptionName,
  'path' => $entry['relative_path'],
  'classification' => 'localized_runtime_exception',
  'before_langcode' => 'fr',
  'after_langcode' => 'en',
  'before_label' => 'Composants',
  'after_label' => 'Components',
  'fr_override_path' => $frOverrideRelative,
  'fr_override_label' => 'Composants',
  'text_mutation' => 'langcode_and_label_with_fr_override',
];

usort($items, static fn(array $left, array $right): int => $left['name'] <=> $right['name']);

[$targetTotal, $targetDistribution] = $countRepositoryDistribution($configDirectory);
if ($targetTotal !== 595 || $targetDistribution !== $expectedTarget) {
  throw new RuntimeException('Issue #760 target repository distribution is not exact.');
}

$coreExtensionAfter = Yaml::parseFile($configDirectory . '/core.extension.yml');
if (!is_array($coreExtensionAfter)
  || isset($coreExtensionAfter['module']['config_language_lock'])
  || is_file($configDirectory . '/config_language_lock.settings.yml')) {
  throw new RuntimeException('Configuration Language Lock changed during #760 preparation.');
}

$result = [
  'schema_version' => 1,
  'status' => 'PASS',
  'verdict' => 'FIFTY_THREE_BASE_FIELD_RUNTIME_CANONICAL_PATCH_PREPARED',
  'counts' => [
    'expected' => 53,
    'prepared' => count($items),
    'runtime_exact_match' => 52,
    'localized_exception' => 1,
    'fr_overrides_created' => 1,
    'problem_count' => 0,
  ],
  'baseline' => [
    'total' => $baselineTotal,
    'distribution' => $baselineDistribution,
  ],
  'target' => [
    'total' => $targetTotal,
    'distribution' => $targetDistribution,
  ],
  'cohort' => [
    'names_sha256' => $expectedNamesHash,
    'exact_match_names_sha256' => $expectedExactHash,
  ],
  'paths' => [
    'modified_base' => array_values(array_map(
      static fn(array $item): string => $item['path'],
      $items,
    )),
    'fr_override' => $frOverrideRelative,
    'manifest' => 'docs/evidence/configuration-language-base-field-runtime-cohort-760.yml',
  ],
  'items' => $items,
  'problems' => [],
  'constraints' => [
    'textual_replacements_only' => TRUE,
    'natural_language_heuristic_used' => FALSE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_access_used' => FALSE,
    'provider_secret_used' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

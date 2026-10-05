<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(__DIR__, 2);
$autoload = $projectRoot . '/vendor/autoload.php';
$cohortPath = $projectRoot . '/docs/evidence/configuration-language-mechanical-cohort-713.yml';
$configDirectory = $projectRoot . '/config/sync';

if (!is_file($autoload) || !is_file($cohortPath) || !is_dir($configDirectory)) {
  throw new RuntimeException('Required repository inputs are unavailable.');
}
require_once $autoload;

$cohort = Yaml::parseFile($cohortPath);
if (!is_array($cohort) || (int) ($cohort['expected_count'] ?? -1) !== 39) {
  throw new RuntimeException('The #713 cohort must contain exactly 39 targets.');
}

$excluded = [
  'core.entity_form_display.node.page.default',
  'field.storage.node.ai_automator_status',
];
if (($cohort['excluded_review_required'] ?? NULL) !== $excluded) {
  throw new RuntimeException('The #713 excluded exception set drifted.');
}

$items = $cohort['items'] ?? NULL;
if (!is_array($items) || count($items) !== 39) {
  throw new RuntimeException('The #713 cohort inventory is invalid.');
}

$prepared = [];
$names = [];
foreach ($items as $item) {
  if (!is_array($item) || !is_string($item['name'] ?? NULL)) {
    throw new RuntimeException('Invalid #713 cohort item.');
  }

  $name = $item['name'];
  if (isset($names[$name]) || in_array($name, $excluded, TRUE)) {
    throw new RuntimeException("Duplicate or excluded cohort item: $name");
  }
  $names[$name] = TRUE;

  $relativePath = 'config/sync/' . $name . '.yml';
  $absolutePath = $projectRoot . '/' . $relativePath;
  if (!is_file($absolutePath)) {
    throw new RuntimeException("Missing canonical configuration: $relativePath");
  }

  $beforeText = file_get_contents($absolutePath);
  if (!is_string($beforeText)) {
    throw new RuntimeException("Unable to read $relativePath");
  }
  if (str_contains($beforeText, "\r\n")) {
    throw new RuntimeException("Unexpected CRLF line endings in $relativePath");
  }

  $beforeData = Yaml::parse($beforeText);
  if (!is_array($beforeData) || ($beforeData['langcode'] ?? NULL) !== 'fr') {
    throw new RuntimeException("Expected top-level langcode fr in $relativePath");
  }

  $afterText = str_replace(
    "langcode: fr\n",
    "langcode: en\n",
    $beforeText,
    $replacementCount,
  );
  if ($replacementCount !== 1) {
    throw new RuntimeException(
      "Expected exactly one literal top-level langcode replacement in $relativePath; got $replacementCount.",
    );
  }

  $afterData = Yaml::parse($afterText);
  if (!is_array($afterData) || ($afterData['langcode'] ?? NULL) !== 'en') {
    throw new RuntimeException("Failed to prepare langcode en for $relativePath");
  }

  $beforeWithoutLangcode = $beforeData;
  $afterWithoutLangcode = $afterData;
  unset($beforeWithoutLangcode['langcode'], $afterWithoutLangcode['langcode']);
  if ($beforeWithoutLangcode !== $afterWithoutLangcode) {
    throw new RuntimeException("Semantic data outside langcode changed in $relativePath");
  }

  $prepared[$name] = [
    'name' => $name,
    'type' => $item['type'] ?? NULL,
    'path' => $relativePath,
    'before_sha256' => hash('sha256', $beforeText),
    'after_sha256' => hash('sha256', $afterText),
    'before_text' => $beforeText,
    'after_text' => $afterText,
  ];
}

if (count($prepared) !== 39) {
  throw new RuntimeException('Prepared canonical migration count is not 39.');
}

foreach ($excluded as $name) {
  $path = $configDirectory . '/' . $name . '.yml';
  $data = is_file($path) ? Yaml::parseFile($path) : NULL;
  if (!is_array($data) || ($data['langcode'] ?? NULL) !== 'fr') {
    throw new RuntimeException("Excluded exception base must remain FR: $name");
  }
  if (!is_file($configDirectory . '/language/en/' . $name . '.yml')) {
    throw new RuntimeException("Excluded exception EN override is missing: $name");
  }
}

foreach ($prepared as $entry) {
  if (file_put_contents($projectRoot . '/' . $entry['path'], $entry['after_text']) === FALSE) {
    throw new RuntimeException('Unable to write ' . $entry['path']);
  }
}

$resultItems = [];
foreach ($prepared as $entry) {
  $written = file_get_contents($projectRoot . '/' . $entry['path']);
  if (!is_string($written) || hash('sha256', $written) !== $entry['after_sha256']) {
    throw new RuntimeException('Post-write fingerprint mismatch for ' . $entry['path']);
  }
  $resultItems[] = [
    'name' => $entry['name'],
    'type' => $entry['type'],
    'path' => $entry['path'],
    'before_langcode' => 'fr',
    'after_langcode' => 'en',
    'before_sha256' => $entry['before_sha256'],
    'after_sha256' => $entry['after_sha256'],
    'semantic_preserved' => TRUE,
  ];
}

$result = [
  'schema_version' => 1,
  'status' => 'PASS',
  'verdict' => 'THIRTY_NINE_MECHANICAL_CANONICAL_PATCH_PREPARED',
  'counts' => [
    'expected' => 39,
    'prepared' => count($resultItems),
    'excluded_exceptions_preserved' => 2,
    'problem_count' => 0,
  ],
  'items' => $resultItems,
  'problems' => [],
  'constraints' => [
    'cohort_source' => 'docs/evidence/configuration-language-mechanical-cohort-713.yml',
    'global_langcode_replacement_allowed' => FALSE,
    'config_export_used' => FALSE,
    'language_override_mutation_allowed' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_mutation_allowed' => FALSE,
  ],
];

echo json_encode(
  $result,
  JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
) . PHP_EOL;

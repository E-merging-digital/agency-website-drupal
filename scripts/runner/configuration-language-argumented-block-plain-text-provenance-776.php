<?php

declare(strict_types=1);

use Drupal\Component\Render\PlainTextOutput;

$projectRoot = dirname(DRUPAL_ROOT);
$sourceProbe = $projectRoot
  . '/scripts/runner/'
  . 'configuration-language-argumented-block-label-provenance-776.php';

if (!is_file($sourceProbe)) {
  throw new RuntimeException('Issue #776 source provenance probe is missing.');
}

ob_start();
try {
  require $sourceProbe;
  $rawSource = (string) ob_get_clean();
}
catch (Throwable $throwable) {
  ob_end_clean();
  throw $throwable;
}

$source = json_decode($rawSource, TRUE, 512, JSON_THROW_ON_ERROR);
if (!is_array($source)) {
  throw new RuntimeException('Issue #776 source provenance payload is invalid.');
}
if (
  ($source['status'] ?? NULL) !== 'PASS'
  || ($source['verdict'] ?? NULL) !== 'ARGUMENTED_BLOCK_LABEL_PROVENANCE_ANALYZED'
  || ($source['counts']['candidate_total'] ?? NULL) !== 3
  || ($source['counts']['analyzed'] ?? NULL) !== 3
  || ($source['counts']['baseline_problem'] ?? NULL) !== 0
  || ($source['counts']['problem_count'] ?? NULL) !== 0
  || ($source['cohort_names_sha256'] ?? NULL)
    !== 'ad40d7521d300bd8b77978997d3a290440e4aaa775179ec4009491e328a78f14'
) {
  throw new RuntimeException('Issue #776 source provenance payload is not trusted.');
}

$items = $source['items'] ?? NULL;
if (!is_array($items) || count($items) !== 3) {
  throw new RuntimeException('Issue #776 source provenance items are incomplete.');
}

$expectedNames = [
  'canvas.component.block.language_block.language_content',
  'canvas.component.block.language_block.language_interface',
  'canvas.component.block.views_block.content_recent-block_1',
];
sort($expectedNames, SORT_STRING);

$actualNames = [];
$projectedItems = [];
$deterministic = 0;
$reviewRequired = 0;
$problems = [];

foreach ($items as $item) {
  if (!is_array($item)) {
    $problems[] = ['reason' => 'source_item_not_mapping'];
    continue;
  }

  $configName = $item['config_name'] ?? NULL;
  $currentLabel = $item['current_canvas_label'] ?? NULL;
  $sourceFormatted = $item['reconstructed']['source_formatted'] ?? NULL;
  $frSafeMarkup = $item['reconstructed']['fr'] ?? NULL;
  $enSafeMarkup = $item['reconstructed']['en'] ?? NULL;
  $nativeFrSafeMarkup = $item['native_admin_label']['rendered_fr_with_runtime_arguments'] ?? NULL;
  $nativeEnSafeMarkup = $item['native_admin_label']['rendered_en_with_runtime_arguments'] ?? NULL;
  $runtimeArgumentMatch = $item['strict_matches']['runtime_arguments_equal_provenance_fr'] ?? NULL;

  if (
    !is_string($configName)
    || !is_string($currentLabel)
    || !is_string($sourceFormatted)
    || !is_string($frSafeMarkup)
    || !is_string($enSafeMarkup)
    || !is_string($nativeFrSafeMarkup)
    || !is_string($nativeEnSafeMarkup)
    || !is_bool($runtimeArgumentMatch)
  ) {
    $problems[] = [
      'name' => is_string($configName) ? $configName : NULL,
      'reason' => 'source_item_shape_invalid',
    ];
    continue;
  }

  $actualNames[] = $configName;
  $frPlainText = PlainTextOutput::renderFromHtml($frSafeMarkup);
  $enPlainText = PlainTextOutput::renderFromHtml($enSafeMarkup);
  $nativeFrPlainText = PlainTextOutput::renderFromHtml($nativeFrSafeMarkup);
  $nativeEnPlainText = PlainTextOutput::renderFromHtml($nativeEnSafeMarkup);

  $isDeterministic = $runtimeArgumentMatch
    && $currentLabel === $frPlainText
    && $sourceFormatted !== ''
    && $enPlainText !== '';

  if ($isDeterministic) {
    $deterministic++;
  }
  else {
    $reviewRequired++;
  }

  $projectedItems[] = [
    'config_name' => $configName,
    'source_local_id' => $item['source_local_id'] ?? NULL,
    'current_canvas_label' => $currentLabel,
    'source_formatted' => $sourceFormatted,
    'safe_markup_rendering' => [
      'reconstructed_fr' => $frSafeMarkup,
      'reconstructed_en' => $enSafeMarkup,
      'native_runtime_fr' => $nativeFrSafeMarkup,
      'native_runtime_en' => $nativeEnSafeMarkup,
    ],
    'plain_text_configuration_rendering' => [
      'reconstructed_fr' => $frPlainText,
      'reconstructed_en' => $enPlainText,
      'native_runtime_fr' => $nativeFrPlainText,
      'native_runtime_en' => $nativeEnPlainText,
    ],
    'strict_matches' => [
      'runtime_arguments_equal_provenance_fr' => $runtimeArgumentMatch,
      'current_equals_plain_text_reconstructed_fr' => $currentLabel === $frPlainText,
      'current_equals_safe_markup_reconstructed_fr' => $currentLabel === $frSafeMarkup,
      'plain_text_native_fr_equals_plain_text_reconstructed_fr' => $nativeFrPlainText === $frPlainText,
      'plain_text_native_en_equals_plain_text_reconstructed_en' => $nativeEnPlainText === $enPlainText,
    ],
    'future_base_label' => $isDeterministic ? $sourceFormatted : NULL,
    'future_fr_override_label' => $isDeterministic ? $currentLabel : NULL,
    'future_en_rendered_label' => $isDeterministic ? $enPlainText : NULL,
    'decision' => $isDeterministic
      ? 'deterministic_native_argument_provenance'
      : 'review_required',
  ];
}

sort($actualNames, SORT_STRING);
if ($actualNames !== $expectedNames) {
  $problems[] = [
    'reason' => 'cohort_name_set_mismatch',
    'actual' => $actualNames,
    'expected' => $expectedNames,
  ];
}

usort(
  $projectedItems,
  static fn(array $left, array $right): int =>
    ($left['config_name'] ?? '') <=> ($right['config_name'] ?? ''),
);

$status = $problems === [] ? 'PASS' : 'FAIL';
$verdict = $status === 'PASS'
  ? 'ARGUMENTED_BLOCK_PLAIN_TEXT_PROVENANCE_ANALYZED'
  : 'ARGUMENTED_BLOCK_PLAIN_TEXT_PROVENANCE_FAILED';

$result = [
  'schema_version' => 1,
  'status' => $status,
  'verdict' => $verdict,
  'source_verdict' => $source['verdict'],
  'counts' => [
    'candidate_total' => 3,
    'analyzed' => count($projectedItems),
    'deterministic_native_argument_provenance' => $deterministic,
    'review_required' => $reviewRequired,
    'problem_count' => count($problems),
  ],
  'cohort_names_sha256' => $source['cohort_names_sha256'],
  'items' => $projectedItems,
  'problems' => $problems,
  'constraints' => [
    'read_only' => TRUE,
    'plain_text_projection_surface' => PlainTextOutput::class,
    'plain_text_projection_via_drupal_output_strategy' => TRUE,
    'strict_equality_only' => TRUE,
    'generic_normalization_used' => FALSE,
    'natural_language_heuristic_used' => FALSE,
    'automatic_arbitrary_text_translation_used' => FALSE,
    'fuzzy_matching_used' => FALSE,
    'migration_allowed_by_this_proof' => FALSE,
    'source_generation_executed' => FALSE,
    'block_build_executed' => FALSE,
    'config_entity_creation_executed' => FALSE,
    'config_entity_update_executed' => FALSE,
    'config_export_used' => FALSE,
    'config_language_lock_activation_allowed' => FALSE,
    'production_access_allowed' => FALSE,
    'provider_secret_used' => FALSE,
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

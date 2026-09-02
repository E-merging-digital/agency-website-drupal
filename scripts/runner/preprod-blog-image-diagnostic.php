<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\file\FileInterface;
use Drupal\image\Entity\ImageStyle;
use Drupal\node\NodeInterface;

const AGENCY_PREPROD_IMAGE_DIAGNOSTIC_ISSUE = 401;
const AGENCY_PREPROD_IMAGE_DIAGNOSTIC_TITLE = 'Checklist avant une refonte de site internet : 12 points à vérifier';
const AGENCY_PREPROD_IMAGE_FIELD = 'field_feature_image';
const AGENCY_PREPROD_IMAGE_SCOPE_LIMIT = 100;

/**
 * Returns one translation image reference as bounded metadata only.
 *
 * @return array<string, mixed>
 */
function agency_preprod_image_reference_state(NodeInterface $node, string $langcode): array {
  if (!$node->hasTranslation($langcode)) {
    return [
      'translation' => 'ABSENT',
      'field' => 'UNOBSERVABLE',
    ];
  }

  $translation = $node->getTranslation($langcode);
  $items = $translation->get(AGENCY_PREPROD_IMAGE_FIELD);
  if ($items->isEmpty()) {
    return [
      'translation' => 'PRESENT',
      'field' => 'EMPTY',
    ];
  }

  $item = $items->first();
  $targetId = (int) ($item?->get('target_id')->getValue() ?? 0);
  $alt = (string) ($item?->get('alt')->getValue() ?? '');
  if ($targetId <= 0) {
    return [
      'translation' => 'PRESENT',
      'field' => 'INVALID_TARGET',
      'alt_present' => trim($alt) !== '',
    ];
  }

  $file = \Drupal::entityTypeManager()->getStorage('file')->load($targetId);
  if (!$file instanceof FileInterface) {
    return [
      'translation' => 'PRESENT',
      'field' => 'REFERENCED',
      'fid' => $targetId,
      'alt_present' => trim($alt) !== '',
      'file_entity' => 'MISSING',
    ];
  }

  $uri = $file->getFileUri();
  $uriValid = str_starts_with($uri, 'public://');
  $realpath = $uriValid ? \Drupal::service('file_system')->realpath($uri) : FALSE;
  $physical = is_string($realpath) && is_file($realpath);

  return [
    'translation' => 'PRESENT',
    'field' => 'REFERENCED',
    'fid' => $targetId,
    'alt_present' => trim($alt) !== '',
    'file_entity' => 'PRESENT',
    'uri' => $uri,
    'uri_valid' => $uriValid,
    'physical_file' => $physical ? 'PRESENT' : 'MISSING',
  ];
}

/**
 * Returns physical source/derivative state for one known File entity.
 *
 * @return array<string, mixed>
 */
function agency_preprod_image_file_state(FileInterface $file): array {
  $uri = $file->getFileUri();
  $uriValid = str_starts_with($uri, 'public://');
  $realpath = $uriValid ? \Drupal::service('file_system')->realpath($uri) : FALSE;
  $sourcePresent = is_string($realpath) && is_file($realpath);
  $derivatives = [];

  foreach (['medium', 'large'] as $styleName) {
    $style = ImageStyle::load($styleName);
    if (!$style instanceof ImageStyle) {
      $derivatives[$styleName] = [
        'style' => 'MISSING',
        'physical_file' => 'UNOBSERVABLE',
      ];
      continue;
    }
    if (!$uriValid) {
      $derivatives[$styleName] = [
        'style' => 'PRESENT',
        'physical_file' => 'UNOBSERVABLE',
      ];
      continue;
    }
    $derivativeUri = $style->buildUri($uri);
    $derivativeRealpath = \Drupal::service('file_system')->realpath($derivativeUri);
    $derivativePresent = is_string($derivativeRealpath) && is_file($derivativeRealpath);
    $derivatives[$styleName] = [
      'style' => 'PRESENT',
      'uri' => $derivativeUri,
      'physical_file' => $derivativePresent ? 'PRESENT' : 'MISSING',
    ];
  }

  return [
    'fid' => (int) $file->id(),
    'uri' => $uri,
    'uri_valid' => $uriValid,
    'physical_file' => $sourcePresent ? 'PRESENT' : 'MISSING',
    'derivatives' => $derivatives,
  ];
}

/**
 * Derives a bounded render classification without rendering or cache writes.
 */
function agency_preprod_image_render_state(
  bool $configured,
  array $reference,
  array $fileState,
  string $styleName,
): string {
  if (!$configured) {
    return 'BROKEN_RENDER_CONFIG';
  }
  if (($reference['field'] ?? NULL) === 'EMPTY') {
    return 'BROKEN_FIELD_EMPTY';
  }
  if (($reference['file_entity'] ?? NULL) === 'MISSING') {
    return 'BROKEN_FILE_ENTITY_MISSING';
  }
  if (($fileState['uri_valid'] ?? FALSE) !== TRUE) {
    return 'BROKEN_URI_INVALID';
  }
  if (($fileState['physical_file'] ?? NULL) === 'PRESENT') {
    return 'PASS_SOURCE_PRESENT';
  }
  $derivative = $fileState['derivatives'][$styleName]['physical_file'] ?? 'UNOBSERVABLE';
  if ($derivative === 'PRESENT') {
    return 'DEGRADED_SOURCE_MISSING_DERIVATIVE_PRESENT';
  }
  if ($derivative === 'MISSING') {
    return 'BROKEN_SOURCE_AND_DERIVATIVE_MISSING';
  }
  return 'UNOBSERVABLE';
}

$container = \Drupal::getContainer();
$state = $container->get('state');
$entityTypeManager = $container->get('entity_type.manager');
$fileStorage = $entityTypeManager->getStorage('file');
$nodeStorage = $entityTypeManager->getStorage('node');

$mapping = $state->get('agency_editorial.issue.' . AGENCY_PREPROD_IMAGE_DIAGNOSTIC_ISSUE);
$sampleNodeId = is_array($mapping) && is_int($mapping['node_id'] ?? NULL)
  ? $mapping['node_id']
  : 0;
$sample = $sampleNodeId > 0 ? $nodeStorage->load($sampleNodeId) : NULL;

$sampleFound = $sample instanceof NodeInterface && $sample->bundle() === 'article';
$titleMatches = FALSE;
$fieldTargetType = 'UNOBSERVABLE';
$sampleFr = ['translation' => 'UNOBSERVABLE', 'field' => 'UNOBSERVABLE'];
$sampleEn = ['translation' => 'UNOBSERVABLE', 'field' => 'UNOBSERVABLE'];
$fileState = [
  'physical_file' => 'UNOBSERVABLE',
  'derivatives' => [],
];

if ($sampleFound) {
  $frNode = $sample->hasTranslation('fr') ? $sample->getTranslation('fr') : $sample;
  $titleMatches = (string) $frNode->label() === AGENCY_PREPROD_IMAGE_DIAGNOSTIC_TITLE;
  $definition = $sample->getFieldDefinition(AGENCY_PREPROD_IMAGE_FIELD);
  $fieldTargetType = (string) ($definition?->getFieldStorageDefinition()->getSetting('target_type') ?? 'UNOBSERVABLE');
  $sampleFr = agency_preprod_image_reference_state($sample, 'fr');
  $sampleEn = agency_preprod_image_reference_state($sample, 'en');

  $fid = (int) ($sampleFr['fid'] ?? $sampleEn['fid'] ?? 0);
  if ($fid > 0) {
    $file = $fileStorage->load($fid);
    if ($file instanceof FileInterface) {
      $fileState = agency_preprod_image_file_state($file);
    }
  }
}

/** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $displayRepository */
$displayRepository = $container->get('entity_display.repository');
assert($displayRepository instanceof EntityDisplayRepositoryInterface);
$teaserDisplay = $displayRepository->getViewDisplay('node', 'article', 'teaser');
$defaultDisplay = $displayRepository->getViewDisplay('node', 'article', 'default');
$teaserComponent = $teaserDisplay->getComponent(AGENCY_PREPROD_IMAGE_FIELD);
$defaultComponent = $defaultDisplay->getComponent(AGENCY_PREPROD_IMAGE_FIELD);
$blogViewMode = (string) \Drupal::config('views.view.blog')->get('display.default.display_options.row.options.view_mode');
$listingConfigured = $blogViewMode === 'teaser'
  && is_array($teaserComponent)
  && ($teaserComponent['type'] ?? NULL) === 'image';
$detailConfigured = is_array($defaultComponent)
  && ($defaultComponent['type'] ?? NULL) === 'image';

$listingState = agency_preprod_image_render_state(
  $listingConfigured,
  $sampleFr,
  $fileState,
  'medium',
);
$detailState = agency_preprod_image_render_state(
  $detailConfigured,
  $sampleFr,
  $fileState,
  'large',
);

$ids = \Drupal::entityQuery('node')
  ->accessCheck(FALSE)
  ->condition('type', 'article')
  ->condition('status', 1)
  ->sort('nid', 'ASC')
  ->range(0, AGENCY_PREPROD_IMAGE_SCOPE_LIMIT + 1)
  ->execute();
$scopeTruncated = count($ids) > AGENCY_PREPROD_IMAGE_SCOPE_LIMIT;
if ($scopeTruncated) {
  $ids = array_slice($ids, 0, AGENCY_PREPROD_IMAGE_SCOPE_LIMIT, TRUE);
}

$scope = [
  'published_articles_observed' => count($ids),
  'scope_limit' => AGENCY_PREPROD_IMAGE_SCOPE_LIMIT,
  'scope_truncated' => $scopeTruncated,
  'field_empty' => 0,
  'with_image_reference' => 0,
  'missing_file_entity' => 0,
  'missing_physical_file' => 0,
  'physical_file_present' => 0,
  'missing_physical_node_ids' => [],
];

foreach ($nodeStorage->loadMultiple($ids) as $node) {
  if (!$node instanceof NodeInterface) {
    continue;
  }
  $refs = [];
  foreach (['fr', 'en'] as $langcode) {
    $reference = agency_preprod_image_reference_state($node, $langcode);
    if (($reference['field'] ?? NULL) === 'REFERENCED') {
      $refs[] = $reference;
    }
  }
  if ($refs === []) {
    $scope['field_empty']++;
    continue;
  }

  $scope['with_image_reference']++;
  $nodeMissingFileEntity = FALSE;
  $nodeMissingPhysical = FALSE;
  $nodePhysicalPresent = FALSE;
  foreach ($refs as $reference) {
    if (($reference['file_entity'] ?? NULL) === 'MISSING') {
      $nodeMissingFileEntity = TRUE;
      continue;
    }
    if (($reference['physical_file'] ?? NULL) === 'MISSING') {
      $nodeMissingPhysical = TRUE;
    }
    if (($reference['physical_file'] ?? NULL) === 'PRESENT') {
      $nodePhysicalPresent = TRUE;
    }
  }
  if ($nodeMissingFileEntity) {
    $scope['missing_file_entity']++;
  }
  if ($nodeMissingPhysical) {
    $scope['missing_physical_file']++;
    if (count($scope['missing_physical_node_ids']) < 20) {
      $scope['missing_physical_node_ids'][] = (int) $node->id();
    }
  }
  if ($nodePhysicalPresent && !$nodeMissingPhysical) {
    $scope['physical_file_present']++;
  }
}

$rootCause = 'UNPROVEN';
if (!$sampleFound || !$titleMatches) {
  $rootCause = 'A_SAMPLE_ARTICLE_NOT_FOUND_OR_MAPPING_MISMATCH';
}
elseif (($sampleFr['field'] ?? NULL) === 'EMPTY') {
  $rootCause = 'A_ARTICLE_FIELD_EMPTY';
}
elseif (($sampleFr['file_entity'] ?? NULL) === 'MISSING') {
  $rootCause = 'B_FILE_REFERENCE_BROKEN';
}
elseif (($fileState['physical_file'] ?? NULL) === 'MISSING') {
  $rootCause = 'D_PUBLIC_FILE_NOT_PRESENT_IN_PREPROD';
}
elseif (!$listingConfigured || !$detailConfigured) {
  $rootCause = 'E_RENDER_CONFIGURATION_DEFECT';
}
elseif (str_starts_with($listingState, 'BROKEN_') || str_starts_with($detailState, 'BROKEN_')) {
  $rootCause = 'E_RENDER_DEFECT';
}
else {
  $rootCause = 'NO_DEFECT_REPRODUCED';
}

$result = [
  'schema_version' => 1,
  'status' => 'PASS',
  'target' => 'PREPROD',
  'prod_access' => 'NONE',
  'prod_write' => 'NONE',
  'preprod_destructive_mutation' => 'NONE',
  'sample' => [
    'issue' => AGENCY_PREPROD_IMAGE_DIAGNOSTIC_ISSUE,
    'expected_title_fr' => AGENCY_PREPROD_IMAGE_DIAGNOSTIC_TITLE,
    'mapping_state' => $sampleNodeId > 0 ? 'PRESENT' : 'MISSING',
    'node_id' => $sampleNodeId > 0 ? $sampleNodeId : NULL,
    'article_found' => $sampleFound,
    'title_matches' => $titleMatches,
    'field_target_type' => $fieldTargetType,
    'media_entity_referenced' => $fieldTargetType === 'file' ? 'NO_BY_MODEL' : 'UNOBSERVABLE',
    'fr' => $sampleFr,
    'en' => $sampleEn,
    'file' => $fileState,
    'listing' => [
      'blog_view_mode' => $blogViewMode,
      'field_configured' => $listingConfigured,
      'state' => $listingState,
    ],
    'detail' => [
      'field_configured' => $detailConfigured,
      'state' => $detailState,
    ],
  ],
  'scope' => $scope,
  'root_cause' => $rootCause,
];

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

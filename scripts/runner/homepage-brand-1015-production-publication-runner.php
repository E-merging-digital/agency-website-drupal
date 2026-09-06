<?php

declare(strict_types=1);

use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Drupal\emerging_digital_content\ContentSync\Repository\ContentSyncMappingRepository;
use Drupal\node\NodeInterface;

$mode = getenv('AGENCY_HOMEPAGE_BRAND_1015_PROD_MODE') ?: '';
$resultPath = getenv('AGENCY_HOMEPAGE_BRAND_1015_PROD_RESULT_PATH') ?: '';
$libraryPath = getenv('AGENCY_HOMEPAGE_BRAND_1015_LIBRARY_PATH') ?: '';

$writeResult = static function (array $result) use ($resultPath): void {
  if ($resultPath === '') {
    throw new RuntimeException('AGENCY_HOMEPAGE_BRAND_1015_PROD_RESULT_PATH is required.');
  }
  file_put_contents(
    $resultPath,
    json_encode(
      $result,
      JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . PHP_EOL,
  );
};

try {
  if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
    throw new InvalidArgumentException('Unsupported AGENCY_HOMEPAGE_BRAND_1015_PROD_MODE.');
  }
  if ($libraryPath === '' || !is_file($libraryPath)) {
    throw new RuntimeException('Homepage Brand #1015 profile library is missing.');
  }

  $container = \Drupal::getContainer();
  $node = $container->get('entity_type.manager')->getStorage('node')->load(5);
  if (!$node instanceof NodeInterface) {
    throw new RuntimeException('PROD homepage node 5 is unavailable.');
  }
  if ($node->bundle() !== 'page') {
    throw new RuntimeException('PROD homepage node 5 is not a Page bundle.');
  }
  if ((string) \Drupal::config('system.site')->get('page.front') !== '/node/5') {
    throw new RuntimeException('PROD system.site:page.front is not /node/5.');
  }

  $mappingRepository = $container->get('emerging_digital_content.content_sync_mapping_repository');
  if (!$mappingRepository instanceof ContentSyncMappingRepository) {
    throw new RuntimeException('PROD Content Sync mapping repository is unavailable.');
  }
  $mapping = $mappingRepository->findByContentId('homepage');
  if (!$mapping instanceof ContentSyncMappingRecord) {
    throw new RuntimeException('PROD homepage Content Sync mapping is unavailable.');
  }
  if ($mapping->entityType() !== 'node') {
    throw new RuntimeException(sprintf(
      'PROD homepage mapping identity mismatch: entity_type expected node, got %s.',
      var_export($mapping->entityType(), TRUE),
    ));
  }
  if ($mapping->entityId() !== 5) {
    throw new RuntimeException(sprintf(
      'PROD homepage mapping identity mismatch: entity_id expected 5, got %s.',
      var_export($mapping->entityId(), TRUE),
    ));
  }
  if ($mapping->entityUuid() !== $node->uuid()) {
    throw new RuntimeException(sprintf(
      'PROD homepage mapping identity mismatch: entity_uuid expected %s, got %s.',
      $node->uuid(),
      var_export($mapping->entityUuid(), TRUE),
    ));
  }
  if (!in_array($mapping->status(), [
    ContentSyncMappingRecord::STATUS_ACTIVE,
    ContentSyncMappingRecord::STATUS_RELEASED,
  ], TRUE)) {
    throw new RuntimeException(sprintf(
      'PROD homepage mapping lifecycle mismatch: status expected active or released, got %s.',
      var_export($mapping->status(), TRUE),
    ));
  }

  require_once $libraryPath;
  $candidate = AgencyHomepageBrand1015::fromContainer($container);
  $result = match ($mode) {
    'dry-run' => $candidate->dryRun(),
    'apply' => $candidate->apply(),
  };

  if (($result['status'] ?? NULL) !== 'PASS'
    || ($result['profile'] ?? NULL) !== 'homepage-brand-1015'
    || ($result['issue_number'] ?? NULL) !== 1015
    || ($result['node']['id'] ?? NULL) !== 5
    || ($result['node']['uuid'] ?? NULL) !== $node->uuid()
    || ($result['bundle'] ?? NULL) !== 'page'
    || ($result['language'] ?? NULL) !== 'fr'
    || ($result['front'] ?? NULL) !== '/node/5') {
    throw new RuntimeException('Homepage Brand #1015 PROD engine result violates the closed production identity contract.');
  }

  $contentSyncBefore = $result['content_sync_before'] ?? NULL;
  if (!in_array($contentSyncBefore, [
    ContentSyncMappingRecord::STATUS_ACTIVE,
    ContentSyncMappingRecord::STATUS_RELEASED,
  ], TRUE)) {
    throw new RuntimeException('Homepage Brand #1015 PROD engine returned an unsupported pre-write Content Sync lifecycle.');
  }

  if ($mode === 'dry-run') {
    if (($result['content_sync_after'] ?? NULL) !== 'NOT_APPLICABLE') {
      throw new RuntimeException('Homepage Brand #1015 PROD dry-run unexpectedly changed Content Sync lifecycle.');
    }
    if ($contentSyncBefore === ContentSyncMappingRecord::STATUS_ACTIVE) {
      if (($result['content_sync'] ?? NULL) !== 'ACTIVE_RECONCILIATION_REQUIRED'
        || ($result['content_sync_reconciliation'] ?? NULL) !== 'REQUIRED') {
        throw new RuntimeException('Homepage Brand #1015 PROD dry-run did not preserve the active reconciliation contract.');
      }
    }
    elseif (($result['content_sync'] ?? NULL) !== 'RELEASED'
      || ($result['content_sync_reconciliation'] ?? NULL) !== 'NOT_REQUIRED') {
      throw new RuntimeException('Homepage Brand #1015 PROD dry-run did not preserve the released lifecycle contract.');
    }
  }
  else {
    if (($result['content_sync'] ?? NULL) !== 'RELEASED'
      || ($result['content_sync_after'] ?? NULL) !== ContentSyncMappingRecord::STATUS_RELEASED) {
      throw new RuntimeException('Homepage Brand #1015 PROD apply did not converge Content Sync to released.');
    }
    $expectedReconciliation = $contentSyncBefore === ContentSyncMappingRecord::STATUS_ACTIVE
      ? 'APPLIED'
      : 'NOT_REQUIRED';
    if (($result['content_sync_reconciliation'] ?? NULL) !== $expectedReconciliation) {
      throw new RuntimeException('Homepage Brand #1015 PROD apply lifecycle reconciliation receipt is inconsistent.');
    }
  }

  $result['target'] = 'PROD';
  $result['human_approval_comment'] = 5555599795;
  $result['approved_candidate_revision'] = 45;
  $result['approved_candidate_uuid'] = '7b8d9926-e015-457f-9da3-562439b962a7';
  $result['language_mode'] = 'FR_ONLY_EXCEPTION_APPROVED';
  $result['prod_write'] = $mode === 'apply' && ($result['verdict'] ?? '') === 'APPLIED'
    ? 'MATERIALIZED'
    : 'NONE';
  $writeResult($result);
}
catch (Throwable $exception) {
  $writeResult([
    'status' => 'FAIL',
    'verdict' => 'FAIL_CLOSED',
    'mode' => $mode,
    'profile' => 'homepage-brand-1015',
    'profile_sha256' => 'bfbc8ac2d56af551509af254c797abe4437b6739b3a1d38ca369309717619da7',
    'issue_number' => 1015,
    'target' => 'PROD',
    'node_id' => 5,
    'bundle' => 'page',
    'language' => 'fr',
    'prod_write' => 'NONE',
    'message' => $exception->getMessage(),
  ]);
  exit(1);
}

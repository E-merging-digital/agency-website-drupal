<?php

declare(strict_types=1);

/**
 * Read-only source capture for future Article WebPs stored in PREPROD Drupal.
 *
 * Runs only under the authenticated, pinned PREPROD SSH/Drush channel. The
 * repository workflow, not GitHub issue text, selects all paths and commands.
 */
try {
  $issueRaw = getenv('AGENCY_IMAGE_ISSUE') ?: '';
  $revisionRaw = getenv('AGENCY_IMAGE_CANDIDATE_REVISION') ?: '';
  $mainSha = getenv('AGENCY_IMAGE_TRUSTED_MAIN') ?: '';
  $payloadSha = getenv('AGENCY_IMAGE_PAYLOAD_SHA') ?: '';
  $payloadFile = getenv('AGENCY_IMAGE_PAYLOAD_FILE') ?: '';
  $manifestFile = getenv('AGENCY_IMAGE_MANIFEST_FILE') ?: '';
  $assetFile = getenv('AGENCY_IMAGE_ASSET_FILE') ?: '';
  if (!preg_match('/^[1-9][0-9]*$/', $issueRaw) || (int) $issueRaw < 1542
    || !preg_match('/^[1-9][0-9]*$/', $revisionRaw)
    || !preg_match('/^[0-9a-f]{40}$/', $mainSha)
    || !preg_match('/^[0-9a-f]{64}$/', $payloadSha)
    || !$payloadFile || !is_file($payloadFile) || !$manifestFile || !$assetFile) {
    throw new RuntimeException('Invalid new-Article source identity.');
  }
  if (!hash_equals($payloadSha, (string) hash_file('sha256', $payloadFile))) {
    throw new RuntimeException('Owner Article payload hash mismatch.');
  }
  $payload = json_decode((string) file_get_contents($payloadFile), TRUE, 32, JSON_THROW_ON_ERROR);
  if (!is_array($payload) || ($payload['issue_number'] ?? NULL) !== (int) $issueRaw
    || ($payload['bundle'] ?? NULL) !== 'article') {
    throw new RuntimeException('Owner Article payload issue/bundle mismatch.');
  }

  $mapping = \Drupal::state()->get('agency_editorial.issue.' . $issueRaw);
  if (!is_array($mapping) || !is_int($mapping['node_id'] ?? NULL)
    || !is_string($mapping['payload_sha256'] ?? NULL)
    || !hash_equals($payloadSha, $mapping['payload_sha256'])) {
    throw new RuntimeException('PREPROD Article issue/hash mapping is absent or stale.');
  }
  $manager = \Drupal::entityTypeManager();
  $node = $manager->getStorage('node')->load($mapping['node_id']);
  if (!$node instanceof \Drupal\node\NodeInterface
    || $node->bundle() !== 'article' || !$node->hasTranslation('en')
    || !$node->hasField('field_feature_image')) {
    throw new RuntimeException('PREPROD mapped Article/translation is invalid.');
  }
  $en = $node->getTranslation('en');
  foreach (['fr' => $node, 'en' => $en] as $lang => $translation) {
    if ($translation->label() !== ($payload[$lang]['title'] ?? NULL)
      || (string) $translation->get('field_short_description')->value !== ($payload[$lang]['short_description'] ?? NULL)
      || (string) $translation->get('body')->value !== ($payload[$lang]['body_html'] ?? NULL)
      || (int) $translation->get('field_blog_category')->target_id !== (int) ($payload['category']['tid'] ?? 0)) {
      throw new RuntimeException('PREPROD Article text/category drifted from owner payload.');
    }
  }
  $frImage = $node->get('field_feature_image')->first();
  $enImage = $en->get('field_feature_image')->first();
  $frFid = $frImage?->get('target_id')->getValue();
  $enFid = $enImage?->get('target_id')->getValue();
  if ($frFid === NULL || $enFid === NULL || (int) $frFid <= 0 || (int) $frFid !== (int) $enFid) {
    throw new RuntimeException('PREPROD image File ID must be identical in FR and EN.');
  }
  $file = $manager->getStorage('file')->load((int) $frFid);
  if (!$file instanceof \Drupal\file\FileInterface || $file->isTemporary()) {
    throw new RuntimeException('PREPROD File entity is missing or temporary.');
  }
  $uri = $file->getFileUri();
  if (!is_string($uri) || !preg_match('#^public://articles/([^/\\\\]{1,128}\.webp)$#iu', $uri, $parts)) {
    throw new RuntimeException('File URI must remain in Drupal articles storage.');
  }
  $fileSystem = \Drupal::service('file_system');
  $root = $fileSystem->realpath('public://articles');
  $real = $fileSystem->realpath($uri);
  if (!is_string($root) || !is_string($real)
    || !str_starts_with($real, rtrim($root, '/') . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('File source cannot escape the Drupal articles directory.');
  }
  $filename = $file->getFilename();
  if (!is_string($filename) || $filename !== $parts[1]
    || !preg_match('/^[^\x00-\x1f\x7f\/\\\\]{1,128}\.webp$/iu', $filename)) {
    throw new RuntimeException('File name is not a bounded WebP filename.');
  }
  $altFr = $frImage?->get('alt')->getValue();
  $altEn = $enImage?->get('alt')->getValue();
  foreach ([$altFr, $altEn] as $alt) {
    if (!is_string($alt) || trim($alt) !== $alt || $alt === ''
      || mb_strlen($alt) > 300 || strip_tags($alt) !== $alt
      || preg_match('/[\x00-\x1f\x7f]/u', $alt)) {
      throw new RuntimeException('Both FR/EN ALT values must be bounded plain text.');
    }
  }
  $bytes = file_get_contents($uri);
  if (!is_string($bytes) || strlen($bytes) < 16 || strlen($bytes) > 5000000) {
    throw new RuntimeException('PREPROD WebP bytes are missing or too large.');
  }
  $info = getimagesizefromstring($bytes);
  $decoded = @imagecreatefromstring($bytes);
  if (!is_array($info) || ($info['mime'] ?? NULL) !== 'image/webp'
    || ($info[0] ?? 0) <= 0 || ($info[1] ?? 0) <= 0
    || ($info[0] ?? 0) > 2400 || ($info[1] ?? 0) > 1600
    || !$decoded instanceof \GdImage) {
    throw new RuntimeException('PREPROD WebP is not a valid bounded decoded image.');
  }
  imagedestroy($decoded);
  $assetSha = hash('sha256', $bytes);
  if (file_put_contents($assetFile, $bytes, LOCK_EX) !== strlen($bytes)) {
    throw new RuntimeException('Could not stage verified private source bytes.');
  }
  chmod($assetFile, 0600);
  // Refuse an in-place byte replacement or a concurrent Article revision.
  $manager->getStorage('node')->resetCache([(int) $node->id()]);
  $fresh = $manager->getStorage('node')->load((int) $node->id());
  if (!$fresh instanceof \Drupal\node\NodeInterface
    || (int) $fresh->getRevisionId() !== (int) $node->getRevisionId()
    || (int) $fresh->get('field_feature_image')->target_id !== (int) $frFid
    || (int) $fresh->getTranslation('en')->get('field_feature_image')->target_id !== (int) $frFid
    || !hash_equals($assetSha, (string) hash_file('sha256', $uri))) {
    throw new RuntimeException('PREPROD source changed during snapshot.');
  }
  $manifest = [
    'schema_version' => 1,
    'source' => 'DRUPAL_PREPROD_FILE_API',
    'issue_number' => (int) $issueRaw,
    'candidate_id' => 'agency-article-' . $issueRaw,
    'candidate_revision' => (int) $revisionRaw,
    'payload_sha256' => $payloadSha,
    'trusted_main' => $mainSha,
    'node_id' => (int) $node->id(),
    'node_uuid' => $node->uuid(),
    'node_revision_id' => (int) $node->getRevisionId(),
    'fid' => (int) $frFid,
    'file_uuid' => $file->uuid(),
    'filename' => $filename,
    'asset_sha256' => $assetSha,
    'mime' => 'image/webp',
    'width' => (int) $info[0],
    'height' => (int) $info[1],
    'bytes' => strlen($bytes),
    'alt' => ['fr' => $altFr, 'en' => $altEn],
  ];
  if (file_put_contents($manifestFile, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n", LOCK_EX) === FALSE) {
    throw new RuntimeException('Could not save metadata-only manifest.');
  }
  chmod($manifestFile, 0600);
}
catch (\Throwable $exception) {
  // Only fixed classes for known reader invariants; never log exception text.
  $failureClass = match ($exception->getMessage()) {
    'Invalid new-Article source identity.',
    'Owner Article payload hash mismatch.',
    'Owner Article payload issue/bundle mismatch.' => 'SOURCE_IDENTITY',
    'PREPROD Article issue/hash mapping is absent or stale.',
    'PREPROD mapped Article/translation is invalid.' => 'ARTICLE_MAPPING',
    'PREPROD Article text/category drifted from owner payload.' => 'ARTICLE_TEXT_DRIFT',
    'PREPROD image File ID must be identical in FR and EN.' => 'IMAGE_FID_MISMATCH',
    'PREPROD File entity is missing or temporary.' => 'FILE_ENTITY',
    'File URI must remain in Drupal articles storage.',
    'File source cannot escape the Drupal articles directory.',
    'File name is not a bounded WebP filename.' => 'FILE_URI',
    'Both FR/EN ALT values must be bounded plain text.' => 'ALT_INVALID',
    'PREPROD WebP bytes are missing or too large.',
    'PREPROD WebP is not a valid bounded decoded image.' => 'WEBP_DECODE',
    'Could not stage verified private source bytes.',
    'Could not save metadata-only manifest.' => 'PRIVATE_STAGING',
    'PREPROD source changed during snapshot.' => 'SOURCE_DRIFT',
    default => 'UNKNOWN',
  };
  fwrite(STDERR, "NATIVE_WEBP_SOURCE=FAIL_CLOSED\n");
  fwrite(STDERR, "NATIVE_WEBP_FAILURE_CLASS={$failureClass}\n");
  exit(1);
}

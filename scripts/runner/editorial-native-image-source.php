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

  // Compare the exact parsed editorial structure, not CKEditor's formatting
  // of whitespace between blocks or its inert list-item bookkeeping attribute.
  $sameBody = static function (string $actual, string $approved): bool {
    if ($actual === $approved) {
      return TRUE;
    }
    $tree = static function (string $html): ?array {
      if (preg_match('/<!--[\s\S]*?-->|<!DOCTYPE|<\?/iu', $html)) {
        return NULL;
      }
      $dom = \Drupal\Component\Utility\Html::load($html);
      $walk = static function (\DOMNode $node) use (&$walk): ?array {
        if ($node instanceof \DOMText) {
          return ['text', $node->nodeValue];
        }
        if (!$node instanceof \DOMElement) {
          return NULL;
        }
        $tag = strtolower($node->tagName);
        $attrs = [];
        foreach ($node->attributes as $attribute) {
          if ($tag === 'li' && $attribute->name === 'data-list-item-id'
            && preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $attribute->value)) {
            continue;
          }
          $attrs[$attribute->name] = $attribute->value;
        }
        ksort($attrs);
        $children = [];
        foreach ($node->childNodes as $child) {
          // Ignore only presentation indentation around block-level tags.
          $left = $child->previousSibling;
          $right = $child->nextSibling;
          $block = ['h2', 'h3', 'p', 'ul', 'ol', 'li'];
          $betweenBlocks = $left instanceof \DOMElement && $right instanceof \DOMElement
            && in_array(strtolower($left->tagName), $block, TRUE)
            && in_array(strtolower($right->tagName), $block, TRUE);
          if ($child instanceof \DOMText && trim($child->nodeValue) === ''
            && preg_match('/[\r\n]/', $child->nodeValue)
            && in_array($tag, ['body', 'ul', 'ol', 'li', 'p', 'h2', 'h3'], TRUE)
            && ($left === NULL || $right === NULL || $betweenBlocks)) {
            continue;
          }
          $part = $walk($child);
          if ($part === NULL) {
            return NULL;
          }
          $children[] = $part;
        }
        return [$tag, $attrs, $children];
      };
      return $walk($dom->getElementsByTagName('body')->item(0));
    };
    $expected = $tree($approved);
    return $expected !== NULL && $expected === $tree($actual);
  };

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
    if ($translation->label() !== ($payload[$lang]['title'] ?? NULL)) {
      throw new RuntimeException($lang === 'fr' ? 'PREPROD Article FR_TITLE drift.' : 'PREPROD Article EN_TITLE drift.');
    }
    if ((string) $translation->get('field_short_description')->value !== ($payload[$lang]['short_description'] ?? NULL)) {
      throw new RuntimeException($lang === 'fr' ? 'PREPROD Article FR_SHORT_DESCRIPTION drift.' : 'PREPROD Article EN_SHORT_DESCRIPTION drift.');
    }
    if (!$sameBody((string) $translation->get('body')->value, (string) ($payload[$lang]['body_html'] ?? ''))) {
      throw new RuntimeException($lang === 'fr' ? 'PREPROD Article FR_BODY_HTML drift.' : 'PREPROD Article EN_BODY_HTML drift.');
    }
    if ((int) $translation->get('field_blog_category')->target_id !== (int) ($payload['category']['tid'] ?? 0)) {
      throw new RuntimeException($lang === 'fr' ? 'PREPROD Article FR_CATEGORY drift.' : 'PREPROD Article EN_CATEGORY drift.');
    }
  }
  $frImage = $node->get('field_feature_image')->first();
  $enImage = $en->get('field_feature_image')->first();
  $frFid = $frImage?->get('target_id')->getValue();
  $enFid = $enImage?->get('target_id')->getValue();
  if ($frFid === NULL || $enFid === NULL || (int) $frFid <= 0 || (int) $enFid <= 0) {
    throw new RuntimeException('PREPROD image File ID must be identical in FR and EN.');
  }
  $file = $manager->getStorage('file')->load((int) $frFid);
  $enFile = $manager->getStorage('file')->load((int) $enFid);
  if (!$file instanceof \Drupal\file\FileInterface || $file->isTemporary()
    || !$enFile instanceof \Drupal\file\FileInterface || $enFile->isTemporary()) {
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
  // When translations reference two File entities, prove both bounded
  // sources are independently valid and byte-identical before retaining the
  // existing canonical FR FID/UUID in the unchanged manifest schema.
  $enUri = $enFile->getFileUri();
  $enFilename = $enFile->getFilename();
  $enReal = is_string($enUri) ? $fileSystem->realpath($enUri) : FALSE;
  if (!is_string($enUri)
    || !preg_match('#^public://articles/([^/\\\\]{1,128}\.webp)$#iu', $enUri, $enParts)
    || !is_string($enReal)
    || !str_starts_with($enReal, rtrim($root, '/') . DIRECTORY_SEPARATOR)
    || !is_string($enFilename) || $enFilename !== $enParts[1]
    || !preg_match('/^[^\x00-\x1f\x7f\/\\\\]{1,128}\.webp$/iu', $enFilename)) {
    throw new RuntimeException('File URI must remain in Drupal articles storage.');
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
  if ($file->getMimeType() !== 'image/webp' || $enFile->getMimeType() !== 'image/webp'
    || (int) $file->getSize() !== strlen($bytes)) {
    throw new RuntimeException('PREPROD WebP is not a valid bounded decoded image.');
  }
  $enBytes = file_get_contents($enUri, FALSE, NULL, 0, 5000001);
  if (!is_string($enBytes) || strlen($enBytes) < 16 || strlen($enBytes) > 5000000
    || (int) $enFile->getSize() !== strlen($enBytes)
    || !hash_equals(hash('sha256', $bytes), hash('sha256', $enBytes))) {
    throw new RuntimeException('PREPROD FR/EN WebP bytes differ.');
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
    || (int) $fresh->getTranslation('en')->get('field_feature_image')->target_id !== (int) $enFid
    || (string) $fresh->get('field_feature_image')->alt !== $altFr
    || (string) $fresh->getTranslation('en')->get('field_feature_image')->alt !== $altEn
    || !hash_equals($assetSha, (string) hash_file('sha256', $uri))
    || !hash_equals($assetSha, (string) hash_file('sha256', $enUri))) {
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
    'PREPROD Article FR_TITLE drift.' => 'ARTICLE_TEXT_DRIFT_FR_TITLE',
    'PREPROD Article FR_SHORT_DESCRIPTION drift.' => 'ARTICLE_TEXT_DRIFT_FR_SHORT_DESCRIPTION',
    'PREPROD Article FR_BODY_HTML drift.' => 'ARTICLE_TEXT_DRIFT_FR_BODY_HTML',
    'PREPROD Article FR_CATEGORY drift.' => 'ARTICLE_TEXT_DRIFT_FR_CATEGORY',
    'PREPROD Article EN_TITLE drift.' => 'ARTICLE_TEXT_DRIFT_EN_TITLE',
    'PREPROD Article EN_SHORT_DESCRIPTION drift.' => 'ARTICLE_TEXT_DRIFT_EN_SHORT_DESCRIPTION',
    'PREPROD Article EN_BODY_HTML drift.' => 'ARTICLE_TEXT_DRIFT_EN_BODY_HTML',
    'PREPROD Article EN_CATEGORY drift.' => 'ARTICLE_TEXT_DRIFT_EN_CATEGORY',
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
    'PREPROD FR/EN WebP bytes differ.' => 'IMAGE_BYTES_MISMATCH',
    'PREPROD source changed during snapshot.' => 'SOURCE_DRIFT',
    default => 'UNKNOWN',
  };
  fwrite(STDERR, "NATIVE_WEBP_SOURCE=FAIL_CLOSED\n");
  fwrite(STDERR, "NATIVE_WEBP_FAILURE_CLASS={$failureClass}\n");
  exit(1);
}

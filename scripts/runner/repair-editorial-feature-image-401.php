<?php

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

const AGENCY_IMAGE_REPAIR_ISSUE = 401;
const AGENCY_IMAGE_REPAIR_PAYLOAD_SHA = '489bf57f89e519862d5a1ae46f2d3335dd213993be0d2cf83b47694cfab22acf';
const AGENCY_IMAGE_REPAIR_OLD_FILENAME = 'issue-401-redesign-checklist-f925e3b41c32.png';
const AGENCY_IMAGE_REPAIR_OLD_SHA = 'f925e3b41c325e9e863d1936d41b18cea5d0b9c064fac7ba6f551741f863fad4';
const AGENCY_IMAGE_REPAIR_NEW_FILENAME = 'issue-401-redesign-checklist-70bf17abe69d.png';
const AGENCY_IMAGE_REPAIR_NEW_SHA = '70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898';
const AGENCY_IMAGE_REPAIR_ALT_FR = 'Checklist de préparation avant la refonte d’un site web';
const AGENCY_IMAGE_REPAIR_ALT_EN = 'Website redesign preparation checklist';
const AGENCY_IMAGE_REPAIR_DIRECTORY = 'public://articles';

$mode = getenv('AGENCY_IMAGE_REPAIR_MODE') ?: '';
$profilePath = getenv('AGENCY_IMAGE_REPAIR_PROFILE_PATH') ?: '';
$assetPath = getenv('AGENCY_IMAGE_REPAIR_ASSET_PATH') ?: '';
$resultPath = getenv('AGENCY_IMAGE_REPAIR_RESULT_PATH') ?: '';

$writeResult = static function (array $result) use ($resultPath): void {
  if ($resultPath === '') {
    throw new RuntimeException('AGENCY_IMAGE_REPAIR_RESULT_PATH is required.');
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
    throw new InvalidArgumentException('Unsupported AGENCY_IMAGE_REPAIR_MODE.');
  }
  if ($profilePath === '' || !is_file($profilePath)) {
    throw new RuntimeException('Trusted editorial image profile is missing.');
  }
  if ($assetPath === '' || !is_file($assetPath) || !is_readable($assetPath)) {
    throw new RuntimeException('Trusted replacement image is missing or unreadable.');
  }

  $registry = json_decode(
    (string) file_get_contents($profilePath),
    TRUE,
    16,
    JSON_THROW_ON_ERROR,
  );
  if (!is_array($registry) || ($registry['schema_version'] ?? NULL) !== 1 || !is_array($registry['profiles'] ?? NULL)) {
    throw new InvalidArgumentException('Trusted editorial image profile registry is invalid.');
  }
  $profile = $registry['profiles'][(string) AGENCY_IMAGE_REPAIR_ISSUE] ?? NULL;
  if (!is_array($profile)) {
    throw new RuntimeException('Exact #401 editorial image profile is missing.');
  }
  $asset = $profile['asset'] ?? NULL;
  $alt = $profile['alt'] ?? NULL;
  if (($profile['issue_number'] ?? NULL) !== AGENCY_IMAGE_REPAIR_ISSUE
    || ($profile['bundle'] ?? NULL) !== 'article'
    || ($profile['field_name'] ?? NULL) !== 'field_feature_image'
    || ($profile['article_payload_sha256'] ?? NULL) !== AGENCY_IMAGE_REPAIR_PAYLOAD_SHA
    || !is_array($asset)
    || ($asset['filename'] ?? NULL) !== AGENCY_IMAGE_REPAIR_NEW_FILENAME
    || ($asset['sha256'] ?? NULL) !== AGENCY_IMAGE_REPAIR_NEW_SHA
    || ($asset['mime'] ?? NULL) !== 'image/png'
    || ($asset['width'] ?? NULL) !== 1200
    || ($asset['height'] ?? NULL) !== 630
    || !is_array($alt)
    || ($alt['fr'] ?? NULL) !== AGENCY_IMAGE_REPAIR_ALT_FR
    || ($alt['en'] ?? NULL) !== AGENCY_IMAGE_REPAIR_ALT_EN) {
    throw new RuntimeException('Repository profile no longer matches the exact #401 source-repair contract.');
  }

  $replacementHash = hash_file('sha256', $assetPath);
  if (!is_string($replacementHash) || !hash_equals(AGENCY_IMAGE_REPAIR_NEW_SHA, $replacementHash)) {
    throw new RuntimeException('Replacement image SHA-256 mismatch.');
  }
  $replacementInfo = getimagesize($assetPath);
  if (!is_array($replacementInfo)
    || ($replacementInfo['mime'] ?? NULL) !== 'image/png'
    || ($replacementInfo[0] ?? NULL) !== 1200
    || ($replacementInfo[1] ?? NULL) !== 630) {
    throw new RuntimeException('Replacement image raster contract mismatch.');
  }
  if (!function_exists('imagecreatefrompng')) {
    throw new RuntimeException('GD PNG decoding is unavailable for replacement validation.');
  }
  $decoded = @imagecreatefrompng($assetPath);
  if (!$decoded instanceof GdImage) {
    throw new RuntimeException('Replacement image is not GD-decodable.');
  }
  if (imagesx($decoded) !== 1200 || imagesy($decoded) !== 630) {
    imagedestroy($decoded);
    throw new RuntimeException('GD-decoded replacement dimensions mismatch.');
  }
  imagedestroy($decoded);

  $container = \Drupal::getContainer();
  $entityTypeManager = $container->get('entity_type.manager');
  $state = $container->get('state');
  $mapping = $state->get('agency_editorial.issue.' . AGENCY_IMAGE_REPAIR_ISSUE);
  if (!is_array($mapping)
    || !is_int($mapping['node_id'] ?? NULL)
    || ($mapping['payload_sha256'] ?? NULL) !== AGENCY_IMAGE_REPAIR_PAYLOAD_SHA) {
    throw new RuntimeException('Editorial #401 mapping is missing or differs from the reviewed payload.');
  }

  $node = $entityTypeManager->getStorage('node')->load($mapping['node_id']);
  if (!$node instanceof NodeInterface || $node->bundle() !== 'article') {
    throw new RuntimeException('Editorial #401 mapping does not resolve to an Article.');
  }
  if (!$node->hasField('field_feature_image') || !$node->hasTranslation('en')) {
    throw new RuntimeException('Editorial #401 Article image/translation contract is incomplete.');
  }

  $classify = static function (NodeInterface $candidate) use ($entityTypeManager): array {
    $frItem = $candidate->get('field_feature_image')->first();
    $enItem = $candidate->getTranslation('en')->get('field_feature_image')->first();
    $frFid = $frItem?->get('target_id')->getValue();
    $enFid = $enItem?->get('target_id')->getValue();
    if ($frFid === NULL || $enFid === NULL || (int) $frFid !== (int) $enFid) {
      throw new RuntimeException('FR/EN feature image references are not the same exact File entity.');
    }
    $file = $entityTypeManager->getStorage('file')->load((int) $frFid);
    if (!$file instanceof FileInterface) {
      throw new RuntimeException('Current #401 feature image File entity is missing.');
    }
    $uri = $file->getFileUri();
    $hash = hash_file('sha256', $uri);
    if (!is_string($hash)) {
      throw new RuntimeException('Current #401 feature image bytes are unreadable.');
    }
    $altFr = trim((string) ($frItem?->get('alt')->getValue() ?? ''));
    $altEn = trim((string) ($enItem?->get('alt')->getValue() ?? ''));

    $newUri = AGENCY_IMAGE_REPAIR_DIRECTORY . '/' . AGENCY_IMAGE_REPAIR_NEW_FILENAME;
    if ($uri === $newUri && hash_equals(AGENCY_IMAGE_REPAIR_NEW_SHA, $hash)) {
      if (!hash_equals(AGENCY_IMAGE_REPAIR_ALT_FR, $altFr) || !hash_equals(AGENCY_IMAGE_REPAIR_ALT_EN, $altEn)) {
        throw new RuntimeException('Replacement asset is attached but translated ALT values are not exact.');
      }
      return [
        'verdict' => 'IDEMPOTENT',
        'fid' => (int) $file->id(),
        'uri' => $uri,
        'sha256' => $hash,
        'alt_fr' => $altFr,
        'alt_en' => $altEn,
      ];
    }

    $oldUri = AGENCY_IMAGE_REPAIR_DIRECTORY . '/' . AGENCY_IMAGE_REPAIR_OLD_FILENAME;
    if ($uri !== $oldUri || !hash_equals(AGENCY_IMAGE_REPAIR_OLD_SHA, $hash)) {
      throw new RuntimeException('Current feature image is neither the exact corrupt legacy asset nor the exact replacement.');
    }
    if (!hash_equals(AGENCY_IMAGE_REPAIR_ALT_FR, $altFr) || !hash_equals(AGENCY_IMAGE_REPAIR_ALT_EN, $altEn)) {
      throw new RuntimeException('Legacy #401 asset ALT values differ from the reviewed state; refusing compound repair.');
    }

    return [
      'verdict' => 'REPLACE_REQUIRED',
      'fid' => (int) $file->id(),
      'uri' => $uri,
      'sha256' => $hash,
      'alt_fr' => $altFr,
      'alt_en' => $altEn,
    ];
  };

  $before = $classify($node);
  if ($mode === 'dry-run' || $before['verdict'] === 'IDEMPOTENT') {
    $writeResult([
      'status' => 'PASS',
      'verdict' => $before['verdict'],
      'mode' => $mode,
      'issue_number' => AGENCY_IMAGE_REPAIR_ISSUE,
      'node' => [
        'id' => (int) $node->id(),
        'revision_id' => (int) $node->getRevisionId(),
        'image' => $before,
      ],
      'replacement_sha256' => AGENCY_IMAGE_REPAIR_NEW_SHA,
    ]);
    return;
  }

  $author = $entityTypeManager->getStorage('user')->load(1);
  if (!$author instanceof UserInterface || !$author->isActive()) {
    throw new RuntimeException('Required Drupal author uid=1 is missing or blocked.');
  }
  $fileSystem = $container->get('file_system');
  if (!$fileSystem instanceof FileSystemInterface || !$fileSystem->prepareDirectory(
    AGENCY_IMAGE_REPAIR_DIRECTORY,
    FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
  )) {
    throw new RuntimeException('Governed Article image directory is not writable.');
  }

  $destination = AGENCY_IMAGE_REPAIR_DIRECTORY . '/' . AGENCY_IMAGE_REPAIR_NEW_FILENAME;
  $fileStorage = $entityTypeManager->getStorage('file');
  $existing = $fileStorage->loadByProperties(['uri' => $destination]);
  if (count($existing) > 1) {
    throw new RuntimeException('Multiple File entities already use the replacement destination URI.');
  }
  $replacementFile = NULL;
  if ($existing !== []) {
    $replacementFile = reset($existing);
    if (!$replacementFile instanceof FileInterface) {
      throw new RuntimeException('Replacement destination did not resolve to a File entity.');
    }
    $existingHash = hash_file('sha256', $replacementFile->getFileUri());
    if (!is_string($existingHash) || !hash_equals(AGENCY_IMAGE_REPAIR_NEW_SHA, $existingHash)) {
      throw new RuntimeException('Replacement destination already contains different bytes.');
    }
  }
  elseif (file_exists($destination)) {
    $existingHash = hash_file('sha256', $destination);
    if (!is_string($existingHash) || !hash_equals(AGENCY_IMAGE_REPAIR_NEW_SHA, $existingHash)) {
      throw new RuntimeException('Replacement destination exists without File entity and has different bytes.');
    }
    $replacementFile = $fileStorage->create([
      'uri' => $destination,
      'filename' => AGENCY_IMAGE_REPAIR_NEW_FILENAME,
      'status' => 1,
      'uid' => 1,
    ]);
    if (!$replacementFile instanceof FileInterface) {
      throw new RuntimeException('Could not create File entity for exact replacement bytes.');
    }
  }
  else {
    $bytes = file_get_contents($assetPath);
    if (!is_string($bytes) || $bytes === '') {
      throw new RuntimeException('Could not read exact replacement bytes.');
    }
    $replacementFile = $container->get('file.repository')->writeData($bytes, $destination, FileExists::Error);
  }

  $replacementFile->setPermanent();
  $replacementFile->setOwnerId(1);
  $replacementFile->save();
  $newFid = (int) $replacementFile->id();
  if ($newFid <= 0) {
    throw new RuntimeException('Replacement image did not receive a valid FID.');
  }

  $oldRevision = (int) $node->getRevisionId();
  $accountSwitcher = $container->get('account_switcher');
  $accountSwitcher->switchTo($author);
  try {
    $node->set('field_feature_image', [[
      'target_id' => $newFid,
      'alt' => AGENCY_IMAGE_REPAIR_ALT_FR,
    ]]);
    $node->getTranslation('en')->set('field_feature_image', [[
      'target_id' => $newFid,
      'alt' => AGENCY_IMAGE_REPAIR_ALT_EN,
    ]]);
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId(1);
    $node->setRevisionLogMessage(
      'Agency issue #596 replaces corrupt #401 image source f925e3b41c32 with 70bf17abe69d',
    );
    $violations = $node->validate();
    if ($violations->count() > 0) {
      $messages = [];
      foreach ($violations as $violation) {
        $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
      }
      throw new RuntimeException('Replacement Article validation failed: ' . implode(' | ', $messages));
    }
    $node->save();
  }
  finally {
    $accountSwitcher->switchBack();
  }

  $nodeId = (int) $node->id();
  $entityTypeManager->getStorage('node')->resetCache([$nodeId]);
  $reloaded = $entityTypeManager->getStorage('node')->load($nodeId);
  if (!$reloaded instanceof NodeInterface || (int) $reloaded->getRevisionId() <= $oldRevision) {
    throw new RuntimeException('Replacement did not create the required Article revision.');
  }
  $after = $classify($reloaded);
  if ($after['verdict'] !== 'IDEMPOTENT') {
    throw new RuntimeException('Replacement did not converge to the exact governed image state.');
  }

  $writeResult([
    'status' => 'PASS',
    'verdict' => 'REPLACED',
    'mode' => 'apply',
    'issue_number' => AGENCY_IMAGE_REPAIR_ISSUE,
    'legacy' => $before,
    'node' => [
      'id' => $nodeId,
      'revision_id' => (int) $reloaded->getRevisionId(),
      'image' => $after,
    ],
    'replacement_sha256' => AGENCY_IMAGE_REPAIR_NEW_SHA,
  ]);
}
catch (Throwable $exception) {
  $writeResult([
    'status' => 'FAIL',
    'verdict' => 'FAIL_CLOSED',
    'mode' => $mode,
    'issue_number' => AGENCY_IMAGE_REPAIR_ISSUE,
    'message' => $exception->getMessage(),
  ]);
  exit(1);
}

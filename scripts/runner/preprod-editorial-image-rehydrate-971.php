<?php

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Rehydrates only the proven missing #401 source image in PREPROD.
 */
final class AgencyPreprodEditorialImageRehydrate971 {

  private const ISSUE_NUMBER = 971;
  private const PROFILE_ISSUE = 401;
  private const NODE_ID = 37;
  private const FID = 2;
  private const FIELD_NAME = 'field_feature_image';
  private const EXPECTED_URI = 'public://articles/issue-401-redesign-checklist-70bf17abe69d.png';
  private const EXPECTED_FILENAME = 'issue-401-redesign-checklist-70bf17abe69d.png';
  private const EXPECTED_ASSET_SHA256 = '70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898';
  private const EXPECTED_PAYLOAD_SHA256 = '489bf57f89e519862d5a1ae46f2d3335dd213993be0d2cf83b47694cfab22acf';
  private const EXPECTED_ALT_FR = 'Checklist de préparation avant la refonte d’un site web';
  private const EXPECTED_ALT_EN = 'Website redesign preparation checklist';
  private const REMOTE_ASSET_PATH = '/tmp/agency-preprod-image-rehydrate-971-asset.png';

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Executes dry-run or apply against the single fixed target.
   *
   * @return array<string, mixed>
   *   Bounded evidence.
   */
  public function execute(string $mode, array $registry): array {
    if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
      throw new InvalidArgumentException('Unsupported #971 rehydration mode.');
    }

    $profile = $this->validateProfile($registry);
    $before = $this->captureState();
    $physical = $this->classifyPhysicalState();

    if ($mode === 'dry-run') {
      return $this->result(
        'dry-run',
        $physical === 'MISSING' ? 'READY_TO_REHYDRATE' : 'IDEMPOTENT',
        $before,
      );
    }

    if ($physical === 'PRESENT_EXACT') {
      return $this->result('apply', 'IDEMPOTENT', $before);
    }
    if ($physical !== 'MISSING') {
      throw new RuntimeException('Unexpected #971 physical state.');
    }

    $asset = self::REMOTE_ASSET_PATH;
    if (!is_file($asset) || !is_readable($asset)) {
      throw new RuntimeException('The fixed #971 transient repository asset is unavailable.');
    }
    $assetHash = hash_file('sha256', $asset);
    if (!is_string($assetHash) || !hash_equals(self::EXPECTED_ASSET_SHA256, $assetHash)) {
      throw new RuntimeException('The fixed #971 transient asset hash is invalid.');
    }
    $info = getimagesize($asset);
    if (!is_array($info)
      || ($info['mime'] ?? '') !== 'image/png'
      || ($info[0] ?? 0) !== 1200
      || ($info[1] ?? 0) !== 630) {
      throw new RuntimeException('The fixed #971 transient asset metadata is invalid.');
    }

    $bytes = file_get_contents($asset);
    if (!is_string($bytes) || $bytes === '') {
      throw new RuntimeException('The fixed #971 transient asset bytes are unavailable.');
    }

    $saved = $this->fileSystem->saveData($bytes, self::EXPECTED_URI, FileExists::Error);
    if ($saved !== self::EXPECTED_URI) {
      throw new RuntimeException('Drupal File API did not materialize the exact #971 URI.');
    }

    $afterPhysical = $this->classifyPhysicalState();
    if ($afterPhysical !== 'PRESENT_EXACT') {
      throw new RuntimeException('The #971 source file did not converge to the exact hash.');
    }

    $after = $this->captureState();
    if ($after !== $before) {
      throw new RuntimeException('Node/File metadata changed during #971 source-file rehydration.');
    }

    return $this->result('apply', 'REHYDRATED', $after);
  }

  /**
   * Validates the existing #584 profile without introducing a new registry.
   *
   * @return array<string, mixed>
   *   The exact #401 profile.
   */
  private function validateProfile(array $registry): array {
    if (($registry['schema_version'] ?? NULL) !== 1 || !is_array($registry['profiles'] ?? NULL)) {
      throw new RuntimeException('Editorial feature-image registry schema is invalid.');
    }
    $profile = $registry['profiles'][(string) self::PROFILE_ISSUE] ?? NULL;
    if (!is_array($profile)) {
      throw new RuntimeException('Existing #584 profile #401 is missing.');
    }
    if (($profile['issue_number'] ?? NULL) !== self::PROFILE_ISSUE
      || ($profile['bundle'] ?? NULL) !== 'article'
      || ($profile['field_name'] ?? NULL) !== self::FIELD_NAME
      || ($profile['article_payload_sha256'] ?? NULL) !== self::EXPECTED_PAYLOAD_SHA256
      || ($profile['asset']['path'] ?? NULL) !== 'assets/editorial/issue-401-redesign-checklist.png'
      || ($profile['asset']['filename'] ?? NULL) !== self::EXPECTED_FILENAME
      || ($profile['asset']['sha256'] ?? NULL) !== self::EXPECTED_ASSET_SHA256
      || ($profile['asset']['mime'] ?? NULL) !== 'image/png'
      || ($profile['asset']['width'] ?? NULL) !== 1200
      || ($profile['asset']['height'] ?? NULL) !== 630
      || ($profile['alt']['fr'] ?? NULL) !== self::EXPECTED_ALT_FR
      || ($profile['alt']['en'] ?? NULL) !== self::EXPECTED_ALT_EN) {
      throw new RuntimeException('Existing #584 profile #401 no longer matches the #971 contract.');
    }
    return $profile;
  }

  /**
   * Captures every database-backed binding that must remain unchanged.
   *
   * @return array<string, mixed>
   *   Exact immutable state.
   */
  private function captureState(): array {
    $state = \Drupal::state()->get('agency_editorial.issue.401');
    if (!is_array($state)
      || ($state['node_id'] ?? NULL) !== self::NODE_ID
      || ($state['payload_sha256'] ?? NULL) !== self::EXPECTED_PAYLOAD_SHA256) {
      throw new RuntimeException('PREPROD editorial mapping no longer matches the #971 precondition.');
    }

    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $node = $storage->load(self::NODE_ID);
    if (!$node instanceof NodeInterface
      || $node->bundle() !== 'article'
      || !$node->hasField(self::FIELD_NAME)
      || !$node->hasTranslation('en')) {
      throw new RuntimeException('PREPROD node 37 no longer matches the #971 Article contract.');
    }

    $fr = $node->get(self::FIELD_NAME)->first();
    $en = $node->getTranslation('en')->get(self::FIELD_NAME)->first();
    $frFid = $fr?->get('target_id')->getValue();
    $enFid = $en?->get('target_id')->getValue();
    $altFr = (string) ($fr?->get('alt')->getValue() ?? '');
    $altEn = (string) ($en?->get('alt')->getValue() ?? '');
    if ((int) $frFid !== self::FID
      || (int) $enFid !== self::FID
      || !hash_equals(self::EXPECTED_ALT_FR, $altFr)
      || !hash_equals(self::EXPECTED_ALT_EN, $altEn)) {
      throw new RuntimeException('PREPROD node 37 image binding or ALT no longer matches #971.');
    }

    $file = \Drupal::entityTypeManager()->getStorage('file')->load(self::FID);
    if (!$file instanceof FileInterface || $file->getFileUri() !== self::EXPECTED_URI) {
      throw new RuntimeException('PREPROD fid 2 no longer points to the exact #971 URI.');
    }

    return [
      'node_id' => (int) $node->id(),
      'node_revision_id' => (int) $node->getRevisionId(),
      'fid' => (int) $file->id(),
      'uri' => $file->getFileUri(),
      'alt_fr' => $altFr,
      'alt_en' => $altEn,
      'article_payload_sha256' => self::EXPECTED_PAYLOAD_SHA256,
    ];
  }

  /**
   * Classifies the one fixed source path without generating derivatives.
   */
  private function classifyPhysicalState(): string {
    $directory = $this->fileSystem->realpath('public://articles');
    if (!is_string($directory) || $directory === '' || !is_dir($directory)) {
      throw new RuntimeException('Existing public://articles directory cannot be resolved safely.');
    }
    $destination = $directory . DIRECTORY_SEPARATOR . self::EXPECTED_FILENAME;
    if (!is_file($destination)) {
      return 'MISSING';
    }
    $hash = hash_file('sha256', $destination);
    if (!is_string($hash) || !hash_equals(self::EXPECTED_ASSET_SHA256, $hash)) {
      throw new RuntimeException('The fixed #971 destination exists with unexpected bytes.');
    }
    return 'PRESENT_EXACT';
  }

  /**
   * Builds the metadata-only receipt.
   *
   * @param array<string, mixed> $state
   *   Immutable Drupal binding evidence.
   *
   * @return array<string, mixed>
   *   Result receipt.
   */
  private function result(string $mode, string $verdict, array $state): array {
    return [
      'schema_version' => 1,
      'status' => 'PASS',
      'target' => 'PREPROD',
      'issue_number' => self::ISSUE_NUMBER,
      'profile_issue' => self::PROFILE_ISSUE,
      'mode' => $mode,
      'verdict' => $verdict,
      'source_asset' => 'assets/editorial/issue-401-redesign-checklist.png',
      'source_sha256' => self::EXPECTED_ASSET_SHA256,
      'node' => $state,
      'derivatives_generated' => FALSE,
      'prod_access' => 'NONE',
      'prod_write' => 'NONE',
    ];
  }

}

$mode = getenv('AGENCY_PREPROD_IMAGE_REHYDRATE_MODE');
$registryB64 = getenv('AGENCY_PREPROD_IMAGE_REHYDRATE_REGISTRY_B64');
if (!is_string($mode) || !is_string($registryB64) || $registryB64 === '') {
  throw new RuntimeException('Required #971 runtime inputs are unavailable.');
}
$registryJson = base64_decode($registryB64, TRUE);
if (!is_string($registryJson)) {
  throw new RuntimeException('The #971 registry transport is invalid.');
}
$registry = json_decode($registryJson, TRUE, 32, JSON_THROW_ON_ERROR);
if (!is_array($registry)) {
  throw new RuntimeException('The #971 registry must decode to an object.');
}

$helper = new AgencyPreprodEditorialImageRehydrate971(\Drupal::service('file_system'));
$result = $helper->execute($mode, $registry);
print(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

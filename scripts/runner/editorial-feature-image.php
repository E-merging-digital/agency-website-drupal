<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bounded helper for a governed editor-owned Article feature image.
 */
final class AgencyEditorialFeatureImage {

  private const BUNDLE = 'article';
  private const FIELD_NAME = 'field_feature_image';
  private const SOURCE_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const AUTHOR_UID = 1;
  private const STATE_PREFIX = 'agency_editorial.issue.';
  private const DESTINATION_DIRECTORY = 'public://articles';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * Builds the helper from Drupal public services.
   */
  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('file.repository'),
      $container->get('account_switcher'),
    );
  }

  /**
   * Read-only inspection of the exact profile/asset/article state.
   *
   * @return array<string, mixed>
   *   Structured evidence.
   */
  public function inspect(array $profile, int $issueNumber, string $assetPath): array {
    $profile = $this->validateProfile($profile, $issueNumber);
    $asset = $this->validateAsset($profile, $assetPath);
    $node = $this->loadMappedNode($profile, $issueNumber);
    $classification = $this->classifyCurrentImage($node, $profile);

    return [
      'status' => 'PASS',
      'verdict' => $classification['verdict'],
      'mode' => 'inspect',
      'issue_number' => $issueNumber,
      'article_payload_sha256' => $profile['article_payload_sha256'],
      'asset' => $asset,
      'node' => $this->nodeResult($node, $classification),
    ];
  }

  /**
   * Performs the same validation as apply without saving anything.
   *
   * @return array<string, mixed>
   *   Structured evidence.
   */
  public function dryRun(array $profile, int $issueNumber, string $assetPath): array {
    $result = $this->inspect($profile, $issueNumber, $assetPath);
    $result['mode'] = 'dry-run';
    return $result;
  }

  /**
   * Attaches the exact profile-owned image or repairs only its ALT values.
   *
   * @return array<string, mixed>
   *   Structured evidence.
   */
  public function apply(array $profile, int $issueNumber, string $assetPath): array {
    $before = $this->dryRun($profile, $issueNumber, $assetPath);
    if ($before['verdict'] === 'IDEMPOTENT') {
      $before['mode'] = 'apply';
      return $before;
    }
    if (!in_array($before['verdict'], ['READY', 'REPAIR_REQUIRED'], TRUE)) {
      throw new RuntimeException('Feature image apply requires READY or REPAIR_REQUIRED.');
    }

    $profile = $this->validateProfile($profile, $issueNumber);
    $node = $this->loadMappedNode($profile, $issueNumber);
    $author = $this->loadAuthor();
    $oldRevisionId = (int) $node->getRevisionId();
    $classification = $this->classifyCurrentImage($node, $profile);

    $this->accountSwitcher->switchTo($author);
    try {
      $file = $classification['file'] ?? NULL;
      if (!$file instanceof FileInterface) {
        $file = $this->materializeFile($profile, $assetPath);
      }
      $fid = (int) $file->id();
      if ($fid <= 0) {
        throw new RuntimeException('Feature image file did not receive a valid FID.');
      }

      $node->set(self::FIELD_NAME, [[
        'target_id' => $fid,
        'alt' => $profile['alt']['fr'],
      ]]);
      $translation = $node->getTranslation(self::TRANSLATION_LANGCODE);
      $translation->set(self::FIELD_NAME, [[
        'target_id' => $fid,
        'alt' => $profile['alt']['en'],
      ]]);

      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(self::AUTHOR_UID);
      $node->setRevisionLogMessage(sprintf(
        'Agency editorial feature image issue #%d asset %s',
        $issueNumber,
        $profile['asset']['sha256'],
      ));

      $violations = $node->validate();
      if ($violations->count() > 0) {
        $messages = [];
        foreach ($violations as $violation) {
          $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }
        throw new RuntimeException('Feature image Article validation failed: ' . implode(' | ', $messages));
      }

      $node->save();
      $nodeId = (int) $node->id();
      $this->entityTypeManager->getStorage('node')->resetCache([$nodeId]);
      $reloaded = $this->loadMappedNode($profile, $issueNumber);
      if ((int) $reloaded->getRevisionId() <= $oldRevisionId) {
        throw new RuntimeException('Feature image mutation did not create the required Article revision.');
      }

      $after = $this->classifyCurrentImage($reloaded, $profile);
      if ($after['verdict'] !== 'IDEMPOTENT') {
        throw new RuntimeException('Feature image mutation did not converge to the exact governed state.');
      }

      return [
        'status' => 'PASS',
        'verdict' => $before['verdict'] === 'READY' ? 'APPLIED' : 'REPAIRED',
        'mode' => 'apply',
        'issue_number' => $issueNumber,
        'article_payload_sha256' => $profile['article_payload_sha256'],
        'asset' => $this->validateAsset($profile, $assetPath),
        'node' => $this->nodeResult($reloaded, $after),
      ];
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Validates the repository-owned closed profile.
   *
   * @return array<string, mixed>
   *   The validated profile.
   */
  public function validateProfile(array $profile, int $issueNumber): array {
    $this->assertExactKeys(
      $profile,
      ['issue_number', 'bundle', 'article_payload_sha256', 'field_name', 'asset', 'alt'],
      'profile',
    );
    if (($profile['issue_number'] ?? NULL) !== $issueNumber) {
      throw new InvalidArgumentException('Feature image profile issue_number mismatch.');
    }
    if (($profile['bundle'] ?? NULL) !== self::BUNDLE) {
      throw new InvalidArgumentException('Feature image route is Article-only.');
    }
    if (($profile['field_name'] ?? NULL) !== self::FIELD_NAME) {
      throw new InvalidArgumentException('Feature image route may mutate only field_feature_image.');
    }
    if (!is_string($profile['article_payload_sha256'] ?? NULL)
      || !preg_match('/^[0-9a-f]{64}$/', $profile['article_payload_sha256'])) {
      throw new InvalidArgumentException('article_payload_sha256 must be lowercase SHA-256.');
    }
    if (!is_array($profile['asset'] ?? NULL)) {
      throw new InvalidArgumentException('asset must be an object.');
    }
    $this->assertExactKeys(
      $profile['asset'],
      ['path', 'filename', 'sha256', 'mime', 'width', 'height', 'max_bytes'],
      'asset',
    );
    $asset = $profile['asset'];
    if (!is_string($asset['path'])
      || !preg_match('#^assets/editorial/[a-z0-9][a-z0-9._-]*\.png$#', $asset['path'])) {
      throw new InvalidArgumentException('Asset path must be a bounded repository PNG path.');
    }
    if (!is_string($asset['filename'])
      || !preg_match('/^[a-z0-9][a-z0-9._-]*\.png$/', $asset['filename'])) {
      throw new InvalidArgumentException('Asset filename must be a bounded PNG filename.');
    }
    if (!is_string($asset['sha256']) || !preg_match('/^[0-9a-f]{64}$/', $asset['sha256'])) {
      throw new InvalidArgumentException('Asset sha256 must be lowercase SHA-256.');
    }
    if (($asset['mime'] ?? NULL) !== 'image/png') {
      throw new InvalidArgumentException('Feature image v1 accepts only image/png.');
    }
    foreach (['width', 'height', 'max_bytes'] as $key) {
      if (!is_int($asset[$key] ?? NULL) || $asset[$key] <= 0) {
        throw new InvalidArgumentException("asset.$key must be a positive integer.");
      }
    }
    if ($asset['width'] > 2400 || $asset['height'] > 1600 || $asset['max_bytes'] > 5000000) {
      throw new InvalidArgumentException('Feature image profile exceeds the bounded image limits.');
    }

    if (!is_array($profile['alt'] ?? NULL)) {
      throw new InvalidArgumentException('alt must be an object.');
    }
    $this->assertExactKeys($profile['alt'], ['fr', 'en'], 'alt');
    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      $this->assertPlainTextAlt($profile['alt'][$langcode] ?? NULL, "alt.$langcode");
    }

    return $profile;
  }

  /**
   * Validates the exact asset bytes supplied by trusted main.
   *
   * @return array<string, mixed>
   *   Safe asset metadata.
   */
  private function validateAsset(array $profile, string $assetPath): array {
    if ($assetPath === '' || !is_file($assetPath) || !is_readable($assetPath)) {
      throw new RuntimeException('Trusted feature image asset is missing or unreadable.');
    }
    $size = filesize($assetPath);
    if (!is_int($size) || $size <= 0 || $size > $profile['asset']['max_bytes']) {
      throw new RuntimeException('Trusted feature image asset size is outside the profile limit.');
    }
    $hash = hash_file('sha256', $assetPath);
    if (!is_string($hash) || !hash_equals($profile['asset']['sha256'], $hash)) {
      throw new RuntimeException('Trusted feature image asset hash mismatch.');
    }
    $info = getimagesize($assetPath);
    if (!is_array($info)) {
      throw new RuntimeException('Trusted feature image is not a readable raster image.');
    }
    $mime = $info['mime'] ?? '';
    if ($mime !== $profile['asset']['mime']) {
      throw new RuntimeException('Trusted feature image MIME mismatch.');
    }
    if (($info[0] ?? 0) !== $profile['asset']['width'] || ($info[1] ?? 0) !== $profile['asset']['height']) {
      throw new RuntimeException('Trusted feature image dimensions mismatch.');
    }

    return [
      'sha256' => $hash,
      'mime' => $mime,
      'width' => (int) $info[0],
      'height' => (int) $info[1],
      'bytes' => $size,
      'filename' => $profile['asset']['filename'],
    ];
  }

  /**
   * Loads the Article bound to the exact issue and original payload hash.
   */
  private function loadMappedNode(array $profile, int $issueNumber): NodeInterface {
    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if (!is_array($mapping)) {
      throw new RuntimeException('Feature image route requires an existing editorial issue mapping.');
    }
    $nodeId = $mapping['node_id'] ?? NULL;
    $payloadSha = $mapping['payload_sha256'] ?? NULL;
    if (!is_int($nodeId) || !is_string($payloadSha)) {
      throw new RuntimeException('Editorial issue mapping is incomplete.');
    }
    if (!hash_equals($profile['article_payload_sha256'], $payloadSha)) {
      throw new RuntimeException('Feature image profile does not match the mapped Article payload hash.');
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      throw new RuntimeException('Editorial issue mapping points to an invalid Article.');
    }
    if (!$node->hasField(self::FIELD_NAME)) {
      throw new RuntimeException('Article is missing field_feature_image.');
    }
    if (!$node->hasTranslation(self::TRANSLATION_LANGCODE)) {
      throw new RuntimeException('Feature image route requires the EN translation.');
    }
    return $node;
  }

  /**
   * Classifies current image state without mutation.
   *
   * @return array<string, mixed>
   *   Classification plus existing File entity when applicable.
   */
  private function classifyCurrentImage(NodeInterface $node, array $profile): array {
    $frItem = $node->get(self::FIELD_NAME)->first();
    $enItem = $node->getTranslation(self::TRANSLATION_LANGCODE)
      ->get(self::FIELD_NAME)
      ->first();
    $frFid = $frItem?->get('target_id')->getValue();
    $enFid = $enItem?->get('target_id')->getValue();

    if ($frFid === NULL && $enFid === NULL) {
      return [
        'verdict' => 'READY',
        'file' => NULL,
        'fid' => NULL,
        'uri' => NULL,
        'sha256' => NULL,
        'alt_fr' => '',
        'alt_en' => '',
      ];
    }
    if ($frFid === NULL || $enFid === NULL || (int) $frFid !== (int) $enFid) {
      throw new RuntimeException('FR/EN feature image file references diverge; refusing ambiguous repair.');
    }

    $file = $this->entityTypeManager->getStorage('file')->load((int) $frFid);
    if (!$file instanceof FileInterface) {
      throw new RuntimeException('Article feature image points to a missing File entity.');
    }
    $uri = $file->getFileUri();
    $hash = hash_file('sha256', $uri);
    if (!is_string($hash) || !hash_equals($profile['asset']['sha256'], $hash)) {
      throw new RuntimeException('Article already has a different feature image asset.');
    }

    $altFr = trim((string) ($frItem?->get('alt')->getValue() ?? ''));
    $altEn = trim((string) ($enItem?->get('alt')->getValue() ?? ''));
    $altsMatch = hash_equals($profile['alt']['fr'], $altFr)
      && hash_equals($profile['alt']['en'], $altEn);

    return [
      'verdict' => $altsMatch ? 'IDEMPOTENT' : 'REPAIR_REQUIRED',
      'file' => $file,
      'fid' => (int) $file->id(),
      'uri' => $uri,
      'sha256' => $hash,
      'alt_fr' => $altFr,
      'alt_en' => $altEn,
    ];
  }

  /**
   * Creates or reuses the deterministic File entity for the exact asset.
   */
  private function materializeFile(array $profile, string $assetPath): FileInterface {
    $destination = self::DESTINATION_DIRECTORY . '/' . $profile['asset']['filename'];
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $existing = $fileStorage->loadByProperties(['uri' => $destination]);
    if (count($existing) > 1) {
      throw new RuntimeException('Multiple File entities already use the governed destination URI.');
    }
    if ($existing !== []) {
      $file = reset($existing);
      if (!$file instanceof FileInterface) {
        throw new RuntimeException('Governed destination did not resolve to a File entity.');
      }
      $hash = hash_file('sha256', $file->getFileUri());
      if (!is_string($hash) || !hash_equals($profile['asset']['sha256'], $hash)) {
        throw new RuntimeException('Governed destination already contains different bytes.');
      }
      if ($file->isTemporary()) {
        $file->setPermanent();
        $file->save();
      }
      return $file;
    }
    if (file_exists($destination)) {
      $hash = hash_file('sha256', $destination);
      if (!is_string($hash) || !hash_equals($profile['asset']['sha256'], $hash)) {
        throw new RuntimeException('Governed destination exists without File entity and has different bytes.');
      }
      $file = $fileStorage->create([
        'uri' => $destination,
        'filename' => $profile['asset']['filename'],
        'status' => 1,
        'uid' => self::AUTHOR_UID,
      ]);
      if (!$file instanceof FileInterface) {
        throw new RuntimeException('Could not create File entity for existing governed asset bytes.');
      }
      $file->setPermanent();
      $file->save();
      return $file;
    }

    $bytes = file_get_contents($assetPath);
    if (!is_string($bytes) || $bytes === '') {
      throw new RuntimeException('Could not read trusted feature image bytes.');
    }
    $file = $this->fileRepository->writeData($bytes, $destination, FileExists::Error);
    $file->setPermanent();
    $file->setOwnerId(self::AUTHOR_UID);
    $file->save();
    return $file;
  }

  /**
   * Loads the fixed technical author.
   */
  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface || !$author->isActive()) {
      throw new RuntimeException('Required Drupal author uid=1 is missing or blocked.');
    }
    return $author;
  }

  /**
   * Builds bounded node/image evidence.
   *
   * @return array<string, mixed>
   *   Evidence safe for artifact/comment projection.
   */
  private function nodeResult(NodeInterface $node, array $classification): array {
    return [
      'id' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'revision_id' => (int) $node->getRevisionId(),
      'image' => [
        'fid' => $classification['fid'],
        'uri' => $classification['uri'],
        'sha256' => $classification['sha256'],
        'alt_fr' => $classification['alt_fr'],
        'alt_en' => $classification['alt_en'],
      ],
    ];
  }

  /**
   * Enforces exact object keys.
   */
  private function assertExactKeys(array $value, array $expected, string $label): void {
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
      throw new InvalidArgumentException($label . ' keys must be exactly: ' . implode(', ', $expected));
    }
  }

  /**
   * Enforces bounded plain-text ALT values.
   */
  private function assertPlainTextAlt(mixed $value, string $label): void {
    if (!is_string($value)) {
      throw new InvalidArgumentException("$label must be text.");
    }
    $trimmed = trim($value);
    if ($trimmed === '' || mb_strlen($trimmed) > 300) {
      throw new InvalidArgumentException("$label must contain 1 to 300 characters.");
    }
    if (strip_tags($trimmed) !== $trimmed || preg_match('/[\r\n\x00-\x08\x0B\x0C\x0E-\x1F]/u', $trimmed)) {
      throw new InvalidArgumentException("$label must be single-line plain text.");
    }
  }

}

if (!defined('AGENCY_EDITORIAL_FEATURE_IMAGE_LIBRARY_ONLY')) {
  $mode = getenv('AGENCY_EDITORIAL_IMAGE_MODE') ?: '';
  $issueRaw = getenv('AGENCY_EDITORIAL_IMAGE_ISSUE') ?: '';
  $profilePath = getenv('AGENCY_EDITORIAL_IMAGE_PROFILE_PATH') ?: '';
  $assetPath = getenv('AGENCY_EDITORIAL_IMAGE_ASSET_PATH') ?: '';
  $resultPath = getenv('AGENCY_EDITORIAL_IMAGE_RESULT_PATH') ?: '';

  $writeResult = static function (array $result) use ($resultPath): void {
    if ($resultPath === '') {
      throw new RuntimeException('AGENCY_EDITORIAL_IMAGE_RESULT_PATH is required.');
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
    if (!preg_match('/^[1-9][0-9]*$/', $issueRaw)) {
      throw new InvalidArgumentException('AGENCY_EDITORIAL_IMAGE_ISSUE must be positive numeric.');
    }
    if (!in_array($mode, ['inspect', 'dry-run', 'apply'], TRUE)) {
      throw new InvalidArgumentException('Unsupported AGENCY_EDITORIAL_IMAGE_MODE.');
    }
    if ($profilePath === '' || !is_file($profilePath)) {
      throw new RuntimeException('Trusted feature image profile is missing.');
    }
    $config = json_decode((string) file_get_contents($profilePath), TRUE, 16, JSON_THROW_ON_ERROR);
    if (!is_array($config) || ($config['schema_version'] ?? NULL) !== 1 || !is_array($config['profiles'] ?? NULL)) {
      throw new InvalidArgumentException('Trusted feature image profile registry is invalid.');
    }
    $issueNumber = (int) $issueRaw;
    $profile = $config['profiles'][(string) $issueNumber] ?? NULL;
    if (!is_array($profile)) {
      throw new RuntimeException('No trusted feature image profile exists for this issue.');
    }

    $helper = AgencyEditorialFeatureImage::fromContainer(\Drupal::getContainer());
    $result = match ($mode) {
      'inspect' => $helper->inspect($profile, $issueNumber, $assetPath),
      'dry-run' => $helper->dryRun($profile, $issueNumber, $assetPath),
      'apply' => $helper->apply($profile, $issueNumber, $assetPath),
    };
    $writeResult($result);
  }
  catch (Throwable $exception) {
    $writeResult([
      'status' => 'FAIL',
      'verdict' => 'FAIL_CLOSED',
      'mode' => $mode,
      'issue_number' => ctype_digit($issueRaw) ? (int) $issueRaw : NULL,
      'message' => $exception->getMessage(),
    ]);
    exit(1);
  }
}

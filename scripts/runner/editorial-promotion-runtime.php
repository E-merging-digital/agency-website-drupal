<?php

declare(strict_types=1);

use Drupal\emerging_digital_content\Service\EditorialPathautoFinalizer;

$issueRaw = getenv('AGENCY_EDITORIAL_ISSUE') ?: '';
$payloadSha = getenv('AGENCY_EDITORIAL_PAYLOAD_SHA') ?: '';
$payloadPath = getenv('AGENCY_EDITORIAL_PAYLOAD_PATH') ?: '';
$profilePath = getenv('AGENCY_EDITORIAL_IMAGE_PROFILE_PATH') ?: '';
$assetPath = getenv('AGENCY_EDITORIAL_IMAGE_ASSET_PATH') ?: '';
$resultPath = getenv('AGENCY_EDITORIAL_RESULT_PATH') ?: '';
$publicationLibrary = getenv('AGENCY_EDITORIAL_LIBRARY_PATH') ?: '';
$imageLibrary = getenv('AGENCY_EDITORIAL_IMAGE_LIBRARY_PATH') ?: '';
$promotionLibrary = getenv('AGENCY_EDITORIAL_PROMOTION_LIBRARY_PATH') ?: '';

$writeResult = static function (array $result) use ($resultPath): void {
  if ($resultPath === '') {
    throw new RuntimeException('AGENCY_EDITORIAL_RESULT_PATH is required.');
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
    throw new InvalidArgumentException('AGENCY_EDITORIAL_ISSUE must be positive numeric.');
  }
  if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha)) {
    throw new InvalidArgumentException('AGENCY_EDITORIAL_PAYLOAD_SHA must be SHA-256.');
  }
  foreach ([
    $payloadPath,
    $profilePath,
    $assetPath,
    $publicationLibrary,
    $imageLibrary,
    $promotionLibrary,
  ] as $requiredFile) {
    if ($requiredFile === '' || !is_file($requiredFile)) {
      throw new RuntimeException('Required exact promotion input is missing.');
    }
  }

  $actualPayloadHash = hash_file('sha256', $payloadPath);
  if (!is_string($actualPayloadHash) || !hash_equals($payloadSha, $actualPayloadHash)) {
    throw new RuntimeException('Editorial payload hash mismatch on production.');
  }
  $payload = json_decode(
    (string) file_get_contents($payloadPath),
    TRUE,
    32,
    JSON_THROW_ON_ERROR,
  );
  $registry = json_decode(
    (string) file_get_contents($profilePath),
    TRUE,
    32,
    JSON_THROW_ON_ERROR,
  );
  if (!is_array($payload) || !is_array($registry)) {
    throw new InvalidArgumentException('Promotion payload/profile inputs must decode to objects.');
  }

  $issueNumber = (int) $issueRaw;
  $profile = $registry['profiles'][(string) $issueNumber] ?? NULL;
  if (!is_array($profile)) {
    throw new RuntimeException('No exact feature-image profile exists for this Article promotion.');
  }

  if (!defined('AGENCY_EDITORIAL_LIBRARY_ONLY')) {
    define('AGENCY_EDITORIAL_LIBRARY_ONLY', TRUE);
  }
  require_once $publicationLibrary;
  require_once $imageLibrary;
  require_once $promotionLibrary;

  $container = \Drupal::getContainer();
  $publisher = AgencyEditorialPublication::fromContainer($container);
  $image = AgencyEditorialFeatureImage::fromContainer($container);
  $promotion = AgencyEditorialPromotion::fromContainer($container);
  $finalizer = EditorialPathautoFinalizer::fromContainer($container);

  $publisher->validatePayload($payload, $issueNumber);
  if (($payload['published'] ?? NULL) !== TRUE) {
    throw new RuntimeException('PROD promotion requires publication_intent=true.');
  }
  $image->validateProfile($profile, $issueNumber);

  // The Article is deliberately staged unpublished. If image materialization
  // fails, no new public Article can escape without the approved visual.
  $stagedPayload = $payload;
  $stagedPayload['published'] = FALSE;
  $stageResult = $publisher->apply($stagedPayload, $issueNumber, $payloadSha);

  $imageResult = $image->apply($profile, $issueNumber, $assetPath);
  if (!in_array($imageResult['verdict'] ?? '', ['APPLIED', 'REPAIRED', 'IDEMPOTENT'], TRUE)) {
    throw new RuntimeException('Feature image did not converge before publication.');
  }

  $promotionResult = $promotion->finalize(
    $payload,
    $profile,
    $issueNumber,
    $payloadSha,
  );
  $aliasResult = $finalizer->apply($issueNumber, $payloadSha);

  $result = [
    'status' => 'PASS',
    'verdict' => $promotionResult['verdict'],
    'mode' => 'apply',
    'issue_number' => $issueNumber,
    'payload_sha256' => $payloadSha,
    'stage_verdict' => $stageResult['verdict'] ?? 'UNKNOWN',
    'image_verdict' => $imageResult['verdict'] ?? 'UNKNOWN',
    'alias_finalization' => $aliasResult['verdict'] ?? 'UNKNOWN',
    'aliases_to_repair' => $aliasResult['aliases_to_repair'] ?? [],
    'node' => $aliasResult['node'] ?? $promotionResult['node'],
    'visual_completeness' => 'PASS',
    'direct_prod_creation' => 'REFUSED_BY_DESIGN',
  ];
  $writeResult($result);
}
catch (Throwable $exception) {
  $writeResult([
    'status' => 'FAIL',
    'verdict' => 'FAIL_CLOSED',
    'mode' => 'apply',
    'issue_number' => ctype_digit($issueRaw) ? (int) $issueRaw : NULL,
    'message' => $exception->getMessage(),
  ]);
  exit(1);
}

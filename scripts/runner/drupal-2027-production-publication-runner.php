<?php

declare(strict_types=1);

$mode = getenv('AGENCY_DRUPAL_2027_PROD_MODE') ?: '';
$payloadSha = getenv('AGENCY_DRUPAL_2027_PAYLOAD_SHA') ?: '';
$payloadPath = getenv('AGENCY_DRUPAL_2027_PAYLOAD_PATH') ?: '';
$resultPath = getenv('AGENCY_DRUPAL_2027_RESULT_PATH') ?: '';
$candidateLibraryPath = getenv('AGENCY_DRUPAL_2027_LIBRARY_PATH') ?: '';

$writeResult = static function (array $result) use ($resultPath): void {
  if ($resultPath === '') {
    throw new RuntimeException('AGENCY_DRUPAL_2027_RESULT_PATH is required.');
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
    throw new InvalidArgumentException('Unsupported AGENCY_DRUPAL_2027_PROD_MODE.');
  }
  if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha)) {
    throw new InvalidArgumentException('AGENCY_DRUPAL_2027_PAYLOAD_SHA must be SHA-256.');
  }
  if ($payloadPath === '' || !is_file($payloadPath)) {
    throw new InvalidArgumentException('Drupal 2027 approved payload file is missing.');
  }
  if ($candidateLibraryPath === '' || !is_file($candidateLibraryPath)) {
    throw new RuntimeException('Drupal 2027 candidate library is missing.');
  }

  $actualHash = hash_file('sha256', $payloadPath);
  if (!is_string($actualHash) || !hash_equals($payloadSha, $actualHash)) {
    throw new RuntimeException('Drupal 2027 approved payload hash mismatch on PROD.');
  }

  $payload = json_decode(
    (string) file_get_contents($payloadPath),
    TRUE,
    64,
    JSON_THROW_ON_ERROR,
  );
  if (!is_array($payload)) {
    throw new InvalidArgumentException('Drupal 2027 payload must decode to an object.');
  }

  require_once $candidateLibraryPath;
  $candidate = AgencyDrupal2027PreprodCandidate::fromContainer(\Drupal::getContainer());

  $result = match ($mode) {
    'dry-run' => $candidate->dryRun($payload, $payloadSha),
    'apply' => $candidate->apply($payload, $payloadSha),
  };

  $result['target'] = 'PROD';
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
    'profile' => 'drupal-2027-landing',
    'candidate_id' => 'agency-drupal-2027-landing-1012',
    'target' => 'PROD',
    'prod_write' => 'NONE',
    'message' => $exception->getMessage(),
  ]);
  exit(1);
}

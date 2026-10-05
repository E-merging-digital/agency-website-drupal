<?php

declare(strict_types=1);

$mode = getenv('AGENCY_EDITORIAL_MODE') ?: '';
$issueRaw = getenv('AGENCY_EDITORIAL_ISSUE') ?: '';
$payloadSha = getenv('AGENCY_EDITORIAL_PAYLOAD_SHA') ?: '';
$payloadPath = getenv('AGENCY_EDITORIAL_PAYLOAD_PATH') ?: '';
$resultPath = getenv('AGENCY_EDITORIAL_RESULT_PATH') ?: '';
$libraryPath = getenv('AGENCY_EDITORIAL_SERVICE_LIBRARY_PATH') ?: '';

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
  if ($issueRaw !== '1117') {
    throw new InvalidArgumentException(
      'Service PROD runtime supports only issue #1117.',
    );
  }
  $issueNumber = (int) $issueRaw;
  if (!in_array($mode, ['inspect', 'dry-run', 'apply'], TRUE)) {
    throw new InvalidArgumentException('Unsupported AGENCY_EDITORIAL_MODE.');
  }
  if ($libraryPath === '' || !is_file($libraryPath)) {
    throw new RuntimeException('Trusted Service publication library is missing.');
  }
  require_once $libraryPath;

  $publisher = AgencyEditorialServicePublication::fromContainer(
    \Drupal::getContainer(),
  );
  if ($mode === 'inspect') {
    $result = $publisher->inspect($issueNumber);
  }
  else {
    if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha)) {
      throw new InvalidArgumentException(
        'AGENCY_EDITORIAL_PAYLOAD_SHA must be SHA-256.',
      );
    }
    if ($payloadPath === '' || !is_file($payloadPath)) {
      throw new InvalidArgumentException('Editorial payload file is missing.');
    }
    $actualHash = hash_file('sha256', $payloadPath);
    if (!is_string($actualHash) || !hash_equals($payloadSha, $actualHash)) {
      throw new RuntimeException('Service payload hash mismatch on PROD.');
    }
    $payload = json_decode(
      (string) file_get_contents($payloadPath),
      TRUE,
      32,
      JSON_THROW_ON_ERROR,
    );
    if (!is_array($payload)) {
      throw new InvalidArgumentException(
        'Editorial payload must decode to an object.',
      );
    }
    $result = $mode === 'dry-run'
      ? $publisher->dryRun($payload, $issueNumber, $payloadSha)
      : $publisher->apply($payload, $issueNumber, $payloadSha);
  }

  $writeResult($result);
}
catch (Throwable $exception) {
  $writeResult([
    'status' => 'FAIL',
    'verdict' => 'FAIL_CLOSED',
    'mode' => $mode,
    'candidate_kind' => 'service',
    'target' => 'PROD',
    'issue_number' => ctype_digit($issueRaw) ? (int) $issueRaw : NULL,
    'message' => $exception->getMessage(),
    'content_sync' => 'NONE',
    'db_copy' => 'NONE',
  ]);
  exit(1);
}

<?php

declare(strict_types=1);

use Drupal\emerging_digital_content\Service\EditorialPathautoFinalizer;

$mode = getenv('AGENCY_EDITORIAL_MODE') ?: '';
$issueRaw = getenv('AGENCY_EDITORIAL_ISSUE') ?: '';
$payloadSha = getenv('AGENCY_EDITORIAL_PAYLOAD_SHA') ?: '';
$payloadPath = getenv('AGENCY_EDITORIAL_PAYLOAD_PATH') ?: '';
$resultPath = getenv('AGENCY_EDITORIAL_RESULT_PATH') ?: '';
$libraryPath = getenv('AGENCY_EDITORIAL_LIBRARY_PATH') ?: '';

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
  $issueNumber = (int) $issueRaw;
  if (!in_array($mode, ['inspect', 'dry-run', 'apply'], TRUE)) {
    throw new InvalidArgumentException('Unsupported AGENCY_EDITORIAL_MODE.');
  }
  if ($libraryPath === '' || !is_file($libraryPath)) {
    throw new RuntimeException('Trusted editorial publication library is missing.');
  }

  if (!defined('AGENCY_EDITORIAL_LIBRARY_ONLY')) {
    define('AGENCY_EDITORIAL_LIBRARY_ONLY', TRUE);
  }
  require_once $libraryPath;

  $publisher = AgencyEditorialPublication::fromContainer(\Drupal::getContainer());
  $finalizer = EditorialPathautoFinalizer::fromContainer(\Drupal::getContainer());

  if ($mode === 'inspect') {
    $result = $publisher->inspect($issueNumber);
    if (is_array($result['runtime']['mapping'] ?? NULL)) {
      $aliasResult = $finalizer->inspect(
        $issueNumber,
        (string) $result['runtime']['mapping']['payload_sha256'],
      );
      $result['runtime']['alias_finalization'] = [
        'verdict' => $aliasResult['verdict'],
        'aliases_to_repair' => $aliasResult['aliases_to_repair'],
        'node' => $aliasResult['node'],
      ];
    }
  }
  else {
    if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha)) {
      throw new InvalidArgumentException('AGENCY_EDITORIAL_PAYLOAD_SHA must be SHA-256.');
    }
    if ($payloadPath === '' || !is_file($payloadPath)) {
      throw new InvalidArgumentException('Editorial payload file is missing.');
    }
    $actualHash = hash_file('sha256', $payloadPath);
    if (!is_string($actualHash) || !hash_equals($payloadSha, $actualHash)) {
      throw new RuntimeException('Editorial payload hash mismatch on production.');
    }
    $payload = json_decode(
      (string) file_get_contents($payloadPath),
      TRUE,
      32,
      JSON_THROW_ON_ERROR,
    );
    if (!is_array($payload)) {
      throw new InvalidArgumentException('Editorial payload must decode to an object.');
    }

    if ($mode === 'dry-run') {
      $result = $publisher->dryRun($payload, $issueNumber, $payloadSha);
      if ($result['verdict'] === 'IDEMPOTENT') {
        $aliasResult = $finalizer->inspect($issueNumber, $payloadSha);
        if ($aliasResult['verdict'] === 'REPAIR_REQUIRED') {
          $result['verdict'] = 'REPAIR_REQUIRED';
        }
        $result['alias_finalization'] = $aliasResult['verdict'];
        $result['aliases_to_repair'] = $aliasResult['aliases_to_repair'];
        $result['node'] = $aliasResult['node'];
      }
    }
    else {
      $result = $publisher->apply($payload, $issueNumber, $payloadSha);
      $aliasResult = $finalizer->apply($issueNumber, $payloadSha);
      if ($result['verdict'] === 'IDEMPOTENT' && $aliasResult['verdict'] === 'REPAIRED') {
        $result['verdict'] = 'REPAIRED';
      }
      $result['alias_finalization'] = $aliasResult['verdict'];
      $result['aliases_to_repair'] = $aliasResult['aliases_to_repair'];
      $result['node'] = $aliasResult['node'];
    }
  }

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

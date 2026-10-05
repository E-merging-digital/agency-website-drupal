<?php

declare(strict_types=1);

$mode = getenv('AGENCY_HOMEPAGE_BRAND_1015_MODE') ?: '';
$resultPath = getenv('AGENCY_HOMEPAGE_BRAND_1015_RESULT_PATH') ?: '';
$libraryPath = getenv('AGENCY_HOMEPAGE_BRAND_1015_LIBRARY_PATH') ?: '';

$writeResult = static function (array $result) use ($resultPath): void {
  if ($resultPath === '') {
    throw new RuntimeException('AGENCY_HOMEPAGE_BRAND_1015_RESULT_PATH is required.');
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
  if (!in_array($mode, ['inspect', 'dry-run', 'apply'], TRUE)) {
    throw new InvalidArgumentException('Unsupported AGENCY_HOMEPAGE_BRAND_1015_MODE.');
  }
  if ($libraryPath === '' || !is_file($libraryPath)) {
    throw new RuntimeException('Homepage Brand #1015 profile library is missing.');
  }

  require_once $libraryPath;
  $candidate = AgencyHomepageBrand1015::fromContainer(\Drupal::getContainer());

  $result = match ($mode) {
    'inspect' => $candidate->inspect(),
    'dry-run' => $candidate->dryRun(),
    'apply' => $candidate->apply(),
  };

  $result['target'] = 'PREPROD';
  $result['prod_write'] = 'NONE';
  $writeResult($result);
}
catch (Throwable $exception) {
  $writeResult([
    'status' => 'FAIL',
    'verdict' => 'FAIL_CLOSED',
    'mode' => $mode,
    'profile' => 'homepage-brand-1015',
    'issue_number' => 1015,
    'target' => 'PREPROD',
    'prod_write' => 'NONE',
    'message' => $exception->getMessage(),
  ]);
  exit(1);
}

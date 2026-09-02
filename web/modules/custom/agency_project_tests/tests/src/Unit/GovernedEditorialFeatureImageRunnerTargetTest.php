<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the closed runtime target propagation for governed feature images.
 *
 * @group agency_project_tests
 * @group governed_editorial_feature_image
 * @group issue_975
 */
final class GovernedEditorialFeatureImageRunnerTargetTest extends TestCase {

  /**
   * The workflow must pass its already-authorized target to the runner.
   */
  public function testWorkflowPropagatesClosedTargetAndPreservesAuthority(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-feature-image.yml',
    );

    self::assertStringContainsString(
      'EDITORIAL_IMAGE_TARGET: ${{ steps.request.outputs.target }}',
      $workflow,
    );
    self::assertStringContainsString("401) target='PROD'", $workflow);
    self::assertStringContainsString("958) target='PREPROD'", $workflow);
    self::assertStringContainsString("target='PROD'", $workflow);
    self::assertStringContainsString('5514934920', $workflow);
    self::assertStringContainsString(
      'bd6412a6547fdcd3dda3bab9a1e2f65d89bc9aafcdbc8744dc5717888ef231d6',
      $workflow,
    );
    self::assertStringContainsString(
      '3a705624b08ac2c21a10db0e8573841ff8c4cecf087409cd8782953e3684c6f4',
      $workflow,
    );
    self::assertStringContainsString(
      'same profile, asset, live main and target',
      $workflow,
    );
  }

  /**
   * The exact three authorized issue/target combinations resolve correctly.
   */
  public function testRunnerResolvesOnlyAuthorizedTargetMatrix(): void {
    $prod401 = $this->traceAuthorizedResolution('401', 'PROD', 'prod-user');
    self::assertStringContainsString('+ PROJECT_ROOT=/var/www/agency', $prod401);
    self::assertStringNotContainsString('+ PROJECT_ROOT=/var/www/agency-preprod', $prod401);
    self::assertStringNotContainsString('+ SERVER_USER=agency-preprod', $prod401);

    $preprod958 = $this->traceAuthorizedResolution('958', 'PREPROD', 'ignored-prod-user');
    self::assertStringContainsString('+ PROJECT_ROOT=/var/www/agency-preprod', $preprod958);
    self::assertStringContainsString('+ SERVER_USER=agency-preprod', $preprod958);

    $prod958 = $this->traceAuthorizedResolution('958', 'PROD', 'prod-user');
    self::assertStringContainsString('+ PROJECT_ROOT=/var/www/agency', $prod958);
    self::assertStringContainsString('+ SERVER_USER=prod-user', $prod958);
    self::assertStringNotContainsString('+ PROJECT_ROOT=/var/www/agency-preprod', $prod958);
    self::assertStringNotContainsString('+ SERVER_USER=agency-preprod', $prod958);
  }

  /**
   * Missing, unknown and unauthorized target combinations fail before I/O.
   */
  public function testRunnerFailsClosedBeforeAnyRuntimeIo(): void {
    $missing = $this->runSelectionFailure('958', NULL, 'prod-user');
    self::assertStringContainsString('Unsupported EDITORIAL_IMAGE_TARGET:', $missing);

    $unknown = $this->runSelectionFailure('958', 'STAGING', 'prod-user');
    self::assertStringContainsString('Unsupported EDITORIAL_IMAGE_TARGET: STAGING', $unknown);

    $wrongPair = $this->runSelectionFailure('401', 'PREPROD', 'prod-user');
    self::assertStringContainsString(
      'No bounded editorial image issue/target pair is approved.',
      $wrongPair,
    );

    $wrongIssue = $this->runSelectionFailure('402', 'PROD', 'prod-user');
    self::assertStringContainsString(
      'No bounded editorial image issue/target pair is approved.',
      $wrongIssue,
    );

    foreach ([$missing, $unknown, $wrongPair, $wrongIssue] as $failure) {
      self::assertStringNotContainsString('ssh ', $failure);
      self::assertStringNotContainsString('scp ', $failure);
    }
  }

  /**
   * Root and PREPROD identity remain internal closed derivations.
   */
  public function testRunnerDoesNotExposeGenericRootOrUserSelectors(): void {
    $root = dirname(DRUPAL_ROOT);
    $shell = (string) file_get_contents(
      $root . '/scripts/runner/run-editorial-feature-image.sh',
    );

    self::assertStringContainsString('TARGET="${EDITORIAL_IMAGE_TARGET:-}"', $shell);
    self::assertStringContainsString('case "$ISSUE_NUMBER:$TARGET" in', $shell);
    self::assertStringContainsString('401:PROD)', $shell);
    self::assertStringContainsString('958:PREPROD)', $shell);
    self::assertStringContainsString('958:PROD)', $shell);
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency'", $shell);
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency-preprod'", $shell);
    self::assertStringContainsString("SERVER_USER='agency-preprod'", $shell);

    self::assertStringNotContainsString('PROJECT_ROOT="${PROJECT_ROOT', $shell);
    self::assertStringNotContainsString('TARGET=\'PREPROD\'\n    PROJECT_ROOT=\'/var/www/agency-preprod\'\n    SERVER_USER=\'agency-preprod\'', $shell);
  }

  /**
   * Traces one authorized pair until the deliberate local file guard stops it.
   */
  private function traceAuthorizedResolution(string $issue, string $target, string $serverUser): string {
    $result = $this->runRunner($issue, $target, $serverUser, TRUE);
    self::assertNotSame(0, $result['exit']);
    self::assertStringContainsString('PROFILE_FILE', $result['output']);
    self::assertStringNotContainsString('Permission denied', $result['output']);
    return $result['output'];
  }

  /**
   * Executes one rejected selection and returns its local-only output.
   */
  private function runSelectionFailure(string $issue, ?string $target, string $serverUser): string {
    $result = $this->runRunner($issue, $target, $serverUser, FALSE);
    self::assertNotSame(0, $result['exit']);
    return $result['output'];
  }

  /**
   * Runs the shell runner with no valid files, so network I/O is unreachable.
   *
   * @return array{exit:int,output:string}
   */
  private function runRunner(string $issue, ?string $target, string $serverUser, bool $trace): array {
    $root = dirname(DRUPAL_ROOT);
    $runner = $root . '/scripts/runner/run-editorial-feature-image.sh';
    self::assertFileExists($runner);

    $env = [
      'EDITORIAL_IMAGE_MODE=inspect',
      'ISSUE_NUMBER=' . $issue,
      'PROFILE_SHA256=' . str_repeat('a', 64),
      'PROFILE_FILE=/definitely/missing/profile.json',
      'ASSET_SHA256=' . str_repeat('b', 64),
      'ASSET_FILE=/definitely/missing/asset.png',
      'SERVER_HOST=example.invalid',
      'SERVER_USER=' . $serverUser,
      'GITHUB_RUN_ID=0',
      'GITHUB_RUN_ATTEMPT=1',
    ];
    if ($target !== NULL) {
      $env[] = 'EDITORIAL_IMAGE_TARGET=' . $target;
    }

    $command = 'env ' . implode(' ', array_map('escapeshellarg', $env))
      . ' bash ' . ($trace ? '-x ' : '') . escapeshellarg($runner) . ' 2>&1';
    $lines = [];
    $exit = 0;
    exec($command, $lines, $exit);

    return [
      'exit' => $exit,
      'output' => implode("\n", $lines),
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects #975 target propagation into the existing #584 image runner.
 *
 * @group agency_project_tests
 * @group issue_975
 * @group governed_editorial_feature_image
 */
final class Issue975EditorialFeatureImageRunnerTargetTest extends TestCase {

  /**
   * The workflow must pass its already-authorized target into the runner.
   */
  public function testWorkflowPropagatesResolvedTargetIntoRunner(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflowPath = $root . '/.github/workflows/trusted-editorial-feature-image.yml';
    $workflow = Yaml::parseFile($workflowPath);
    self::assertIsArray($workflow);

    $workflowCall = $workflow['on']['workflow_call'] ?? NULL;
    self::assertIsArray($workflowCall);
    self::assertArrayNotHasKey('inputs', $workflowCall);

    $steps = $workflow['jobs']['editorial-feature-image']['steps'] ?? NULL;
    self::assertIsArray($steps);
    $execute = NULL;
    foreach ($steps as $step) {
      if (($step['name'] ?? NULL) === 'Execute bounded editorial image route') {
        $execute = $step;
        break;
      }
    }
    self::assertIsArray($execute);
    self::assertSame(
      '${{ steps.request.outputs.target }}',
      $execute['env']['EDITORIAL_IMAGE_TARGET'] ?? NULL,
    );
    self::assertSame(
      '${{ secrets.SERVER_USER }}',
      $execute['env']['SERVER_USER'] ?? NULL,
    );
    self::assertArrayNotHasKey('PROJECT_ROOT', $execute['env']);

    $runner = $this->runnerSource();
    self::assertStringContainsString('TARGET="${EDITORIAL_IMAGE_TARGET:-}"', $runner);
    self::assertStringNotContainsString('EDITORIAL_IMAGE_PROJECT_ROOT', $runner);
    self::assertStringNotContainsString('EDITORIAL_IMAGE_SERVER_USER', $runner);
  }

  /**
   * Only the three Project Lead-approved issue/target pairs may resolve.
   */
  public function testRunnerHasOnlyClosedIssueTargetPairs(): void {
    $runner = $this->runnerSource();

    self::assertStringContainsString('case "$ISSUE_NUMBER:$TARGET" in', $runner);
    self::assertStringContainsString('401:PROD)', $runner);
    self::assertStringContainsString('958:PREPROD)', $runner);
    self::assertStringContainsString('958:PROD)', $runner);
    self::assertStringContainsString(
      'No bounded editorial image issue/target pair is approved.',
      $runner,
    );

    $prod401 = $this->caseBody($runner, '401:PROD');
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency'", $prod401);
    self::assertStringNotContainsString('agency-preprod', $prod401);

    $preprod958 = $this->caseBody($runner, '958:PREPROD');
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency-preprod'", $preprod958);
    self::assertStringContainsString("SERVER_USER='agency-preprod'", $preprod958);

    $prod958 = $this->caseBody($runner, '958:PROD');
    self::assertStringContainsString("PROJECT_ROOT='/var/www/agency'", $prod958);
    self::assertStringNotContainsString('/var/www/agency-preprod', $prod958);
    self::assertStringNotContainsString("SERVER_USER='agency-preprod'", $prod958);
    self::assertStringNotContainsString('TARGET=\'PREPROD\'', $prod958);

    self::assertSame(
      3,
      preg_match_all("/PROJECT_ROOT='\\/var\\/www\\/agency(?:-preprod)?'/", $runner),
      'Project roots must remain fixed constants for the three approved pairs.',
    );
    self::assertStringNotContainsString('PROJECT_ROOT="${', $runner);
  }

  /**
   * Missing, unknown and wrong issue/target combinations fail before runtime.
   */
  public function testWrongTargetsAndPairsFailClosedBeforeSsh(): void {
    $missing = $this->runResolver('958', '');
    self::assertNotSame(0, $missing['exit']);
    self::assertStringContainsString('Unsupported EDITORIAL_IMAGE_TARGET:', $missing['output']);

    $unknown = $this->runResolver('958', 'STAGING');
    self::assertNotSame(0, $unknown['exit']);
    self::assertStringContainsString('Unsupported EDITORIAL_IMAGE_TARGET: STAGING', $unknown['output']);

    foreach ([
      ['401', 'PREPROD'],
      ['999', 'PROD'],
      ['999', 'PREPROD'],
    ] as [$issue, $target]) {
      $result = $this->runResolver($issue, $target);
      self::assertNotSame(0, $result['exit']);
      self::assertStringContainsString(
        'No bounded editorial image issue/target pair is approved.',
        $result['output'],
      );
      self::assertStringNotContainsString('Permission denied', $result['output']);
    }

    foreach ([
      ['401', 'PROD'],
      ['958', 'PREPROD'],
      ['958', 'PROD'],
    ] as [$issue, $target]) {
      $result = $this->runResolver($issue, $target);
      self::assertNotSame(0, $result['exit'], 'Fixture intentionally stops at later hash/file validation.');
      self::assertStringNotContainsString('Unsupported EDITORIAL_IMAGE_TARGET', $result['output']);
      self::assertStringNotContainsString(
        'No bounded editorial image issue/target pair is approved.',
        $result['output'],
      );
      self::assertStringNotContainsString('Permission denied', $result['output']);
    }
  }

  /**
   * The #972 approval/profile/asset and dry-run bindings remain intact.
   */
  public function testProdApprovalAndDryRunBindingsRemainUnchanged(): void {
    $workflow = (string) file_get_contents(
      dirname(DRUPAL_ROOT) . '/.github/workflows/trusted-editorial-feature-image.yml',
    );

    foreach ([
      '5514934920',
      '2e92228480ee6ae7410c028eab2b88c7d7db1534668477f6eafbc236668cb700',
      'bd6412a6547fdcd3dda3bab9a1e2f65d89bc9aafcdbc8744dc5717888ef231d6',
      '3a705624b08ac2c21a10db0e8573841ff8c4cecf087409cd8782953e3684c6f4',
      'EXACT_CANDIDATE_PROMOTION_TO_PROD = AUTHORIZED',
      'match.groups() == (profile_sha, actual_sha, trusted_main, target)',
      'same profile, asset, live main and target',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Returns one exact shell case body.
   */
  private function caseBody(string $runner, string $case): string {
    $pattern = '/(?:^|\\n)\\s*' . preg_quote($case, '/') . '\\)\\s*\\n(.*?)\\n\\s*;;/s';
    self::assertSame(1, preg_match($pattern, $runner, $matches));
    return $matches[1];
  }

  /**
   * Executes only enough of the runner to exercise target validation.
   *
   * Approved pairs intentionally stop later because hashes/files are omitted;
   * no SSH command can be reached by this fixture.
   *
   * @return array{exit:int,output:string}
   *   Process exit status and output.
   */
  private function runResolver(string $issue, string $target): array {
    $runner = dirname(DRUPAL_ROOT) . '/scripts/runner/run-editorial-feature-image.sh';
    $command = sprintf(
      'EDITORIAL_IMAGE_MODE=inspect ISSUE_NUMBER=%s EDITORIAL_IMAGE_TARGET=%s bash %s 2>&1',
      escapeshellarg($issue),
      escapeshellarg($target),
      escapeshellarg($runner),
    );
    $lines = [];
    $exit = 0;
    exec($command, $lines, $exit);
    return [
      'exit' => $exit,
      'output' => implode("\n", $lines),
    ];
  }

  /**
   * Returns the existing bounded runner source.
   */
  private function runnerSource(): string {
    return (string) file_get_contents(
      dirname(DRUPAL_ROOT) . '/scripts/runner/run-editorial-feature-image.sh',
    );
  }

}

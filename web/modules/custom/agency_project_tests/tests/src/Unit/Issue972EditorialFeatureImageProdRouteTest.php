<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the #972 explicit PROD route for the approved #958 feature image.
 *
 * @group agency_project_tests
 * @group issue_972
 * @group governed_editorial_feature_image
 */
final class Issue972EditorialFeatureImageProdRouteTest extends TestCase {

  /**
   * The existing route is extended by exact commands, not a target selector.
   */
  public function testExplicitProdCommandSurfaceIsClosedToIssue958(): void {
    $root = dirname(DRUPAL_ROOT);
    $dispatcherPath = $root . '/.github/workflows/agency-command-dispatch.yml';
    $workflowPath = $root . '/.github/workflows/trusted-editorial-feature-image.yml';
    $dispatcher = Yaml::parseFile($dispatcherPath);
    $workflow = Yaml::parseFile($workflowPath);
    self::assertIsArray($dispatcher);
    self::assertIsArray($workflow);

    $routes = json_decode(
      (string) ($dispatcher['env']['AGENCY_COMMAND_ROUTES'] ?? ''),
      TRUE,
      32,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($routes);
    $featureImageRoutes = array_values(array_filter(
      $routes,
      static fn (array $route): bool => ($route['route'] ?? NULL) === 'EDITORIAL_FEATURE_IMAGE',
    ));
    self::assertCount(1, $featureImageRoutes);
    self::assertSame([
      '/agency-editorial-image inspect',
      '/agency-editorial-image dry-run',
      '/agency-editorial-image apply',
      '/agency-editorial-image-prod inspect',
      '/agency-editorial-image-prod dry-run',
      '/agency-editorial-image-prod apply',
    ], $featureImageRoutes[0]['exact'] ?? NULL);

    foreach ($featureImageRoutes[0]['exact'] as $command) {
      self::assertStringNotContainsString('target=', $command);
      self::assertStringNotContainsString('host=', $command);
      self::assertStringNotContainsString('path=', $command);
      self::assertStringNotContainsString('url=', $command);
    }

    $dispatcherSource = (string) file_get_contents($dispatcherPath);
    self::assertStringContainsString(
      "github.event.issue.number == 958 && startsWith(github.event.comment.body, '/agency-editorial-image ')",
      $dispatcherSource,
    );
    self::assertStringContainsString('secrets.PREPROD_SSH_PRIVATE_KEY', $dispatcherSource);
    self::assertStringContainsString('secrets.PREPROD_SERVER_HOST', $dispatcherSource);
    self::assertStringContainsString('secrets.SSH_PRIVATE_KEY', $dispatcherSource);
    self::assertStringContainsString('secrets.SERVER_HOST', $dispatcherSource);

    $workflowCall = $workflow['on']['workflow_call'] ?? NULL;
    self::assertIsArray($workflowCall);
    self::assertArrayNotHasKey('inputs', $workflowCall);

    $source = (string) file_get_contents($workflowPath);
    self::assertStringContainsString("401) target='PROD'", $source);
    self::assertStringContainsString("958) target='PREPROD'", $source);
    self::assertStringContainsString("surface='prod'", $source);
    self::assertStringContainsString(
      'The explicit editorial image PROD command is approved only for issue #958.',
      $source,
    );
    self::assertStringContainsString("target='PROD'", $source);
    self::assertStringNotContainsString('workflow_dispatch:', $source);
  }

  /**
   * PROD #958 is bound to the exact approved Article/image receipt.
   */
  public function testExactHumanApprovalReceiptAndHashesAreRequired(): void {
    $source = $this->workflowSource();

    foreach ([
      '5514934920',
      'agency-article-958',
      '5510862057',
      '2e92228480ee6ae7410c028eab2b88c7d7db1534668477f6eafbc236668cb700',
      'bd6412a6547fdcd3dda3bab9a1e2f65d89bc9aafcdbc8744dc5717888ef231d6',
      '3a705624b08ac2c21a10db0e8573841ff8c4cecf087409cd8782953e3684c6f4',
      'HUMAN_REVIEW = PASS',
      'CONTENT = APPROVED',
      'IMAGE = APPROVED',
      'ALT_FR_EN = APPROVED',
      'EXACT_CANDIDATE_PROMOTION_TO_PROD = AUTHORIZED',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringContainsString(
      "issues/comments/{approval_comment_id}",
      $source,
    );
    self::assertStringContainsString("endswith('/issues/958')", $source);
    self::assertStringContainsString("author_association') != 'OWNER'", $source);
    self::assertStringContainsString('Approved #958 image profile hash mismatch.', $source);
    self::assertStringContainsString('Approved #958 image asset hash mismatch.', $source);
    self::assertStringContainsString('Approved #958 Article payload hash mismatch.', $source);
  }

  /**
   * Apply authorization is bound to profile, asset, live main and target.
   */
  public function testProdApplyRequiresSameMainProdDryRun(): void {
    $source = $this->workflowSource();

    self::assertStringContainsString(
      "r'^target: `(PROD|PREPROD)`\\s*$'",
      $source,
    );
    self::assertStringContainsString(
      'match.groups() == (profile_sha, actual_sha, trusted_main, target)',
      $source,
    );
    self::assertStringContainsString(
      'Apply requires a bot-authored image dry-run PASS for the same profile, asset, live main and target.',
      $source,
    );
    self::assertStringContainsString("comment.get('user', {}).get('login') != 'github-actions[bot]'", $source);
    self::assertStringContainsString('trusted_main', $source);
  }

  /**
   * No generic media/content transport or PR-time operational route is added.
   */
  public function testNoGenericMediaPipelineAndPullRequestsStayNonOperational(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = $this->workflowSource();
    $dispatcher = (string) file_get_contents(
      $root . '/.github/workflows/agency-command-dispatch.yml',
    );
    $runner = (string) file_get_contents(
      $root . '/scripts/runner/run-editorial-feature-image.sh',
    );
    $php = (string) file_get_contents(
      $root . '/scripts/runner/editorial-feature-image.php',
    );
    $combined = $workflow . "\n" . $dispatcher . "\n" . $runner . "\n" . $php;

    self::assertStringContainsString('github.event.issue.pull_request == null', $workflow);
    self::assertStringContainsString("is_pr = os.environ['IS_PULL_REQUEST'].lower() == 'true'", $dispatcher);
    self::assertStringContainsString('if not is_pr:', $dispatcher);
    self::assertStringContainsString('editorial-feature-image-profiles.json', $workflow);
    self::assertStringContainsString('run-editorial-feature-image.sh', $workflow);
    self::assertStringContainsString('editorial-feature-image.php', $workflow);
    self::assertStringContainsString("private const FIELD_NAME = 'field_feature_image'", $php);

    foreach ([
      'workflow_dispatch:',
      'composer require',
      'drush cim',
      'drush updb',
      'emerging:governed-content',
      'REMOTE_IMAGE_DOWNLOAD',
      'GENERIC_MEDIA_PIPELINE',
      'CONTENT_SYNC',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $combined);
    }
  }

  /**
   * Returns the reusable #584 workflow source.
   */
  private function workflowSource(): string {
    return (string) file_get_contents(
      dirname(DRUPAL_ROOT) . '/.github/workflows/trusted-editorial-feature-image.yml',
    );
  }

}

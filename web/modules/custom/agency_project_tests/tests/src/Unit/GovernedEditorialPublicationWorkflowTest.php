<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed editor-owned Article publication route.
 *
 * @group agency_project_tests
 * @group governed_editorial_publication
 */
final class GovernedEditorialPublicationWorkflowTest extends TestCase {

  /**
   * The workflow must remain comment-triggered, actor-bound and main-trusted.
   */
  public function testWorkflowControlSurfaceIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-editorial-publication.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('issue_comment:', $workflow);
    self::assertStringContainsString("github.event.comment.body == '/agency-editorial inspect'", $workflow);
    self::assertStringContainsString("github.event.comment.body == '/agency-editorial dry-run'", $workflow);
    self::assertStringContainsString("github.event.comment.body == '/agency-editorial apply'", $workflow);
    self::assertStringContainsString("GITHUB_ACTOR\" == 'E-merging-digital'", $workflow);
    self::assertStringContainsString("'.user.login'", $workflow);
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Payload data must stay data and apply must require an exact dry-run hash.
   */
  public function testPayloadAndApplyContractFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-publication.yml',
    );

    self::assertStringContainsString(
      '<!-- agency-editorial-payload:v1 -->',
      $workflow,
    );
    self::assertStringContainsString("sort_keys=True", $workflow);
    self::assertStringContainsString("separators=(',', ':')", $workflow);
    self::assertStringContainsString('hashlib.sha256', $workflow);
    self::assertStringContainsString('github-actions[bot]', $workflow);
    self::assertStringContainsString(
      'Apply requires a prior bot-authored dry-run PASS',
      $workflow,
    );
    self::assertStringContainsString(
      "payload.get('bundle') != 'article'",
      $workflow,
    );
    self::assertStringNotContainsString('eval ', $workflow);
  }

  /**
   * The production runner may reuse SSH but must expose no generic executor.
   */
  public function testProductionRunnerIsArticleOnlyAndSyntaxValid(): void {
    $root = dirname(DRUPAL_ROOT);
    $shellPath = $root . '/scripts/runner/run-editorial-publication.sh';
    $phpPath = $root . '/scripts/runner/editorial-publication.php';
    self::assertFileExists($shellPath);
    self::assertFileExists($phpPath);

    $shell = (string) file_get_contents($shellPath);
    $php = (string) file_get_contents($phpPath);
    $combined = $shell . "\n" . $php;

    self::assertStringContainsString('/var/www/agency/current', $shell);
    self::assertStringContainsString('vendor/bin/drush sql:dump', $shell);
    self::assertStringContainsString('vendor/bin/drush php:script', $shell);
    self::assertStringContainsString("private const BUNDLE = 'article'", $php);
    self::assertStringContainsString("private const TEXT_FORMAT = 'basic_html'", $php);
    self::assertStringContainsString("private const AUTHOR_UID = 1", $php);
    self::assertStringContainsString('agency_editorial.issue.', $php);

    foreach ([
      'drush cim',
      'drush updb',
      'emerging:governed-content',
      'deploy-production.sh',
      'composer require',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $combined);
    }

    $output = [];
    $exitCode = 0;
    exec('bash -n ' . escapeshellarg($shellPath) . ' 2>&1', $output, $exitCode);
    self::assertSame(0, $exitCode, implode("\n", $output));

    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($phpPath) . ' 2>&1', $output, $exitCode);
    self::assertSame(0, $exitCode, implode("\n", $output));
  }

  /**
   * Existing deployment secrets are the only production connection inputs.
   */
  public function testWorkflowReusesExistingSshSecrets(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-publication.yml',
    );

    self::assertStringContainsString('${{ secrets.SSH_PRIVATE_KEY }}', $workflow);
    self::assertStringContainsString('${{ secrets.SERVER_HOST }}', $workflow);
    self::assertStringContainsString('${{ secrets.SERVER_USER }}', $workflow);
    self::assertStringNotContainsString('password:', $workflow);
    self::assertStringNotContainsString('settings.php', $workflow);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed editor-owned Article feature-image route.
 *
 * @group agency_project_tests
 * @group governed_editorial_feature_image
 */
final class GovernedEditorialFeatureImageWorkflowTest extends TestCase {

  /**
   * The control surface must stay issue-bound, actor-bound and main-trusted.
   */
  public function testWorkflowControlSurfaceIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-editorial-feature-image.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    foreach ([
      '/agency-editorial-image inspect',
      '/agency-editorial-image dry-run',
      '/agency-editorial-image apply',
    ] as $command) {
      self::assertStringContainsString($command, $workflow);
    }
    self::assertStringContainsString("GITHUB_ACTOR\" == 'E-merging-digital'", $workflow);
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString(
      "always() && steps.request.outcome == 'success'",
      $workflow,
    );
    self::assertStringContainsString('Apply requires a bot-authored image dry-run PASS', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Asset transport must be exact, repository-owned and URL-free.
   */
  public function testProfileAndAssetAreClosedAndHashBound(): void {
    $root = dirname(DRUPAL_ROOT);
    $profilePath = $root . '/scripts/runner/editorial-feature-image-profiles.json';
    $profile = json_decode(
      (string) file_get_contents($profilePath),
      TRUE,
      16,
      JSON_THROW_ON_ERROR,
    );
    self::assertSame(1, $profile['schema_version']);
    self::assertSame(['401'], array_keys($profile['profiles']));
    $issue = $profile['profiles']['401'];
    self::assertSame('article', $issue['bundle']);
    self::assertSame('field_feature_image', $issue['field_name']);
    self::assertSame('image/png', $issue['asset']['mime']);
    self::assertStringStartsWith('assets/editorial/', $issue['asset']['path']);
    self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $issue['asset']['sha256']);
    self::assertSame(
      $issue['asset']['sha256'],
      hash_file('sha256', $root . '/' . $issue['asset']['path']),
    );
    self::assertSame('Checklist de préparation avant la refonte d’un site web', $issue['alt']['fr']);
    self::assertSame('Website redesign preparation checklist', $issue['alt']['en']);

    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-feature-image.yml',
    );
    foreach (['curl ', 'wget ', 'http://', 'https://'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
    self::assertStringContainsString('hashlib.sha256', $workflow);
    self::assertStringContainsString('asset_sha256', $workflow);
    self::assertStringContainsString('profile_sha256', $workflow);
  }

  /**
   * The production runner may transport only the trusted profile and asset.
   */
  public function testRunnerIsFixedAndSyntaxValid(): void {
    $root = dirname(DRUPAL_ROOT);
    $shellPath = $root . '/scripts/runner/run-editorial-feature-image.sh';
    $phpPath = $root . '/scripts/runner/editorial-feature-image.php';
    self::assertFileExists($shellPath);
    self::assertFileExists($phpPath);

    $shell = (string) file_get_contents($shellPath);
    $php = (string) file_get_contents($phpPath);
    $combined = $shell . "\n" . $php;
    self::assertStringContainsString('/var/www/agency/current', $shell);
    self::assertStringContainsString('vendor/bin/drush sql:dump', $shell);
    self::assertStringContainsString('vendor/bin/drush php:script', $shell);
    self::assertStringContainsString('scp "${ssh_opts[@]}" "$ASSET_FILE"', $shell);
    self::assertStringContainsString("private const FIELD_NAME = 'field_feature_image'", $php);
    self::assertStringContainsString("private const DESTINATION_DIRECTORY = 'public://articles'", $php);
    self::assertStringContainsString('FileExists::Error', $php);
    self::assertStringContainsString('agency_editorial.issue.', $php);

    foreach ([
      'drush cim',
      'drush updb',
      'emerging:governed-content',
      'deploy-production.sh',
      'composer require',
      'curl ',
      'wget ',
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
   * The route may reuse only the existing production SSH secret surface.
   */
  public function testWorkflowReusesExistingSshSecrets(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-feature-image.yml',
    );
    self::assertStringContainsString('${{ secrets.SSH_PRIVATE_KEY }}', $workflow);
    self::assertStringContainsString('${{ secrets.SERVER_HOST }}', $workflow);
    self::assertStringContainsString('${{ secrets.SERVER_USER }}', $workflow);
    self::assertStringNotContainsString('password:', $workflow);
    self::assertStringNotContainsString('settings.php', $workflow);
  }

}

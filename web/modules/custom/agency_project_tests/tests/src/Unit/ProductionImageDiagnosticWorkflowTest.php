<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the issue-bound production image derivative diagnostic.
 *
 * @group agency_project_tests
 * @group production_image_diagnostic
 */
final class ProductionImageDiagnosticWorkflowTest extends TestCase {

  /**
   * The control surface must remain restricted to issue #596 and live main.
   */
  public function testControlSurfaceIsClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-image-diagnostic.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('/agency-production-image diagnose', $workflow);
    self::assertStringContainsString("github.actor == 'E-merging-digital'", $workflow);
    self::assertStringContainsString("ISSUE_NUMBER\" == '596'", $workflow);
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString('runs-on: ubuntu-24.04', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * The image target and diagnostic probes must be fixed in repository code.
   */
  public function testDiagnosticTargetsOnlyIssue401Derivative(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-image-diagnostic.yml',
    );

    foreach ([
      "CURRENT='/var/www/agency/current'",
      'issue-401-redesign-checklist-f925e3b41c32.png',
      'issue-401-redesign-checklist-f925e3b41c32.png.avif?itok=bTtxA4Oo',
      'system.image toolkit',
      'function_exists("imageavif")',
      'function_exists("imagewebp")',
      '["AVIF Support"]',
      '["WebP Support"]',
      'getimagesize',
      'imagecreatefrompng',
      'imageavif($i, NULL, 75)',
      'imagewebp($i, NULL, 75)',
      'php_fpm_workers',
      'php-fpm8.4 -i',
      'namei -l "$DERIVATIVE"',
      'df -Pk "$FILES"',
      'df -Pi "$FILES"',
      'existing_large_derivatives_begin',
      'watchdog:show --count=100 --type=php --severity=Error --extended',
      "message~=#(image|avif|gd|permission|write|file)#i",
      '/var/log/nginx/error.log',
      '/var/log/php8.4-fpm.log',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * In-memory encoder probes may not create a diagnostic output file.
   */
  public function testEncoderProbesStayInMemory(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-image-diagnostic.yml',
    );

    self::assertStringContainsString('ob_start()', $workflow);
    self::assertStringContainsString('ob_get_clean()', $workflow);
    self::assertStringContainsString('cli_avif_encode', $workflow);
    self::assertStringContainsString('cli_webp_encode', $workflow);
    self::assertStringNotContainsString('imageavif($i, $', $workflow);
    self::assertStringNotContainsString('imagewebp($i, $', $workflow);
  }

  /**
   * No recovery, config/content mutation or arbitrary input may be introduced.
   */
  public function testDiagnosticHasNoMutationSurface(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-image-diagnostic.yml',
    );

    foreach ([
      'state:set',
      'config:set',
      'config:import',
      'drush cim',
      'drush updb',
      'drush cr',
      'image:flush',
      'rm -rf',
      'systemctl restart',
      'systemctl reload',
      'deploy-production.sh main',
      'sudo ',
      'git pull',
      'git reset',
      'git checkout',
      'composer ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }

    self::assertStringContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
    self::assertStringContainsString('secrets.SERVER_HOST', $workflow);
    self::assertStringContainsString('secrets.SERVER_USER', $workflow);
  }

  /**
   * Machine evidence must distinguish capability and derivative failure modes.
   */
  public function testEvidenceClassifiesImageFailure(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-image-diagnostic.yml',
    );

    foreach ([
      'AVIF_CAPABILITY_MISMATCH',
      'CLI_AVIF_ENCODER_FAILED',
      'DERIVATIVE_GENERATION_FAILED',
      'DERIVATIVE_SERVING_FAILED',
      'DERIVATIVE_HEALTHY',
      'SOURCE_MISSING',
      'schema_version:2',
      'artifacts/production-image-diagnostic/result.json',
      'artifacts/production-image-diagnostic/diagnostic.txt',
      'derivative_http_content_type',
      'derivative_exists_after_get',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the issue-bound read-only production health diagnostic.
 *
 * @group agency_project_tests
 * @group production_health_diagnostic
 */
final class ProductionHealthDiagnosticWorkflowTest extends TestCase {

  /**
   * The diagnostic control surface must remain closed to issue #590.
   */
  public function testControlSurfaceIsIssueAndActorBound(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-health-diagnostic.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('/agency-production-health diagnose', $workflow);
    self::assertStringContainsString("github.actor == 'E-merging-digital'", $workflow);
    self::assertStringContainsString("ISSUE_NUMBER\" == '590'", $workflow);
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString('runs-on: ubuntu-24.04', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Only the existing SSH secrets may cross the trusted GitHub-hosted boundary.
   */
  public function testUsesExistingProductionSshChannelOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-health-diagnostic.yml',
    );

    self::assertStringContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
    self::assertStringContainsString('secrets.SERVER_HOST', $workflow);
    self::assertStringContainsString('secrets.SERVER_USER', $workflow);
    self::assertStringNotContainsString('secrets.OPENAI', $workflow);
    self::assertStringNotContainsString('secrets.DRUPAL', $workflow);
  }

  /**
   * The remote command set must remain diagnostic and non-mutating.
   */
  public function testRemoteDiagnosticRemainsReadOnlyAndFixed(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-health-diagnostic.yml',
    );

    foreach ([
      'system.maintenance_mode',
      "pgrep -af '[d]eploy-production.sh'",
      'service_state nginx nginx_state',
      'service_state php8.4-fpm php84_fpm_state',
      "--resolve 'emergingdigital.be:443:127.0.0.1'",
      '/var/www/agency/shared/deployments.log',
      'https://emergingdigital.be/blog/checklist-avant-une-refonte-de-site-internet-12-points-verifier',
      'https://emergingdigital.be/en/blog/website-redesign-checklist-12-things-verify-you-start',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'state:set',
      'systemctl restart',
      'systemctl reload',
      'service nginx restart',
      'service php',
      'deploy-production.sh main',
      'drush cr',
      'drush cim',
      'drush updb',
      'sudo ',
      'git pull',
      'git reset',
      'git checkout',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The workflow must preserve evidence and publish only bounded fields.
   */
  public function testDiagnosticEvidenceIsMachineReadable(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-production-health-diagnostic.yml',
    );

    self::assertStringContainsString('artifacts/production-health/result.json', $workflow);
    self::assertStringContainsString('artifacts/production-health/diagnostic.txt', $workflow);
    self::assertStringContainsString('MAINTENANCE_STUCK', $workflow);
    self::assertStringContainsString('HTTP_503_LOCAL_AND_EXTERNAL', $workflow);
    self::assertStringContainsString('HTTP_503_EXTERNAL_ONLY', $workflow);
    self::assertStringContainsString('PUBLIC_HTTP_HEALTHY', $workflow);
  }

}

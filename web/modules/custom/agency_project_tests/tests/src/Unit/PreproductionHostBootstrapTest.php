<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the isolated PREPROD bootstrap and deployment contract.
 *
 * @group agency_project_tests
 */
final class PreproductionHostBootstrapTest extends TestCase {

  /**
   * Host bootstrap keeps the approved isolated runtime boundaries.
   */
  public function testHostBootstrapIsIsolatedAndFailSafe(): void {
    $root = dirname(DRUPAL_ROOT);
    $bootstrap = $root . '/scripts/preproduction/bootstrap-host.sh';
    $nginx = $root
      . '/scripts/preproduction/nginx-agency-preprod.conf.template';
    $settings = $root . '/scripts/preproduction/settings.php.template';

    foreach ([$bootstrap, $nginx, $settings] as $path) {
      self::assertFileExists($path);
    }

    $bootstrapContent = (string) file_get_contents($bootstrap);
    foreach ([
      'Ubuntu 24.04 LTS',
      'mariadb-11.8',
      'max_allowed_packet=64M',
      'php8.4-fpm',
      '/var/www/agency-preprod',
      'sendmail_path = /bin/true',
      'PREPROD_BASIC_AUTH_PASSWORD',
      'certbot --nginx',
      'ufw default deny incoming',
    ] as $expected) {
      self::assertStringContainsString($expected, $bootstrapContent);
    }

    $nginxContent = (string) file_get_contents($nginx);
    self::assertStringContainsString('auth_basic', $nginxContent);
    self::assertStringContainsString('auth_basic off', $nginxContent);
    self::assertStringContainsString('X-Robots-Tag', $nginxContent);
    self::assertStringContainsString('/health/live', $nginxContent);
    self::assertStringContainsString('/health/ready', $nginxContent);

    $settingsContent = (string) file_get_contents($settings);
    self::assertStringContainsString(
      "config_split.config_split.production']['status'] = FALSE",
      $settingsContent,
    );
    self::assertStringContainsString(
      "config_split.config_split.preproduction']['status'] = TRUE",
      $settingsContent,
    );
    self::assertStringContainsString(
      "automated_cron.settings']['interval'] = 0",
      $settingsContent,
    );
  }

  /**
   * PREPROD consumes an immutable candidate instead of rebuilding it.
   */
  public function testCandidateDeployDoesNotRebuildApplication(): void {
    $root = dirname(DRUPAL_ROOT);
    $deploy = $root . '/scripts/preproduction/deploy-candidate.sh';
    self::assertFileExists($deploy);

    $content = (string) file_get_contents($deploy);
    foreach ([
      'candidate.json',
      'sha256sum -c',
      'ARTIFACTS_DIR="$SHARED_DIR/artifacts"',
      'config/splits/preproduction',
      'updb -y',
      'cim -y',
      'emerging:governed-content:validate',
      'emerging:governed-content --all --dry-run',
      'emerging:governed-content --all',
      "governed_content='PASS'",
      'OPENAI_API_KEY',
    ] as $expected) {
      self::assertStringContainsString($expected, $content);
    }

    self::assertStringNotContainsString('list --format=list', $content);
    self::assertStringNotContainsString('composer install', $content);
    self::assertStringNotContainsString('git clone', $content);
    self::assertStringNotContainsString('/var/www/agency/shared', $content);
  }

  /**
   * The deployment workflow is release-only and validates the real target.
   */
  public function testPreproductionWorkflowUsesExactCandidateEvidence(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/deploy-preproduction.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      'workflow_run:',
      'Build Agency release candidate',
      "startsWith(github.event.workflow_run.head_branch, 'release/')",
      'agency-release-candidate-${{ github.event.workflow_run.head_sha }}',
      'for endpoint in live ready',
      '$PREPROD_URL/health/$endpoint',
      'npm run browser:validate',
      'PREPROD_SSH_PRIVATE_KEY',
      'PREPROD_BASIC_AUTH_PASSWORD',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }

    self::assertStringNotContainsString('deploy-production.sh', $workflow);
    self::assertStringNotContainsString('/var/www/agency/shared', $workflow);
  }

  /**
   * Browser Validation can authenticate without persisting credentials.
   */
  public function testBrowserValidationSupportsProtectedPreproduction(): void {
    $root = dirname(DRUPAL_ROOT);
    $runner = (string) file_get_contents(
      $root . '/scripts/run-browser-validation.mjs',
    );
    $config = (string) file_get_contents($root . '/playwright.config.mjs');

    foreach ([$runner, $config] as $content) {
      self::assertStringContainsString(
        'BROWSER_VALIDATION_HTTP_USERNAME',
        $content,
      );
      self::assertStringContainsString(
        'BROWSER_VALIDATION_HTTP_PASSWORD',
        $content,
      );
    }
    self::assertStringContainsString('httpCredentials', $config);
  }

  /**
   * Browser proof uses the same responsive boundary as the theme.
   */
  public function testBrowserValidationUsesDeterministicNavigationMode(): void {
    $root = dirname(DRUPAL_ROOT);
    $spec = (string) file_get_contents(
      $root . '/tests/browser/public-blog.spec.mjs',
    );

    self::assertStringContainsString(
      "window.matchMedia('(max-width: 48rem)').matches",
      $spec,
    );
    self::assertStringNotContainsString('if (await toggle.isVisible())', $spec);
    self::assertStringContainsString(
      "const drawerLink = drawer.getByRole('link'",
      $spec,
    );
    self::assertStringContainsString(
      'await expect(drawerLink).toBeVisible()',
      $spec,
    );
  }

}

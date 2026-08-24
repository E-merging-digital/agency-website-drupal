<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the first real PREPROD host and immutable deploy path.
 *
 * @group agency_project_tests
 */
final class PreproductionRealHostWorkflowTest extends TestCase {

  /**
   * The host bootstrap keeps PREPROD isolated from production.
   */
  public function testHostBootstrapIsIsolatedAndIdempotent(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/preproduction/bootstrap-host.sh';
    self::assertFileExists($path);
    $script = (string) file_get_contents($path);

    foreach ([
      'VERSION_CODENAME:-}" != "noble"',
      '/var/www/agency-preprod',
      'agency-preprod',
      'php8.4-fpm',
      'mariadb-11.8',
      'max_allowed_packet = 64M',
      'bind-address = 127.0.0.1',
      'agency-preprod-smtp-sink.service',
      '127.0.0.1:1025',
      'systemctl disable --now postfix',
      'X-Robots-Tag "noindex, nofollow, noarchive"',
      'auth_basic "Agency PREPROD"',
      "config_split.config_split.production']['status'] = FALSE",
      "config_split.config_split.preproduction']['status'] = TRUE",
      "putenv('OPENAI_API_KEY')",
      "ufw allow 'Nginx Full'",
      'certbot --nginx',
    ] as $expected) {
      self::assertStringContainsString($expected, $script);
    }

    foreach ([
      '/var/www/agency/current',
      'EMERGING_DIGITAL_SMTP_',
      'BETTERUPTIME_API_TOKEN',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The PREPROD split routes mail only to the local sink.
   */
  public function testPreproductionSplitIsFailSafe(): void {
    $root = dirname(DRUPAL_ROOT);
    $definition = DrupalYaml::decode((string) file_get_contents(
      $root . '/config/sync/config_split.config_split.preproduction.yml',
    ));
    $mail = DrupalYaml::decode((string) file_get_contents(
      $root . '/config/splits/preproduction/system.mail.yml',
    ));

    self::assertIsArray($definition);
    self::assertIsArray($mail);
    self::assertFalse($definition['status']);
    self::assertSame('preproduction', $definition['id']);
    self::assertContains('system.mail', $definition['complete_list']);
    self::assertSame('smtp', $mail['mailer_dsn']['scheme']);
    self::assertSame('127.0.0.1', $mail['mailer_dsn']['host']);
    self::assertSame(1025, $mail['mailer_dsn']['port']);
    self::assertNull($mail['mailer_dsn']['user']);
    self::assertNull($mail['mailer_dsn']['password']);
  }

  /**
   * PREPROD consumes the immutable candidate without rebuilding it.
   */
  public function testArtifactDeployerVerifiesIdentityWithoutRebuild(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/preproduction/deploy-artifact.sh';
    self::assertFileExists($path);
    $script = (string) file_get_contents($path);

    foreach ([
      'EXPECTED_ARTIFACT_SHA256',
      'EXPECTED_COMPOSER_LOCK_SHA256',
      'artifact_sha256',
      'composer_lock_sha256',
      "metadata_branch\" == release/*",
      'vendor/autoload.php',
      'site:install --existing-config',
      'drush updb -y',
      'drush cim -y',
      'config/splits/preproduction',
      'drush cr',
      '127.0.0.1',
      '1025',
    ] as $expected) {
      self::assertStringContainsString($expected, $script);
    }

    foreach ([
      'composer install',
      'git clone',
      'config/splits/production',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * Governed workflows remain PREPROD-only and prove the real candidate.
   */
  public function testWorkflowsArePreproductionOnlyAndTerminallyValidated(): void {
    $root = dirname(DRUPAL_ROOT);
    $bootstrap = (string) file_get_contents(
      $root . '/.github/workflows/bootstrap-preproduction.yml',
    );
    $deploy = (string) file_get_contents(
      $root . '/.github/workflows/deploy-preproduction.yml',
    );
    self::assertIsArray(DrupalYaml::decode($bootstrap));
    self::assertIsArray(DrupalYaml::decode($deploy));

    foreach ([
      'workflow_dispatch:',
      'environment: preproduction',
      'PREPROD_BOOTSTRAP_SSH_PRIVATE_KEY',
      'PREPROD_DEPLOY_PUBLIC_KEY',
      'PREPROD_HTTP_PASSWORD',
      'preprod.emergingdigital.be',
    ] as $expected) {
      self::assertStringContainsString($expected, $bootstrap);
    }

    foreach ([
      'candidate_sha',
      'build-release-candidate.yml',
      'agency-release-candidate-$CANDIDATE_SHA',
      'artifact_sha256',
      'composer_lock_sha256',
      'PREPROD_DEPLOY_SSH_PRIVATE_KEY',
      'PREPROD_SERVER_HOST',
      'PREPROD_SERVER_USER',
      "probe live '/health/live' 3",
      "probe ready '/health/ready' 5",
      "'{\"status\":\"ok\"}'",
      "! grep -qi '^location:'",
      'PLAYWRIGHT_HTTP_USERNAME: preprod',
      'PLAYWRIGHT_HTTP_PASSWORD',
      'visual_desktop == "PASS"',
      'visual_mobile == "PASS"',
      'READY_FOR_PROD',
      'WAITING_FOR_HUMAN_GO',
    ] as $expected) {
      self::assertStringContainsString($expected, $deploy);
    }

    foreach ([$bootstrap, $deploy] as $workflow) {
      foreach ([
        '${{ secrets.SSH_PRIVATE_KEY }}',
        '${{ secrets.SERVER_HOST }}',
        '${{ secrets.SERVER_USER }}',
        'BETTERUPTIME_API_TOKEN',
        'deploy-production.yml',
      ] as $forbidden) {
        self::assertStringNotContainsString($forbidden, $workflow);
      }
    }
  }

  /**
   * Basic Auth can be tested without leaking request headers into traces.
   */
  public function testPlaywrightProtectedTargetDisablesTraceRetention(): void {
    $root = dirname(DRUPAL_ROOT);
    $config = (string) file_get_contents($root . '/playwright.config.mjs');

    self::assertStringContainsString('PLAYWRIGHT_HTTP_USERNAME', $config);
    self::assertStringContainsString('PLAYWRIGHT_HTTP_PASSWORD', $config);
    self::assertStringContainsString('httpCredentials: protectedTarget', $config);
    self::assertStringContainsString(
      "trace: protectedTarget ? 'off' : 'retain-on-failure'",
      $config,
    );
  }

}

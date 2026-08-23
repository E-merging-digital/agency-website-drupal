<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the read-only production Nginx/docroot diagnostic.
 *
 * @group agency_project_tests
 * @group production_nginx_docroot_diagnostic
 */
final class ProductionNginxDocrootDiagnosticWorkflowTest extends TestCase {

  public function testControlSurfaceIsPinned(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-nginx diagnose',
      'github.event.issue.number == 676',
      "github.actor == 'E-merging-digital'",
      "ISSUE_NUMBER\" == '676'",
      'currently on live main',
      'runs-on: ubuntu-24.04',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('github.event.inputs', $workflow);
  }

  public function testFixedReadOnlyFactsAreCollected(): void {
    $workflow = $this->workflow();

    foreach ([
      '/var/www/agency/current',
      '/var/www/agency/releases',
      '$CURRENT/web/index.php',
      '$CURRENT/web/robots.txt',
      'namei -l "$CURRENT/web/index.php"',
      'namei -l "$CURRENT/web/robots.txt"',
      '/etc/nginx/sites-enabled',
      'server_name|root|index|try_files|fastcgi_pass',
      '/var/log/nginx/error.log',
      'permission denied|/var/www/agency',
      "--resolve 'emergingdigital.be:443:127.0.0.1'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  public function testMutationSurfacesAreAbsentFromProductionBlock(): void {
    $workflow = $this->workflow();
    $remoteStart = strpos(
      $workflow,
      'ssh "$SERVER_USER@$SERVER_HOST" \'bash -s\' > "$raw"',
    );
    $remoteEnd = strpos($workflow, "          REMOTE\n", $remoteStart ?: 0);

    self::assertIsInt($remoteStart);
    self::assertIsInt($remoteEnd);
    $remote = substr($workflow, $remoteStart, $remoteEnd - $remoteStart);

    foreach ([
      'sudo ',
      'systemctl restart',
      'systemctl reload',
      'nginx -s reload',
      'chmod ',
      'chown ',
      'setfacl ',
      'drush cr',
      'drush cim',
      'drush updb',
      'state:set',
      'config:set',
      'git pull',
      'git reset',
      'git checkout',
      'deploy-production.sh main',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $remote);
    }
  }

  public function testDiagnosticClassificationsAreBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      'NGINX_ROOT_MISMATCH',
      'NGINX_PATH_OR_PERMISSION_ERROR',
      'CURRENT_RELEASE_WEB_MISSING',
      'STATIC_ACCESS_HEALTHY',
      'CURRENT_RELEASE_PERMISSION_REGRESSION',
      'NGINX_STATIC_ACCESS_DENIED',
      'artifacts/nginx-docroot/result.json',
      'expected_root_present',
      'previous_web_mode',
      'nginx_relevant_error_count',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-nginx-docroot-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

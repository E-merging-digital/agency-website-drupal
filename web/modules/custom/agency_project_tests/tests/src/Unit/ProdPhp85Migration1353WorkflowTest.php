<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the PLAN-only PROD PHP 8.5 migration capability from #1353.
 *
 * @group agency_project_tests
 */
final class ProdPhp85Migration1353WorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const WORKFLOW = '.github/workflows/prod-php85-migration-1353.yml';
  private const PLAN = 'scripts/production-php85-1353/remote-plan.sh';

  /**
   * Dispatcher is exact, owner-only, issue-bound and PLAN-only.
   */
  public function testDispatcherUsesExactPlanOnlyProdRoute(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $job = $dispatcher['jobs']['prod-php85-migration-1353-plan'] ?? NULL;
    self::assertIsArray($job);
    self::assertSame('./' . self::WORKFLOW, $job['uses'] ?? NULL);
    self::assertSame(
      ['actions' => 'read', 'contents' => 'read', 'issues' => 'write'],
      $job['permissions'] ?? NULL,
    );
    self::assertSame(
      ['SSH_PRIVATE_KEY', 'SERVER_HOST', 'SERVER_USER'],
      array_keys($job['secrets'] ?? []),
    );
    $condition = (string) ($job['if'] ?? '');
    foreach ([
      "github.event_name == 'issue_comment'",
      "github.event.action == 'created'",
      'github.event.issue.pull_request == null',
      'github.event.issue.number == 1353',
      "github.event.comment.author_association == 'OWNER'",
      "github.event.comment.user.login == 'E-merging-digital'",
      'github.event.comment.performed_via_github_app == null',
      "github.event.comment.body == '/agency-prod-php85-1353 plan'",
    ] as $required) {
      self::assertStringContainsString($required, $condition);
    }
  }

  /**
   * Reusable workflow exposes PLAN only with exact-main JIT authority.
   */
  public function testWorkflowIsPlanOnlyExactMainAndUsesExistingProdTrust(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);
    $on = $workflow['on'] ?? [];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayNotHasKey('workflow_dispatch', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertSame(
      ['validate-authority', 'plan'],
      array_keys($workflow['jobs'] ?? []),
    );

    foreach ([
      'test "$GITHUB_RUN_ATTEMPT" = \'1\'',
      'test "$ISSUE_NUMBER" = \'1353\'',
      'test "$COMMENT_LOGIN" = \'E-merging-digital\'',
      'test "$COMMENT_ASSOCIATION" = \'OWNER\'',
      'test "$COMMENT_VIA_APP" = \'false\'',
      'test "$WORKFLOW_SHA" = "$main_sha"',
      "test \"\$COMMENT_BODY\" = '/agency-prod-php85-1353 plan'",
      'scripts/production-ssh-trust/manage-known-host.sh PROVISION',
      '"$SERVER_USER@$SERVER_HOST"',
      'scripts/production-php85-1353/remote-plan.sh',
      'prod-php85-1353-plan-',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    foreach ([
      'workflow_dispatch',
      'apply plan_run=',
      'APPLY_CONSUMED',
      'root@$SERVER_HOST',
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }
  }

  /**
   * PLAN is read-only, bounded and decision-complete.
   */
  public function testPlanIsReadOnlyAndDecisionComplete(): void {
    $plan = $this->source(self::PLAN);

    foreach ([
      "ISSUE='1353'",
      "TARGET='PROD'",
      "MODE='PLAN'",
      "PROD_URL='https://emergingdigital.be'",
      "DRUPAL_ROOT='/var/www/agency/current'",
      "NGINX_SITES_ENABLED='/etc/nginx/sites-enabled'",
      "FPM84_POOL_DIR='/etc/php/8.4/fpm/pool.d'",
      'CURRENT_RELEASE',
      'OS_PRETTY_NAME',
      'VERSION_ID',
      'KERNEL_RUNNING',
      'REBOOT_REQUIRED',
      'CURRENT_PHP_CLI',
      'CURRENT_PHP_FPM',
      'CURRENT_PHP_FPM_SERVICE',
      'CURRENT_PROD_SOCKET',
      'NGINX_SERVICE',
      'MARIADB_SERVICE',
      'MARIADB_VERSION',
      'FAILED_SYSTEMD_UNITS',
      'DRUPAL_HEALTH',
      'PUBLIC_HEALTH',
      'DISK_AVAILABLE',
      'PHP85_PACKAGE_CANDIDATES',
      'PHP85_INSTALL_SIMULATION',
      'PHP85_INSTALLABLE',
      'PHP84_PACKAGES_PRESENT',
      'PHP84_SERVICE_ACTIVE',
      'NGINX_VHOST_PHP84_SOCKET_MATCH',
      'FPM84_POOL_CONTRACT',
      'UNEXPECTED_PACKAGE_REMOVALS',
      'UNRELATED_PACKAGE_UPGRADES',
      'ROLLBACK_PHP84_AVAILABLE',
      'apt-get --simulate install',
      'PACKAGE_ADDITIONS',
      'TRANSITIVE_ADDITIONS',
      'PLAN_DIGEST',
    ] as $required) {
      self::assertStringContainsString($required, $plan);
    }

    foreach ([
      'apt-get update',
      'apt upgrade',
      'full-upgrade',
      'dist-upgrade',
      'do-release-upgrade',
      'apt-get install -y',
      'systemctl restart',
      'systemctl reload',
      'systemctl enable',
      'systemctl disable',
      'drush cr',
      'drush updb',
      'drush cim',
      'sudo ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $plan);
    }

    foreach ([
      'php8.5-bcmath',
      'php8.5-cli',
      'php8.5-common',
      'php8.5-curl',
      'php8.5-fpm',
      'php8.5-gd',
      'php8.5-intl',
      'php8.5-mbstring',
      'php8.5-mysql',
      'php8.5-xml',
      'php8.5-zip',
    ] as $package) {
      self::assertStringContainsString($package, $plan);
    }

    self::assertStringNotContainsString('php8.5-opcache', $plan);
    self::assertStringContainsString(
      "    'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB'])",
      $plan,
    );
    self::assertStringNotContainsString(
      "    'DISK_AVAILABLE_KB',",
      $plan,
    );
  }

  /**
   * PLAN shell remains syntactically valid.
   */
  public function testPlanShellSyntax(): void {
    $path = dirname(DRUPAL_ROOT) . '/' . self::PLAN;
    $output = [];
    $status = 1;
    exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
  }

  /**
   * Reads and parses a repository YAML file.
   *
   * @return array<string, mixed>
   *   The parsed YAML data.
   */
  private function parsed(string $path): array {
    $parsed = Yaml::decode($this->source($path));
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads a repository file.
   */
  private function source(string $path): string {
    $source = file_get_contents(dirname(DRUPAL_ROOT) . '/' . $path);
    self::assertIsString($source);
    return $source;
  }

}

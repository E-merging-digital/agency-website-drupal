<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed read-only host OS inventory capability from #1102.
 *
 * @group agency_project_tests
 */
final class HostOsInventory1102WorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  private const WORKFLOW = '.github/workflows/host-os-inventory.yml';

  private const RUNNER = 'scripts/runner/run-host-os-inventory.sh';

  /**
   * The command surface is exact, human, non-PR and bound only to #1101.
   */
  public function testCommandRouteIsExactAndIssueBound(): void {
    $dispatcher = $this->source(self::DISPATCHER);
    $parsed = $this->parsed(self::DISPATCHER);
    $job = $parsed['jobs']['host-os-inventory'] ?? NULL;

    self::assertIsArray($job);
    self::assertSame('./' . self::WORKFLOW, $job['uses'] ?? NULL);
    self::assertSame(
      ['actions' => 'read', 'contents' => 'read', 'issues' => 'write'],
      $job['permissions'] ?? NULL,
    );
    self::assertSame(
      [
        'PREPROD_SSH_PRIVATE_KEY',
        'PREPROD_SERVER_HOST',
        'SSH_PRIVATE_KEY',
        'SERVER_HOST',
        'SERVER_USER',
      ],
      array_keys($job['secrets'] ?? []),
    );

    $condition = (string) ($job['if'] ?? '');
    foreach ([
      "github.event_name == 'issue_comment'",
      "github.event.action == 'created'",
      'github.event.issue.pull_request == null',
      'github.event.issue.number == 1101',
      "github.event.comment.author_association == 'OWNER'",
      "github.event.comment.user.login == 'E-merging-digital'",
      "github.event.comment.body == '/agency-host-os-inventory audit'",
      'github.event.comment.performed_via_github_app == null',
    ] as $required) {
      self::assertStringContainsString($required, $condition);
    }

    self::assertStringContainsString('types: [created]', $dispatcher);
    self::assertStringNotContainsString(
      '"prefix":"/agency-host-os-inventory ',
      $dispatcher,
    );
  }

  /**
   * The reusable workflow exposes exactly the three governed read-only probes.
   */
  public function testWorkflowUsesTrustedSurfacesAndPinnedSsh(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);
    $on = $workflow['on'] ?? [];

    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayNotHasKey('workflow_dispatch', $on);

    $jobs = $workflow['jobs'] ?? [];
    foreach ([
      'validate-authority',
      'runner-inventory',
      'preprod-inventory',
      'prod-inventory',
      'aggregate',
    ] as $jobId) {
      self::assertArrayHasKey($jobId, $jobs);
    }

    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency', 'ddev', 'browser'],
      $jobs['runner-inventory']['runs-on'] ?? NULL,
    );
    self::assertSame(
      'ubuntu-24.04',
      $jobs['preprod-inventory']['runs-on'] ?? NULL,
    );
    self::assertSame(
      'ubuntu-24.04',
      $jobs['prod-inventory']['runs-on'] ?? NULL,
    );

    foreach ([
      'test "$EVENT_NAME" = \'issue_comment\'',
      'test "$EVENT_ACTION" = \'created\'',
      'test "$ISSUE_NUMBER" = \'1101\'',
      'test "$COMMENT_BODY" = \'/agency-host-os-inventory audit\'',
      'test "$COMMENT_LOGIN" = \'E-merging-digital\'',
      'test "$COMMENT_ASSOCIATION" = \'OWNER\'',
      'test "$COMMENT_VIA_APP" = \'false\'',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringContainsString(
      'scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION',
      $source,
    );
    self::assertStringContainsString(
      'scripts/production-ssh-trust/manage-known-host.sh PROVISION',
      $source,
    );
    self::assertStringContainsString(
      'agency-preprod@$PREPROD_SERVER_HOST',
      $source,
    );
    self::assertStringContainsString('$SERVER_USER@$SERVER_HOST', $source);
    self::assertStringNotContainsString('ssh-keyscan', $source);
    self::assertStringNotContainsString('secrets: inherit', $source);
    self::assertStringContainsString(
      'host-os-inventory-${{ github.run_id }}-${{ github.run_attempt }}',
      $source,
    );
    self::assertStringContainsString('surface_status:', $source);
    self::assertStringContainsString("overall='PARTIAL'", $source);
  }

  /**
   * APT metadata and host observations remain temporary and non-mutating.
   */
  public function testProbeIsBoundedAndMutationFree(): void {
    $runner = $this->source(self::RUNNER);

    foreach ([
      'mktemp -d',
      'Dir::State=$apt_root/state',
      'Dir::State::status=/var/lib/dpkg/status',
      'Dir::State::lists=$apt_root/state/lists',
      'Dir::Cache=$apt_root/cache',
      'apt-get "${apt_options[@]}" update',
      'rm -rf -- "$apt_root"',
      'systemctl --failed --no-legend --plain',
      'systemctl is-active',
      'dpkg-query',
      'uname -r',
      '/proc/meminfo',
      'ddev version',
      'docker info',
      'vendor/bin/drush status',
      'UPGRADABLE_PACKAGES',
      'FAILED_SYSTEMD_UNITS',
      'SECURITY_UPDATES_TOTAL',
      'KERNEL_INSTALLED_LATEST',
      'DRUPAL_HEALTH',
    ] as $required) {
      self::assertStringContainsString($required, $runner);
    }

    self::assertStringContainsString('head -n 30', $runner);
    self::assertStringContainsString('head -n 20', $runner);

    foreach ([
      'sudo ',
      'apt upgrade',
      'apt-get upgrade',
      'full-upgrade',
      'dist-upgrade',
      'do-release-upgrade',
      'systemctl restart',
      'systemctl start',
      'systemctl stop',
      'apt install',
      'apt-get install',
      'ddev upgrade',
      'ddev start',
      'service ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner);
    }

    self::assertDoesNotMatchRegularExpression(
      '/^\s*(reboot|shutdown)(\s|$)/m',
      $runner,
    );
    self::assertStringNotContainsString('printenv', $runner);
    self::assertStringNotContainsString('/etc/shadow', $runner);
    self::assertStringNotContainsString('settings.php', $runner);
  }

  /**
   * Reads one repository workflow structurally.
   */
  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

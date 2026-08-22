<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded production database packet recovery workflow.
 *
 * @group agency_project_tests
 * @group production_database_packet_recovery
 */
final class ProductionDatabasePacketRecoveryWorkflowTest extends TestCase {

  /**
   * Ensures the route is owner-only and pinned to issue 660.
   */
  public function testControlSurfaceIsPinned(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-db-fix preflight',
      '/agency-production-db-fix apply',
      'github.event.issue.number == 660',
      "github.actor == 'E-merging-digital'",
      'EVENT_DEFAULT_SHA',
      'Packet recovery must execute the workflow revision on live main.',
      'repos/$GITHUB_REPOSITORY/issues/$ISSUE_NUMBER',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('github.event.inputs', $workflow);
  }

  /**
   * Ensures the measured incident and target packet size cannot drift.
   */
  public function testPacketAndRuntimeAreFixed(): void {
    $workflow = $this->workflow();

    foreach ([
      '9188a2ebd6516a738be6df6f854794d41889aa90',
      "SOURCE_PACKET='16777216'",
      "TARGET_PACKET='67108864'",
      'max_allowed_packet=64M',
      '/etc/mysql/mariadb.conf.d/99-agency-max-allowed-packet.cnf',
      'cache_file_parsing',
      '15000000',
      '11.8.8-MariaDB-ubu2404',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'SET GLOBAL',
      'SET SESSION',
      'max_allowed_packet=128M',
      'max_allowed_packet=256M',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Ensures preflight is read-only and apply is independently gated.
   */
  public function testApplyRequiresBotAuthoredReadyPreflight(): void {
    $workflow = $this->workflow();

    foreach ([
      'Agency production DB packet fix preflight READY',
      'github-actions[bot]',
      'No bot-authored READY preflight exists for this main/runtime.',
      '[[ "${{ steps.preflight.outputs.outcome }}" == \'READY\' ]]',
      'sudo -n -l',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    $preflightStart = strpos(
      $workflow,
      '      - name: Revalidate fixed production incident',
    );
    $gateStart = strpos(
      $workflow,
      '      - name: Require exact READY preflight before apply',
    );

    self::assertIsInt($preflightStart);
    self::assertIsInt($gateStart);

    $preflight = substr(
      $workflow,
      $preflightStart,
      $gateStart - $preflightStart,
    );

    foreach ([
      '/usr/bin/install',
      '/usr/bin/systemctl restart mariadb',
      '/usr/bin/rm',
      'vendor/bin/drush cr',
      'state:set',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $preflight);
    }
  }

  /**
   * Ensures the privileged mutation remains narrow and recoverable.
   */
  public function testApplyMutationIsBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      '/usr/bin/tar -C / -czf',
      '/usr/bin/install',
      '/usr/bin/systemctl restart mariadb',
      '/usr/bin/rm -f "$TARGET"',
      'issue660-etc-mysql-',
      'CONFIG_VERIFIED=0',
      'rollback_unverified_config',
      "printf '[mariadb]\\nmax_allowed_packet=64M\\n'",
      '[[ ! -e "$TARGET" ]]',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'apt-get',
      'apt ',
      'mariadb-upgrade',
      'git pull',
      'deploy-production.sh ',
      'chmod 777',
      'chown -R',
      '/etc/sudoers',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Ensures zero active deploy processes remain valid under pipefail.
   */
  public function testZeroDeployCountIsPipefailSafe(): void {
    $workflow = $this->workflow();
    $safeCount = "pgrep -fc '[d]eploy-production.sh' 2>/dev/null || true";
    $unsafeCount = "pgrep -af '[d]eploy-production.sh' 2>/dev/null | wc -l";

    self::assertSame(3, substr_count($workflow, $safeCount));
    self::assertStringNotContainsString($unsafeCount, $workflow);
  }

  /**
   * Ensures recovery is one cache rebuild followed by real HTTP checks.
   */
  public function testRecoveryAndHealthChecksAreFixed(): void {
    $workflow = $this->workflow();

    self::assertSame(1, substr_count($workflow, 'vendor/bin/drush cr'));

    foreach ([
      'https://emergingdigital.be/fr/blog/'
      . 'checklist-avant-une-refonte-de-site-internet-12-points-verifier',
      'https://emergingdigital.be/en/blog/'
      . 'website-redesign-checklist-12-things-verify-you-start',
      'public_fr_status',
      'public_en_status',
      'drupal_bootstrap',
      'maintenance_mode',
      'active_deploy_processes',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Loads and parses the trusted packet recovery workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-production-db-packet-recovery.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

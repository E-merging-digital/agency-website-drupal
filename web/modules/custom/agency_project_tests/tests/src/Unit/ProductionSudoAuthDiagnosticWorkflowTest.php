<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded production sudo authentication diagnostic.
 *
 * @group agency_project_tests
 * @group production_sudo_auth_diagnostic
 */
final class ProductionSudoAuthDiagnosticWorkflowTest extends TestCase {

  /**
   * Ensures the route is owner-only and pinned to issue 666.
   */
  public function testControlSurfaceIsPinned(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-sudo-auth diagnose',
      'github.event.issue.number == 666',
      "github.actor == 'E-merging-digital'",
      '[[ "$ISSUE_NUMBER" == \'666\' ]]',
      'EVENT_DEFAULT_SHA',
      'currently on live main',
      'runs-on: ubuntu-24.04',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('github.event.inputs', $workflow);
  }

  /**
   * Ensures the credential never becomes an argument or persisted artifact.
   */
  public function testCredentialHandlingIsEphemeral(): void {
    $workflow = $this->workflow();

    foreach ([
      'SERVER_SUDO_PASSWORD: ${{ secrets.SERVER_SUDO_PASSWORD }}',
      'printf \'%s\\n\' "$SERVER_SUDO_PASSWORD" |',
      "sudo -S -k -p '' /usr/bin/true",
      'sudo -k >/dev/null 2>&1 || true',
      'secret_present=0',
      'sudo_password_valid=0',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'echo "$SERVER_SUDO_PASSWORD"',
      'SERVER_SUDO_PASSWORD" >',
      'SERVER_SUDO_PASSWORD" >>',
      '/tmp/sudo',
      'password.txt',
      'credential.txt',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Ensures the remote privilege probe cannot perform useful root work.
   */
  public function testOnlyPrivilegedCommandIsTrue(): void {
    $workflow = $this->workflow();

    self::assertSame(
      1,
      substr_count($workflow, "sudo -S -k -p '' /usr/bin/true"),
    );

    foreach ([
      'systemctl restart',
      'systemctl reload',
      '/usr/bin/install',
      '/usr/bin/rm',
      '/usr/bin/tar',
      'apt-get',
      'mariadb ',
      'vendor/bin/drush cr',
      'state:set',
      'config:import',
      'chmod 777',
      '/etc/sudoers',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Ensures the probe is tied to the current failed production runtime.
   */
  public function testRuntimeGuardAndVerdictsAreFixed(): void {
    $workflow = $this->workflow();

    foreach ([
      '9188a2ebd6516a738be6df6f854794d41889aa90',
      "pgrep -fc '[d]eploy-production.sh'",
      'AVAILABLE|INVALID|ABSENT|BLOCKED',
      'ssh_channel_ok',
      'active_deploy_processes',
      'secret_present',
      'sudo_password_valid',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Ensures only a bounded JSON result can be retained.
   */
  public function testPublishedEvidenceIsBounded(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString(
      'path: artifacts/production-sudo-auth/result.json',
      $workflow,
    );
    self::assertStringNotContainsString(
      "path: artifacts/production-sudo-auth\n",
      $workflow,
    );
    self::assertStringContainsString(
      'The secret is never published or written to disk',
      $workflow,
    );
  }

  /**
   * Loads and parses the trusted sudo authentication diagnostic workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-production-sudo-auth-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

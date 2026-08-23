<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the temporary deterministic production sudo credential probe.
 *
 * @group agency_project_tests
 * @group production_sudo_push_probe
 */
final class TemporaryProductionSudoPushProbeWorkflowTest extends TestCase {

  /**
   * Ensures the temporary route runs only when its own file reaches main.
   */
  public function testPushSurfaceIsStrictlyBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      'push:',
      'branches:',
      '- main',
      '.github/workflows/temporary-production-sudo-push-probe-668.yml',
      "github.repository == 'E-merging-digital/agency-website-drupal'",
      "github.ref == 'refs/heads/main'",
      "github.actor == 'E-merging-digital'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('issue_comment:', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Ensures the live incident and owner gates remain fixed.
   */
  public function testIncidentGatesAreFixed(): void {
    $workflow = $this->workflow();

    foreach ([
      'for issue in 660 666 668',
      '9188a2ebd6516a738be6df6f854794d41889aa90',
      "pgrep -fc '[d]eploy-production.sh'",
      'AVAILABLE|INVALID|ABSENT|BLOCKED',
      'ssh_channel_ok',
      'active_deploy_processes',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Ensures the credential is ephemeral and never exposed.
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
   * Ensures no useful root or application mutation can be performed.
   */
  public function testProbeCannotMutateProduction(): void {
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
   * Ensures only bounded scalar evidence is retained and published.
   */
  public function testEvidenceIsBounded(): void {
    $workflow = $this->workflow();

    foreach ([
      'artifacts/production-sudo-push-probe/result.json',
      'secret_present:$secret_present',
      'sudo_password_valid:$sudo_password_valid',
      'for issue in 666 668',
      'Read-only authentication probe.',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString(
      "path: artifacts/production-sudo-push-probe\n",
      $workflow,
    );
  }

  /**
   * Loads and parses the temporary production probe workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/temporary-production-sudo-push-probe-668.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

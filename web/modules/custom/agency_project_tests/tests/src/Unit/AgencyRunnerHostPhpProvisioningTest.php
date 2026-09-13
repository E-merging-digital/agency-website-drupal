<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded host-PHP provisioning contract for #1149.
 *
 * @group agency_project_tests
 */
final class AgencyRunnerHostPhpProvisioningTest extends TestCase {

  /**
   * Future runner bootstrap provisions and validates the distro PHP CLI.
   */
  public function testBootstrapProvisionsHostPhpCli(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/runner/bootstrap-agency-browser-runner.sh';
    $bootstrap = file_get_contents($path);
    self::assertIsString($bootstrap);

    self::assertStringContainsString(
      'apt-get install -y --no-install-recommends php-cli "${playwright_packages[@]}"',
      $bootstrap,
    );
    self::assertStringContainsString('command -v php', $bootstrap);
    self::assertStringContainsString('php --version', $bootstrap);
    self::assertStringContainsString('refusing replacement', $bootstrap);
    self::assertStringContainsString('RUNNER_LABELS="agency,ddev,browser"', $bootstrap);
    self::assertStringNotContainsString('add-apt-repository', $bootstrap);
    self::assertStringNotContainsString('ppa:', $bootstrap);
    $this->assertBashSyntax($path);
  }

  /**
   * Existing runners have a narrow, repeatable PHP-only repair surface.
   */
  public function testRepairScriptIsBoundedAndFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/runner/reconcile-agency-runner-host-php.sh';
    $repair = file_get_contents($path);
    self::assertIsString($repair);

    self::assertStringContainsString('if [[ "${EUID}" -ne 0 ]]; then', $repair);
    self::assertStringContainsString('source /etc/os-release', $repair);
    self::assertStringContainsString('"${ID:-}" != "ubuntu"', $repair);
    self::assertStringContainsString('"${VERSION_ID:-}" != "24.04"', $repair);
    self::assertStringContainsString("dpkg-query -W -f='\${Status}\\n' php-cli", $repair);
    self::assertSame(1, substr_count($repair, 'apt-get install -y --no-install-recommends php-cli'));
    self::assertStringContainsString('RUNNER_USER="agency-runner"', $repair);
    self::assertStringContainsString('PHP_VERSION_ID >= 80100', $repair);
    self::assertStringContainsString('php -l "$VERIFY_SEED_SCRIPT"', $repair);
    self::assertStringContainsString('runuser -u "$RUNNER_USER"', $repair);
    self::assertStringContainsString('AGENCY_RUNNER_PHP_COMMAND=PRESENT', $repair);

    foreach (['add-apt-repository', 'ppa:', './config.sh', './svc.sh', 'usermod', 'ddev ', 'docker '] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $repair);
    }
    $this->assertBashSyntax($path);
  }

  /**
   * Runs bash syntax validation without executing provisioning.
   */
  private function assertBashSyntax(string $path): void {
    $process = proc_open(
      ['bash', '-n', $path],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
    );
    self::assertIsResource($process);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    self::assertSame(0, proc_close($process), (string) $stderr);
    self::assertSame('', $stdout);
    self::assertSame('', $stderr);
  }

}

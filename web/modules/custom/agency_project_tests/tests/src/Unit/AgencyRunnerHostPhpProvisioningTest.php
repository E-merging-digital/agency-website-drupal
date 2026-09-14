<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded host-PHP 8.4 provisioning contract for #1151.
 *
 * @group agency_project_tests
 */
final class AgencyRunnerHostPhpProvisioningTest extends TestCase {

  /**
   * Future runner bootstrap provisions and validates explicit PHP CLI 8.4.
   */
  public function testBootstrapProvisionsHostPhpCli(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/runner/bootstrap-agency-browser-runner.sh';
    $bootstrap = file_get_contents($path);
    self::assertIsString($bootstrap);

    self::assertStringContainsString('apt-cache show php8.4-cli', $bootstrap);
    self::assertStringContainsString('add-apt-repository -y ppa:ondrej/php', $bootstrap);
    self::assertStringContainsString('apt-get install -y --no-install-recommends php8.4-cli', $bootstrap);
    self::assertStringContainsString('command -v php8.4', $bootstrap);
    self::assertStringContainsString('php8.4 --version', $bootstrap);
    self::assertStringContainsString('PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4', $bootstrap);
    self::assertStringContainsString('refusing replacement', $bootstrap);
    self::assertStringContainsString('RUNNER_LABELS="agency,ddev,browser"', $bootstrap);
    self::assertStringNotContainsString('update-alternatives', $bootstrap);
    $this->assertBashSyntax($path);
  }

  /**
   * Existing runners have a narrow, repeatable PHP 8.4-only repair surface.
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
    self::assertStringContainsString("dpkg-query -W -f='\${Status}\\n' php8.4-cli", $repair);
    self::assertStringContainsString('apt-cache show php8.4-cli', $repair);
    self::assertStringContainsString('add-apt-repository -y ppa:ondrej/php', $repair);
    self::assertSame(1, substr_count($repair, 'apt-get install -y --no-install-recommends php8.4-cli'));
    self::assertStringContainsString('RUNNER_USER="agency-runner"', $repair);
    self::assertStringContainsString('PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4', $repair);
    self::assertStringContainsString('php8.4 -l "$VERIFY_SEED_SCRIPT"', $repair);
    self::assertStringContainsString('runuser -u "$RUNNER_USER"', $repair);
    self::assertStringContainsString('AGENCY_RUNNER_PHP84_COMMAND=PRESENT', $repair);
    self::assertStringContainsString('GENERIC_PHP_ALTERNATIVE_MUTATION=NONE', $repair);
    self::assertStringNotContainsString('update-alternatives', $repair);

    foreach (['./config.sh', './svc.sh', 'usermod', 'ddev ', 'docker '] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $repair);
    }
    $this->assertBashSyntax($path);
  }

  /**
   * Reuses the repository's existing PHP 8.4 repository strategy.
   */
  public function testPhp84RepositoryStrategyMatchesPreproductionContract(): void {
    $root = dirname(DRUPAL_ROOT);
    $preprod = file_get_contents($root . '/scripts/preproduction/bootstrap-host.sh');
    $runner = file_get_contents($root . '/scripts/runner/bootstrap-agency-browser-runner.sh');
    $repair = file_get_contents($root . '/scripts/runner/reconcile-agency-runner-host-php.sh');
    self::assertIsString($preprod);
    self::assertIsString($runner);
    self::assertIsString($repair);

    foreach (['add-apt-repository -y ppa:ondrej/php', 'php8.4-cli'] as $contract) {
      self::assertStringContainsString($contract, $preprod);
      self::assertStringContainsString($contract, $runner);
      self::assertStringContainsString($contract, $repair);
    }
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

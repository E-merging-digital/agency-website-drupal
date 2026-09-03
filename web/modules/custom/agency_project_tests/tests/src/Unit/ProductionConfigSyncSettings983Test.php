<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Protects the deterministic production config-sync settings contract.
 *
 * @group agency_project_tests
 * @group production_config_sync_983
 */
final class ProductionConfigSyncSettings983Test extends TestCase {

  /**
   * The shared project scaffold and PREPROD use the same deterministic rule.
   */
  public function testRepositorySettingsUseDrupalRootConvention(): void {
    $expected = "$settings['config_sync_directory'] = dirname(DRUPAL_ROOT) . '/config/sync';";
    $base = $this->file('web/sites/default/settings.php');
    $preprod = $this->file('scripts/preproduction/settings.php.template');

    self::assertStringContainsString($expected, $base);
    self::assertStringNotContainsString(
      "$settings['config_sync_directory'] = '../config/sync';",
      $base,
    );
    self::assertStringContainsString($expected, $preprod);
    self::assertStringContainsString(
      "$config['config_split.config_split.production']['status'] = FALSE;",
      $preprod,
    );
    self::assertStringContainsString(
      "$config['config_split.config_split.preproduction']['status'] = TRUE;",
      $preprod,
    );
  }

  /**
   * The deploy converges only the server-owned settings before release switch.
   */
  public function testProductionDeployOwnsBoundedSettingsConvergence(): void {
    $deploy = $this->file('scripts/deploy-production.sh');

    foreach ([
      'PRODUCTION_SETTINGS_FILE="$SHARED_DIR/settings/settings.php"',
      'PRODUCTION_SETTINGS_CONVERGER="$NEW_RELEASE/scripts/production-settings/converge-config-sync-directory.sh"',
      'bash "$PRODUCTION_SETTINGS_CONVERGER" "$PRODUCTION_SETTINGS_FILE"',
      'ln -sfn "$SHARED_DIR/settings/settings.php" "$NEW_RELEASE/web/sites/default/settings.php"',
      '"$CURRENT_LINK/vendor/bin/drush" cim -y',
      '"$CURRENT_LINK/vendor/bin/drush" config:import --source="$PRODUCTION_SPLIT_DIR" --partial -y',
    ] as $required) {
      self::assertStringContainsString($required, $deploy);
    }

    $finalVerify = strpos($deploy, "verify_runtime_permissions\n\nif [[ ! -r \"$PRODUCTION_SETTINGS_CONVERGER\" ]]");
    $converge = strpos(
      $deploy,
      'bash "$PRODUCTION_SETTINGS_CONVERGER" "$PRODUCTION_SETTINGS_FILE"',
    );
    $switch = strpos($deploy, 'log "[deploy] Switch release"');

    self::assertIsInt($finalVerify);
    self::assertIsInt($converge);
    self::assertIsInt($switch);
    self::assertTrue($finalVerify < $converge);
    self::assertTrue($converge < $switch);
    self::assertStringNotContainsString('mkdir -p "$NEW_RELEASE/config/sync"', $deploy);
    self::assertStringNotContainsString('chmod 777', $deploy);
    self::assertStringNotContainsString(' config:export', $deploy);
    self::assertStringNotContainsString(' cex', $deploy);
  }

  /**
   * The release still packages the canonical config-sync directory.
   */
  public function testConfigSyncRemainsPackagedInRepositoryRelease(): void {
    $root = dirname(DRUPAL_ROOT);
    $configSync = $root . '/config/sync';

    self::assertDirectoryExists($configSync);
    $yaml = glob($configSync . '/*.yml');
    self::assertIsArray($yaml);
    self::assertNotEmpty($yaml);
  }

  /**
   * Real helper execution is independent from the caller working directory.
   */
  public function testConvergenceIsCwdIndependentAndIdempotent(): void {
    $sandbox = $this->sandbox();
    $settings = $sandbox . '/settings.php';
    $otherCwd = $sandbox . '/cwd';
    mkdir($otherCwd);
    file_put_contents(
      $settings,
      "<?php\n\$settings['config_sync_directory'] = '../config/sync';\n\$settings['secret_sentinel'] = 'keep-me';\n",
    );
    chmod($settings, 0640);

    $first = new Process(['bash', $this->helper(), $settings], $otherCwd);
    $first->run();
    self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
    self::assertStringContainsString(
      'PROD_CONFIG_SYNC_SETTING=DETERMINISTIC',
      $first->getOutput(),
    );
    self::assertStringContainsString('CWD_DEPENDENCY=NONE', $first->getOutput());

    $afterFirst = (string) file_get_contents($settings);
    self::assertStringContainsString(
      "$settings['config_sync_directory'] = dirname(DRUPAL_ROOT) . '/config/sync';",
      $afterFirst,
    );
    self::assertStringNotContainsString("../config/sync", $afterFirst);
    self::assertStringContainsString("'secret_sentinel'] = 'keep-me'", $afterFirst);
    self::assertSame(0640, fileperms($settings) & 0777);

    $second = new Process(['bash', $this->helper(), $settings], $sandbox);
    $second->run();
    self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
    self::assertSame($afterFirst, (string) file_get_contents($settings));
  }

  /**
   * Ambiguous shared settings fail closed without rewriting the source file.
   */
  public function testMultipleAssignmentsFailClosedWithoutMutation(): void {
    $sandbox = $this->sandbox();
    $settings = $sandbox . '/settings.php';
    $original = "<?php\n"
      . "\$settings['config_sync_directory'] = '../config/sync';\n"
      . "\$settings['config_sync_directory'] = '/tmp/other';\n";
    file_put_contents($settings, $original);

    $process = new Process(['bash', $this->helper(), $settings], $sandbox);
    $process->run();

    self::assertFalse($process->isSuccessful());
    self::assertStringContainsString(
      'Expected exactly one config_sync_directory assignment.',
      $process->getErrorOutput(),
    );
    self::assertSame($original, (string) file_get_contents($settings));
  }

  /**
   * Returns the repository-owned convergence helper.
   */
  private function helper(): string {
    $path = dirname(DRUPAL_ROOT)
      . '/scripts/production-settings/converge-config-sync-directory.sh';
    self::assertFileExists($path);
    return $path;
  }

  /**
   * Reads one repository file.
   */
  private function file(string $relativePath): string {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
  }

  /**
   * Creates one isolated temporary directory removed after the test.
   */
  private function sandbox(): string {
    $path = sys_get_temp_dir() . '/agency-983-' . bin2hex(random_bytes(8));
    self::assertTrue(mkdir($path, 0700));
    $this->registerCleanup($path);
    return $path;
  }

  /**
   * Registers recursive cleanup for a temporary sandbox.
   */
  private function registerCleanup(string $path): void {
    $this->addToAssertionCount(0);
    register_shutdown_function(static function () use ($path): void {
      if (!is_dir($path)) {
        return;
      }
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($iterator as $item) {
        if ($item->isDir()) {
          rmdir($item->getPathname());
        }
        else {
          unlink($item->getPathname());
        }
      }
      rmdir($path);
    });
  }

}

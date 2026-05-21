<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Couvre la séquence Config Split du script de déploiement production.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class DeployProductionConfigSplitTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Vérifie que le split production est importé après le config import global.
   */
  public function testProductionSplitIsImportedAfterGlobalConfigImport(): void {
    $project_root = dirname(__DIR__, 7);
    $script_path = $project_root . '/scripts/deploy-production.sh';

    self::assertFileExists($script_path);

    $script = file_get_contents($script_path);
    self::assertIsString($script);
    $global_import_command = '"$CURRENT_LINK/vendor/bin/drush" cim -y';
    $split_dir_command = 'PRODUCTION_SPLIT_DIR="$CURRENT_LINK/config/splits/production"';
    $split_import_command = '"$CURRENT_LINK/vendor/bin/drush" config:import'
      . ' --source="$PRODUCTION_SPLIT_DIR" --partial -y';
    $content_sync_command = '"$CURRENT_LINK/vendor/bin/drush" emerging:content-sync --all';

    self::assertStringContainsString('"$CURRENT_LINK/vendor/bin/drush" cim -y', $script);
    self::assertStringContainsString($split_dir_command, $script);
    self::assertStringContainsString($split_import_command, $script);
    self::assertStringContainsString($content_sync_command, $script);

    $global_import_position = strpos($script, $global_import_command);
    $split_import_position = strpos($script, $split_import_command);
    $content_sync_position = strpos($script, $content_sync_command);

    self::assertIsInt($global_import_position);
    self::assertIsInt($split_import_position);
    self::assertIsInt($content_sync_position);
    self::assertGreaterThan($global_import_position, $split_import_position);
    self::assertGreaterThan($split_import_position, $content_sync_position);
  }

}

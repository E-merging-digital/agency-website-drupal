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
   * Charge le script de déploiement production.
   */
  private function loadDeployProductionScript(): string {
    $project_root = dirname(__DIR__, 7);
    $script_path = $project_root . '/scripts/deploy-production.sh';

    self::assertFileExists($script_path);

    $script = file_get_contents($script_path);
    self::assertIsString($script);

    return $script;
  }

  /**
   * Vérifie que le split production est importé après le config import global.
   */
  public function testProductionSplitIsImportedAfterGlobalConfigImport(): void {
    $script = $this->loadDeployProductionScript();
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

  /**
   * Vérifie le garde-fou des fichiers publics partagés.
   */
  public function testPublicFilesSymlinkIsPreparedBeforeDrupalCommands(): void {
    $script = $this->loadDeployProductionScript();

    $prepare_command = 'prepare_public_files';
    $drush_status_command = '"$NEW_RELEASE/vendor/bin/drush" status >/dev/null';

    self::assertStringContainsString('SHARED_FILES_DIR="$SHARED_DIR/files"', $script);
    self::assertStringContainsString('RELEASE_FILES_LINK="$NEW_RELEASE/web/sites/default/files"', $script);
    self::assertStringContainsString('FILES_OWNER="deploy"', $script);
    self::assertStringContainsString('rm -rf "$RELEASE_FILES_LINK"', $script);
    self::assertStringContainsString('ln -sfn "$SHARED_FILES_DIR" "$RELEASE_FILES_LINK"', $script);
    self::assertStringContainsString('chown -R "${FILES_OWNER}:www-data" "$SHARED_FILES_DIR"', $script);
    self::assertStringContainsString('chgrp www-data "$SHARED_FILES_DIR"', $script);
    self::assertStringContainsString('chmod ug+rwX "$SHARED_FILES_DIR"', $script);
    self::assertStringContainsString('chmod g+s "$SHARED_FILES_DIR"', $script);
    self::assertStringContainsString('chmod -R ug+rwX "$SHARED_FILES_DIR"', $script);
    self::assertStringContainsString("find \"\$SHARED_FILES_DIR\" -type d -exec chmod g+s {} +", $script);
    self::assertStringContainsString('[[ ! -L "$RELEASE_FILES_LINK" ]]', $script);
    self::assertStringContainsString('readlink -f "$RELEASE_FILES_LINK"', $script);
    self::assertStringContainsString('stat -c \'%G\' "$SHARED_FILES_DIR"', $script);
    self::assertStringContainsString('stat -c \'%U\' "$SHARED_FILES_DIR"', $script);
    self::assertStringContainsString('group-writable', $script);

    $prepare_position = strrpos($script, $prepare_command);
    $drush_status_position = strpos($script, $drush_status_command);

    self::assertIsInt($prepare_position);
    self::assertIsInt($drush_status_position);
    self::assertLessThan($drush_status_position, $prepare_position);
  }

}

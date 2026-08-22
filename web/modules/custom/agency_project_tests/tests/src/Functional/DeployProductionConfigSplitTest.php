<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Couvre la séquence Config Split du script de déploiement production.
 */
#[Group('agency_project_tests')]
final class DeployProductionConfigSplitTest extends TestCase {

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
   * Vérifie que le split production est importé avant Governed Content.
   */
  public function testProductionSplitIsImportedBeforeGovernedContent(): void {
    $script = $this->loadDeployProductionScript();
    $global_import_command = '"$CURRENT_LINK/vendor/bin/drush" cim -y';
    $split_dir_command = 'PRODUCTION_SPLIT_DIR="$CURRENT_LINK/config/splits/production"';
    $split_import_command = '"$CURRENT_LINK/vendor/bin/drush" config:import'
      . ' --source="$PRODUCTION_SPLIT_DIR" --partial -y';
    $governed_content_command = '"$CURRENT_LINK/vendor/bin/drush" emerging:governed-content --all';

    self::assertStringContainsString($global_import_command, $script);
    self::assertStringContainsString($split_dir_command, $script);
    self::assertStringContainsString($split_import_command, $script);
    self::assertStringContainsString($governed_content_command, $script);
    self::assertStringNotContainsString(
      '"$CURRENT_LINK/vendor/bin/drush" emerging:content-sync --all',
      $script,
    );

    $global_import_position = strpos($script, $global_import_command);
    $split_import_position = strpos($script, $split_import_command);
    $governed_content_position = strpos($script, $governed_content_command);

    self::assertIsInt($global_import_position);
    self::assertIsInt($split_import_position);
    self::assertIsInt($governed_content_position);
    self::assertGreaterThan($global_import_position, $split_import_position);
    self::assertGreaterThan($split_import_position, $governed_content_position);
  }

  /**
   * Vérifie le garde-fou des fichiers publics partagés.
   */
  public function testPublicFilesSymlinkIsPreparedBeforeDrupalCommands(): void {
    $script = $this->loadDeployProductionScript();

    $prepare_command = 'prepare_public_files';
    $drush_status_command = 'vendor/bin/drush status --fields=bootstrap >/dev/null';

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

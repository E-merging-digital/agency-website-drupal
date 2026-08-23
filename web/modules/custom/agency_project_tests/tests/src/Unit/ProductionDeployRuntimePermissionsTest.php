<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects production deploy runtime permission invariants.
 *
 * @group agency_project_tests
 * @group production_deploy_runtime_permissions
 */
final class ProductionDeployRuntimePermissionsTest extends TestCase {

  /**
   * The deploy must neutralize a restrictive inherited umask before writes.
   */
  public function testUmaskIsSetBeforeReleaseCreation(): void {
    $script = $this->script();
    $strict = strpos($script, 'set -Eeuo pipefail');
    $umask = strpos($script, 'umask 022');
    $releaseCreation = strpos($script, 'mkdir -p "$NEW_RELEASE"');

    self::assertIsInt($strict);
    self::assertIsInt($umask);
    self::assertIsInt($releaseCreation);
    self::assertTrue($strict < $umask);
    self::assertTrue($umask < $releaseCreation);
  }

  /**
   * Runtime code is normalized without following shared symlinks.
   */
  public function testRuntimeNormalizationIsBounded(): void {
    $script = $this->script();

    foreach ([
      'normalize_runtime_permissions()',
      'chmod a+rx "$NEW_RELEASE"',
      'find "$NEW_RELEASE/vendor" "$NEW_RELEASE/web" -xdev -type d -exec chmod a+rx {} +',
      'find "$NEW_RELEASE/vendor" "$NEW_RELEASE/web" -xdev -type f -exec chmod a+r {} +',
      'assert_runtime_directory_accessible',
      'assert_runtime_file_readable',
      '"$NEW_RELEASE/web/index.php" "$NEW_RELEASE/web/robots.txt"',
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }

    self::assertStringNotContainsString('find -L', $script);
    self::assertStringNotContainsString('chmod -R a+r', $script);
    self::assertStringNotContainsString('chmod -R a+rx', $script);
  }

  /**
   * Normalization and a final verification must happen before current switches.
   */
  public function testRuntimePermissionsAreVerifiedBeforeSwitch(): void {
    $script = $this->script();
    $composer = strpos(
      $script,
      'composer --working-dir="$NEW_RELEASE" install --no-dev --optimize-autoloader',
    );
    $normalize = strpos($script, "normalize_runtime_permissions\n", $composer ?: 0);
    $publicFiles = strpos($script, "prepare_public_files\n", $normalize ?: 0);
    $finalVerify = strpos($script, "verify_runtime_permissions\n", $publicFiles ?: 0);
    $switch = strpos($script, 'log "[deploy] Switch release"');

    self::assertIsInt($composer);
    self::assertIsInt($normalize);
    self::assertIsInt($publicFiles);
    self::assertIsInt($finalVerify);
    self::assertIsInt($switch);
    self::assertTrue($composer < $normalize);
    self::assertTrue($normalize < $publicFiles);
    self::assertTrue($publicFiles < $finalVerify);
    self::assertTrue($finalVerify < $switch);
  }

  /**
   * Existing shared-files ownership and group-write handling stays explicit.
   */
  public function testSharedFilesPolicyRemainsSeparate(): void {
    $script = $this->script();

    foreach ([
      'SHARED_FILES_DIR="$SHARED_DIR/files"',
      'RELEASE_FILES_LINK="$NEW_RELEASE/web/sites/default/files"',
      'chgrp www-data "$SHARED_FILES_DIR"',
      'chmod ug+rwX "$SHARED_FILES_DIR"',
      'chmod g+s "$SHARED_FILES_DIR"',
      'ln -sfn "$SHARED_DIR/settings/settings.php" "$NEW_RELEASE/web/sites/default/settings.php"',
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }
  }

  /**
   * Loads the production deploy script.
   */
  private function script(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/deploy-production.sh';

    self::assertFileExists($path);
    return (string) file_get_contents($path);
  }

}

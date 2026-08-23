<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded production runtime permission recovery.
 *
 * @group agency_project_tests
 * @group production_runtime_permissions_recovery
 */
final class ProductionRuntimePermissionsRecoveryWorkflowTest extends TestCase {

  /**
   * The recovery surface is restricted to the owner and issue #678.
   */
  public function testControlSurfaceIsPinned(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-permissions preflight',
      '/agency-production-permissions apply',
      'github.event.issue.number == 678',
      "github.actor == 'E-merging-digital'",
      "ISSUE_NUMBER\" == '678'",
      'currently on live main',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('github.event.inputs', $workflow);
  }

  /**
   * Recovery is pinned to the already-proven release and production SHA.
   */
  public function testProductionIdentityIsExact(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString(
      "EXPECTED_RELEASE='/var/www/agency/releases/20260822135335'",
      $workflow,
    );
    self::assertStringContainsString(
      "EXPECTED_SHA='9188a2ebd6516a738be6df6f854794d41889aa90'",
      $workflow,
    );
    self::assertStringContainsString('$maintenance" == \'0\'', $workflow);
    self::assertStringContainsString('$deploy_count" == \'0\'', $workflow);
  }

  /**
   * Only runtime code permissions may be broadened during apply.
   */
  public function testApplyIsPermissionOnlyAndDoesNotFollowSharedSymlinks(): void {
    $workflow = $this->workflow();

    foreach ([
      'chmod a+rx "$release"',
      'find "$release/vendor" "$release/web" -xdev -type d -exec chmod a+rx {} +',
      'find "$release/vendor" "$release/web" -xdev -type f -exec chmod a+r {} +',
      "EXPECTED_SETTINGS='/var/www/agency/shared/settings/settings.php'",
      "EXPECTED_FILES='/var/www/agency/shared/files'",
      'settings_target_unchanged',
      'files_target_unchanged',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'sudo ',
      'systemctl restart',
      'systemctl reload',
      'nginx -s reload',
      'chown ',
      'chgrp ',
      'setfacl ',
      'drush cr',
      'drush cim',
      'drush updb',
      'state:set',
      'config:set',
      'deploy-production.sh main',
      'chmod -R',
      'find -L',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }

    self::assertStringNotContainsString(
      'chmod a+r "$EXPECTED_SETTINGS"',
      $workflow,
    );
    self::assertStringNotContainsString(
      'chmod a+r "$EXPECTED_FILES"',
      $workflow,
    );
  }

  /**
   * Apply must save permission evidence before the first production chmod.
   */
  public function testPermissionManifestPrecedesMutation(): void {
    $workflow = $this->workflow();
    $manifest = strpos($workflow, 'backup_manifest="$BACKUPS/issue678-permissions-$timestamp.tsv"');
    $manifestWrite = strpos($workflow, '} > "$backup_manifest"');
    $firstMutation = strpos($workflow, 'chmod a+rx "$release"');
    $postProbe = strpos($workflow, 'local_robots_after="$(http_local');

    self::assertIsInt($manifest);
    self::assertIsInt($manifestWrite);
    self::assertIsInt($firstMutation);
    self::assertIsInt($postProbe);
    self::assertLessThan($manifestWrite, $manifest);
    self::assertLessThan($firstMutation, $manifestWrite);
    self::assertLessThan($postProbe, $firstMutation);
  }

  /**
   * Success requires local and public HTTP recovery evidence.
   */
  public function testSuccessRequiresHttpAndSharedTargetEvidence(): void {
    $workflow = $this->workflow();

    foreach ([
      'local_robots_after',
      'local_root_after',
      'public_fr_after',
      'public_en_after',
      '$settings_before" == "$settings_after',
      '$files_before" == "$files_after',
      "verdict='PASS'",
      'agency-production-permissions-678-',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Loads and parses the workflow under test.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-runtime-permissions-recovery.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the exact-head self-hosted browser validation contract.
 *
 * @group agency_project_tests
 * @group governed_browser
 */
final class SelfHostedBrowserValidationWorkflowTest extends TestCase {

  /**
   * Database, config, governed content and browser gates stay ordered.
   */
  public function testDrupalRebuildRunsMaintenanceGatesBeforeBrowser(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/self-hosted-browser-validation.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);

    $installPosition = strpos(
      $workflow,
      'ddev composer install --no-interaction --no-progress --prefer-dist',
    );
    $siteInstallPosition = strpos(
      $workflow,
      'ddev drush site:install --existing-config -y',
    );
    $updbPosition = strpos($workflow, 'ddev drush updb -y');
    $cimPosition = strpos($workflow, 'ddev drush cim -y');
    $cachePosition = strpos($workflow, 'ddev drush cr');
    $governedPosition = strpos(
      $workflow,
      'ddev drush emerging:governed-content --all',
    );
    $browserPosition = strpos($workflow, 'npm run browser:validate');

    self::assertIsInt($installPosition);
    self::assertIsInt($siteInstallPosition);
    self::assertIsInt($updbPosition);
    self::assertIsInt($cimPosition);
    self::assertIsInt($cachePosition);
    self::assertIsInt($governedPosition);
    self::assertIsInt($browserPosition);

    self::assertGreaterThan($installPosition, $siteInstallPosition);
    self::assertGreaterThan($siteInstallPosition, $updbPosition);
    self::assertGreaterThan($updbPosition, $cimPosition);
    self::assertGreaterThan($cimPosition, $cachePosition);
    self::assertGreaterThan($cachePosition, $governedPosition);
    self::assertGreaterThan($governedPosition, $browserPosition);

    self::assertStringContainsString('ddev drush config:status', $workflow);
    self::assertStringContainsString(
      'bash scripts/runner/prove-governed-content-cli-facade.sh',
      $workflow,
    );
    self::assertStringContainsString(
      "context='agency/browser-validation'",
      $workflow,
    );
  }

}

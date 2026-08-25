<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the repository-owned GA4 environment and audience policy.
 *
 * @group agency_project_tests
 * @group google_tag_environment_policy
 */
final class GoogleTagEnvironmentPolicyTest extends TestCase {

  /**
   * PROD GA4 remains enabled only for anonymous users via a native condition.
   */
  public function testProductionContainerWhitelistsAnonymousRole(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/config/splits/production/'
      . 'google_tag.container.G-K5TDNZCPTY.69f8b7287a84a3.47771255.yml';

    self::assertFileExists($path);
    $config = Yaml::decode((string) file_get_contents($path));
    self::assertIsArray($config);
    self::assertTrue($config['status']);
    self::assertContains('G-K5TDNZCPTY', $config['tag_container_ids']);
    self::assertArrayHasKey('conditions', $config);
    self::assertNotSame([], $config['conditions']);
    self::assertSame([
      'id' => 'user_role',
      'negate' => FALSE,
      'context_mapping' => [
        'user' => '@user.current_user_context:current_user',
      ],
      'roles' => [
        'anonymous' => 'anonymous',
      ],
    ], $config['conditions']['user_role'] ?? NULL);
  }

  /**
   * Real PREPROD browser validation must fail if GA4 silence is lost.
   */
  public function testPreproductionBrowserProofRequiresZeroGa4Traffic(): void {
    $root = dirname(DRUPAL_ROOT);
    $spec = (string) file_get_contents(
      $root . '/tests/browser/public-blog.spec.mjs',
    );
    $audit = (string) file_get_contents(
      $root . '/tests/browser/support/browser-audit.mjs',
    );

    self::assertStringContainsString(
      "hostname === 'preprod.emergingdigital.be'",
      $spec,
    );
    self::assertStringContainsString('audit.analyticsRequests', $spec);
    self::assertStringContainsString('audit.analyticsMeasurementRequests', $spec);
    self::assertStringContainsString('dom.html', $spec);
    self::assertStringContainsString('googletagmanager.com', $audit);
    self::assertStringContainsString('google-analytics.com', $audit);
    self::assertStringContainsString("parsed.pathname.includes('/collect')", $audit);
    self::assertStringContainsString('G-K5TDNZCPTY', $audit);
  }

}

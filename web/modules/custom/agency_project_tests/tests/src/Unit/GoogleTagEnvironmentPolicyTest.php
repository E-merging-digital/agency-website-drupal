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
   * The real PREPROD browser workflow must enforce zero GA4 traffic.
   */
  public function testPreproductionBrowserProofRequiresZeroGa4Traffic(): void {
    $root = dirname(DRUP_ROOT);
  }

}

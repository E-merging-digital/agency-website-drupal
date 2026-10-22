<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the canonical MCP Service first-screen targeting.
 *
 * @group agency_project_tests
 * @group mcp_service_1420
 */
final class McpServiceHero1420Test extends TestCase {

  /**
   * Matches only the exact approved UUID in the full FR/EN Service view.
   */
  public function testUuidGuardAndOtherServiceRegression(): void {
    require_once DRUPAL_ROOT . '/themes/custom/emerging_digital/emerging_digital.theme';

    $uuid = '4081ff3d-bdce-49e1-90e3-17a7281d9880';
    self::assertTrue(\emerging_digital_is_mcp_service('service', $uuid, 'full', 'fr'));
    self::assertTrue(\emerging_digital_is_mcp_service('service', $uuid, 'full', 'en'));
    self::assertFalse(\emerging_digital_is_mcp_service('article', $uuid, 'full', 'fr'));
    self::assertFalse(\emerging_digital_is_mcp_service('service', '00000000-0000-0000-0000-000000000000', 'full', 'fr'));
    self::assertFalse(\emerging_digital_is_mcp_service('service', $uuid, 'teaser', 'fr'));
    self::assertFalse(\emerging_digital_is_mcp_service('service', $uuid, 'full', 'nl'));
    self::assertFalse(\emerging_digital_is_mcp_service('service', '', 'full', 'en'));
  }

  /**
   * Keeps original editorial fields and governed reusable components.
   */
  public function testFrEnCopyAndEditorialRenderContract(): void {
    require_once DRUPAL_ROOT . '/themes/custom/emerging_digital/emerging_digital.theme';

    $fr = \emerging_digital_mcp_service_hero_copy(FALSE);
    $en = \emerging_digital_mcp_service_hero_copy(TRUE);
    self::assertSame(array_keys($fr), array_keys($en));
    foreach ([$fr, $en] as $copy) {
      foreach ($copy as $label) {
        self::assertNotSame('', trim($label));
      }
    }
    self::assertNotSame($fr['hero_heading'], $en['hero_heading']);

    $twig = file_get_contents(DRUPAL_ROOT . '/themes/custom/emerging_digital/templates/content/node--service.html.twig');
    self::assertIsString($twig);
    self::assertStringContainsString("include('emerging_digital:hero'", $twig);
    self::assertStringContainsString("heading_level: 'h2'", $twig);
    self::assertStringContainsString("contact_qualification_cta.url", $twig);
    self::assertStringContainsString("{% else %}", $twig);
    self::assertSame(1, substr_count($twig, '{{ content.field_detailed_description }}'));
    self::assertStringNotContainsString('/node/43', $twig);
    self::assertStringNotContainsString('type=mcp', $twig);
  }

}

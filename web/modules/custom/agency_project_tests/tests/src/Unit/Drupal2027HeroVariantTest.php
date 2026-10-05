<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the governed hero illustration selected for Drupal 2027.
 *
 * @group agency_project_tests
 * @group drupal_2027
 */
final class Drupal2027HeroVariantTest extends TestCase {

  /**
   * Drupal 2027 reuses the existing Services visual context.
   */
  public function testDrupal2027UsesServicesHeroVariant(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/web/themes/custom/emerging_digital/emerging_digital.theme';

    self::assertFileExists($path);

    $theme = (string) file_get_contents($path);
    self::assertStringContainsString(
      "'/drupal-2027' => 'services'",
      $theme,
    );
    self::assertStringContainsString(
      "'services' => [",
      $theme,
    );
    self::assertStringContainsString(
      "'path' => 'images/services/services-page-hero.svg'",
      $theme,
    );
  }

}

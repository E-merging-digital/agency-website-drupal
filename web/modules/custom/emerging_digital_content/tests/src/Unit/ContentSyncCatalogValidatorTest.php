<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Unit;

use Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalog;
use Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalogEntry;
use Drupal\emerging_digital_content\ContentSync\Validator\ContentSyncCatalogValidator;
use PHPUnit\Framework\TestCase;

/**
 * Tests Content Sync catalog validation guards.
 *
 * @group emerging_digital_content
 */
final class ContentSyncCatalogValidatorTest extends TestCase {

  /**
   * Loads module classes when PHPUnit is run without Drupal's test config.
   */
  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();

    $module_path = dirname(__DIR__, 3);
    require_once $module_path . '/src/ContentSync/Catalog/ContentSyncCatalog.php';
    require_once $module_path . '/src/ContentSync/Catalog/ContentSyncCatalogEntry.php';
    require_once $module_path . '/src/ContentSync/Validator/ContentSyncCatalogValidator.php';
  }

  /**
   * Validates legacy UUID format and duplicate detection.
   */
  public function testLegacyUuidValidationGuardsHistoricalMappings(): void {
    $catalog = new ContentSyncCatalog(__DIR__, [
      new ContentSyncCatalogEntry([
        'id' => 'first',
        'entity_type' => 'node',
        'bundle' => 'page',
        'legacy_uuid' => '11111111-1111-1111-1111-111111111111',
        'translations' => [
          'fr' => ['alias' => '/first'],
        ],
        'components' => [
          [
            'id' => 'first.hero',
            'bundle' => 'hero',
            'legacy_uuid' => '22222222-2222-2222-2222-222222222222',
            'translations' => [
              'fr' => [],
            ],
          ],
        ],
      ], 0, NULL),
      new ContentSyncCatalogEntry([
        'id' => 'second',
        'entity_type' => 'node',
        'bundle' => 'page',
        'legacy_uuid' => '11111111-1111-1111-1111-111111111111',
        'translations' => [
          'fr' => ['alias' => '/second'],
        ],
        'components' => [
          [
            'id' => 'second.hero',
            'bundle' => 'hero',
            'legacy_uuid' => 'not-a-uuid',
            'translations' => [
              'fr' => [],
            ],
          ],
        ],
      ], 1, NULL),
    ]);

    $report = (new ContentSyncCatalogValidator())->validate($catalog);

    self::assertSame(2, $report['contents_found']);
    self::assertArrayHasKey('second#1', $report['invalid_contents']);
    self::assertContains(
      'Legacy UUID "11111111-1111-1111-1111-111111111111" is declared by '
      . 'both content "first" and content "second".',
      $report['errors'],
    );
    self::assertContains(
      'Content "second" component "second.hero" legacy_uuid "not-a-uuid" '
      . 'is not a valid UUID.',
      $report['errors'],
    );
  }

}

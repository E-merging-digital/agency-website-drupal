<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\locale\Gettext;

/**
 * Proves the durable French interface translation catalog through Drupal Locale.
 *
 * @group agency_operations
 */
final class AgencyOperationsTranslationCatalogTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
    'locale',
    'agency_operations',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('locale', [
      'locales_location',
      'locales_source',
      'locales_target',
    ]);
    ConfigurableLanguage::createFromLangcode('fr')->save();
  }

  /**
   * Imports the real PO catalog and resolves translations through Drupal.
   */
  public function testFrenchStatusCatalogIsRealDrupalTranslation(): void {
    $catalog = (object) [
      'uri' => dirname(__DIR__, 3)
        . '/translations/agency_operations.fr.po',
      'langcode' => 'fr',
    ];
    Gettext::fileToDatabase($catalog, []);

    $translator = $this->container->get('string_translation');
    $translator->reset();

    $expected = [
      'Operational' => 'Opérationnel',
      'Ready' => 'Prêt',
      'Preparing' => 'En préparation',
      'Blocked' => 'Bloqué',
      'Unavailable' => 'Indisponible',
      'Human action required' => 'Action humaine requise',
    ];

    foreach ($expected as $source => $translation) {
      self::assertSame(
        $source,
        (string) $translator->translate($source, [], ['langcode' => 'en']),
      );
      self::assertSame(
        $translation,
        (string) $translator->translate($source, [], ['langcode' => 'fr']),
      );
    }

    foreach ([
      'SOURCE_IMPLEMENTED',
      'REAL_EXECUTION_PROVEN',
      'SYNTHETICALLY_PROVEN',
      'EXECUTION_PENDING',
    ] as $technicalStatus) {
      self::assertSame(
        $technicalStatus,
        (string) $translator->translate(
          $technicalStatus,
          [],
          ['langcode' => 'fr'],
        ),
      );
    }
  }

}

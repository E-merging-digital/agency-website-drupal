<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the canonical source language of the AI bulk action config.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class AiBulkActionCanonicalConfigurationLanguageTest extends TestCase {

  /**
   * The module default and canonical base are EN while FR remains translated.
   */
  public function testCanonicalEnglishSourcePreservesFrenchTranslation(): void {
    $root = dirname(DRUPAL_ROOT);
    $relative = 'system.action.agency_ai_translate_nodes_bulk_action.yml';

    $default = DrupalYaml::decode((string) file_get_contents(
      $root . '/web/modules/custom/agency_ai_translation/config/install/' . $relative,
    ));
    $canonical = DrupalYaml::decode((string) file_get_contents(
      $root . '/config/sync/' . $relative,
    ));
    $french = DrupalYaml::decode((string) file_get_contents(
      $root . '/config/sync/language/fr/' . $relative,
    ));

    self::assertIsArray($default);
    self::assertIsArray($canonical);
    self::assertIsArray($french);

    foreach ([$default, $canonical] as $source) {
      self::assertSame('en', $source['langcode'] ?? NULL);
      self::assertSame(
        'Translate with AI to a target language',
        $source['label'] ?? NULL,
      );
      self::assertSame(
        'agency_ai_translate_nodes_bulk_action',
        $source['plugin'] ?? NULL,
      );
      self::assertSame('node', $source['type'] ?? NULL);
      self::assertSame('fr', $source['configuration']['source_langcode'] ?? NULL);
      self::assertSame('', $source['configuration']['target_langcode'] ?? NULL);
    }

    self::assertSame(
      ['label' => 'Traduire avec IA vers une langue cible'],
      $french,
    );
  }

}

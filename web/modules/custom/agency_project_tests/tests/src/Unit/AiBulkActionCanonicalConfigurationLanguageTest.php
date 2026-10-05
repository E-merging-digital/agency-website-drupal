<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects source defaults and materialized AI bulk-action language semantics.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class AiBulkActionCanonicalConfigurationLanguageTest extends TestCase {

  /**
   * Module source stays EN while the materialized repository is technical FR.
   */
  public function testSourceDefaultAndMaterializedPolicyStayDistinct(): void {
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
    $englishOverride = $root . '/config/sync/language/en/' . $relative;

    self::assertIsArray($default);
    self::assertIsArray($canonical);
    self::assertIsArray($french);

    self::assertSame('en', $default['langcode'] ?? NULL);
    self::assertSame(
      'Translate with AI to a target language',
      $default['label'] ?? NULL,
    );
    self::assertSame('fr', $canonical['langcode'] ?? NULL);
    self::assertSame(
      'Translate with AI to a target language',
      $canonical['label'] ?? NULL,
    );

    foreach ([$default, $canonical] as $source) {
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
    self::assertFileDoesNotExist($englishOverride);
  }

}

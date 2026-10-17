<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the native Metatag service-only configuration contract.
 *
 * @group agency_project_tests
 * @group agency_service_metatag
 */
final class ServiceMetatagConfigurationTest extends TestCase {

  /**
   * Reads the exact committed Drupal sync configuration.
   */
  private static function config(string $filename): array {
    $path = dirname(DRUPAL_ROOT) . '/config/sync/' . $filename;
    self::assertFileExists($path);
    $value = Yaml::parseFile($path);
    self::assertIsArray($value);
    return $value;
  }

  /**
   * The only stored Metatag field is optional and translatable on service.
   */
  public function testNativeFieldIsServiceOnlyAndTranslatable(): void {
    $storage = self::config('field.storage.node.field_metatags.yml');
    $field = self::config('field.field.node.service.field_metatags.yml');

    self::assertSame('node.field_metatags', $storage['id']);
    self::assertSame('metatag', $storage['type']);
    self::assertSame('metatag', $storage['module']);
    self::assertSame(1, $storage['cardinality']);
    self::assertTrue($storage['translatable']);
    self::assertContains('metatag', $storage['dependencies']['module']);

    self::assertSame('node.service.field_metatags', $field['id']);
    self::assertSame('service', $field['bundle']);
    self::assertSame('metatag', $field['field_type']);
    self::assertTrue($field['translatable']);
    self::assertFalse($field['required']);
    self::assertSame([], $field['default_value']);
    self::assertContains('field.storage.node.field_metatags', $field['dependencies']['config']);
    self::assertContains('node.type.service', $field['dependencies']['config']);

    $matches = glob(dirname(DRUPAL_ROOT) . '/config/sync/field.field.node.*.field_metatags.yml');
    self::assertIsArray($matches);
    self::assertCount(1, $matches);
    self::assertSame('field.field.node.service.field_metatags.yml', basename($matches[0]));

    $language = self::config('language.content_settings.node.service.yml');
    self::assertTrue($language['third_party_settings']['content_translation']['enabled']);
  }

  /**
   * Keep all prior editing controls and avoid printing Metatags in content.
   */
  public function testEditorAndViewDisplaysPreserveExistingContent(): void {
    $form = self::config('core.entity_form_display.node.service.default.yml');
    $view = self::config('core.entity_view_display.node.service.default.yml');
    self::assertSame('node.service.default', $form['id']);
    self::assertSame('service', $form['bundle']);
    self::assertSame('metatag_firehose', $form['content']['field_metatags']['type']);
    foreach ([
      'title' => 'string_textfield',
      'field_short_description' => 'text_textarea',
      'field_detailed_description' => 'text_textarea',
      'langcode' => 'language_select',
    ] as $name => $type) {
      self::assertSame($type, $form['content'][$name]['type']);
    }
    foreach (['translation', 'url_redirects', 'simple_sitemap', 'path'] as $name) {
      self::assertArrayHasKey($name, $form['content']);
    }
    foreach (['field_localisation', 'field_related_ai_features', 'field_sector', 'field_service_type'] as $name) {
      self::assertTrue($form['hidden'][$name]);
      self::assertTrue($view['hidden'][$name]);
    }
    self::assertSame('node.service.default', $view['id']);
    self::assertTrue($view['hidden']['field_metatags']);
    self::assertArrayNotHasKey('field_metatags', $view['content']);
    self::assertSame('text_default', $view['content']['field_short_description']['type']);
    self::assertSame('text_default', $view['content']['field_detailed_description']['type']);
    self::assertSame(0, $view['content']['field_short_description']['weight']);
    self::assertSame(1, $view['content']['field_detailed_description']['weight']);
  }

  /**
   * Existing bundle defaults and routing tags remain unchanged.
   */
  public function testDefaultSeoAndOtherBundlesRemainUnchanged(): void {
    $defaults = self::config('metatag.metatag_defaults.node__service.yml');
    $tags = $defaults['tags'];
    self::assertSame('[node:title] | [site:name]', $tags['title']);
    self::assertSame('[node:field_short_description:value]', $tags['description']);
    self::assertSame('[node:url]', $tags['canonical_url']);
    self::assertSame('[node:title] | [site:name]', $tags['og_title']);
    self::assertSame('[node:title] | [site:name]', $tags['twitter_cards_title']);

    $settings = self::config('metatag.settings.yml');
    foreach (['basic', 'advanced', 'open_graph', 'twitter_cards', 'schema_web_page'] as $group) {
      self::assertSame($group, $settings['entity_type_groups']['node']['service'][$group]);
    }
    self::assertTrue(self::config('core.entity_form_display.node.service.default.yml')['status']);
    self::assertSame('service', self::config('core.entity_form_display.node.service.default.yml')['bundle']);
    self::assertSame('metatag', self::config('field.field.node.service.field_metatags.yml')['field_type']);
  }

}

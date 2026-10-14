<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\metatag\Entity\MetatagDefaults;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves FR/EN persistence and inherited SEO tags in disposable fixtures.
 *
 * @group agency_project_tests
 * @group agency_service_metatag
 */
final class ServiceMetatagTranslationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'language',
    'content_translation',
    'metatag',
    'token',
  ];

  /**
   * Loads a committed field configuration into this disposable test database.
   */
  private static function loadSyncConfiguration(string $filename): array {
    $path = dirname(DRUPAL_ROOT) . '/config/sync/' . $filename;
    self::assertFileExists($path);
    $value = Yaml::parseFile($path);
    self::assertIsArray($value);
    return $value;
  }

  /**
   * Stored per-translation overrides beat defaults but never touch other tags.
   */
  public function testTranslatedOverridesAndEmptyFieldFallback(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'node', 'metatag']);

    foreach (['fr', 'en'] as $langcode) {
      if (ConfigurableLanguage::load($langcode) === NULL) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }
    NodeType::create(['type' => 'service', 'name' => 'Service'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->container->get('content_translation.manager')
      ->setEnabled('node', 'service', TRUE);

    FieldStorageConfig::create(self::loadSyncConfiguration('field.storage.node.field_metatags.yml'))->save();
    FieldConfig::create(self::loadSyncConfiguration('field.field.node.service.field_metatags.yml'))->save();

    $defaults = MetatagDefaults::load('node__service');
    if ($defaults === NULL) {
      $defaults = MetatagDefaults::create([
        'id' => 'node__service',
        'label' => 'Service',
        'status' => TRUE,
      ]);
    }
    $defaults->set('tags', [
      'title' => '[node:title] | [site:name]',
      'description' => 'INHERITED DESCRIPTION',
      'canonical_url' => '[node:url]',
      'og_title' => '[node:title] | [site:name]',
      'twitter_cards_title' => '[node:title] | [site:name]',
    ]);
    $defaults->save();

    $node = Node::create([
      'type' => 'service',
      'langcode' => 'fr',
      'title' => 'H1 français',
      'status' => 1,
      'field_metatags' => [
        'value' => json_encode(['title' => 'SEO français | E-merging Digital', 'description' => 'Description FR'], JSON_THROW_ON_ERROR),
      ],
    ]);
    $node->addTranslation('en', [
      'title' => 'English H1',
      'status' => 1,
      'field_metatags' => [
        'value' => json_encode(['title' => 'English SEO | E-merging Digital', 'description' => 'Description EN'], JSON_THROW_ON_ERROR),
      ],
    ]);
    $node->save();

    $saved = Node::load($node->id());
    self::assertNotNull($saved);
    $manager = $this->container->get('metatag.manager');
    foreach ([
      'fr' => ['H1 français', 'SEO français | E-merging Digital', 'Description FR'],
      'en' => ['English H1', 'English SEO | E-merging Digital', 'Description EN'],
    ] as $langcode => [$h1, $seoTitle, $description]) {
      $translation = $saved->getTranslation($langcode);
      self::assertSame($h1, $translation->label());
      self::assertNotSame($h1, $seoTitle);
      $overrides = $manager->tagsFromEntity($translation);
      self::assertSame($seoTitle, $overrides['title']);
      self::assertSame($description, $overrides['description']);
      self::assertArrayNotHasKey('canonical_url', $overrides);
      self::assertArrayNotHasKey('og_title', $overrides);
      self::assertArrayNotHasKey('twitter_cards_title', $overrides);

      $tags = $manager->tagsFromEntityWithDefaults($translation);
      self::assertSame($seoTitle, $tags['title']);
      self::assertSame($description, $tags['description']);
      self::assertSame('[node:url]', $tags['canonical_url']);
      self::assertSame('[node:title] | [site:name]', $tags['og_title']);
      self::assertSame('[node:title] | [site:name]', $tags['twitter_cards_title']);
    }

    $fallback = Node::create([
      'type' => 'service',
      'langcode' => 'fr',
      'title' => 'Other service',
      'status' => 1,
    ]);
    $fallback->save();
    self::assertSame([], $manager->tagsFromEntity($fallback));
    $fallbackTags = $manager->tagsFromEntityWithDefaults($fallback);
    self::assertSame('[node:title] | [site:name]', $fallbackTags['title']);
    self::assertSame('INHERITED DESCRIPTION', $fallbackTags['description']);

    $page = Node::create(['type' => 'page', 'title' => 'Unrelated page', 'status' => 1]);
    $page->save();
    self::assertFalse($page->hasField('field_metatags'));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Kernel;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\KernelTests\KernelTestBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;

/**
 * Tests materialization of the initial bilingual Blog categories.
 *
 * @group emerging_digital_content
 */
final class BlogCategoriesUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'taxonomy',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['system', 'language', 'taxonomy']);

    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }

    Vocabulary::create([
      'vid' => 'blog_categories',
      'name' => 'Blog categories',
    ])->save();

    require_once DRUPAL_ROOT . '/modules/custom/emerging_digital_content/emerging_digital_content.install';
  }

  /**
   * Ensures creation, translations, idempotence and unrelated-term safety.
   */
  public function testInitialBlogCategoriesAreMaterializedIdempotently(): void {
    $sentinel = Term::create([
      'vid' => 'blog_categories',
      'langcode' => 'fr',
      'name' => 'Catégorie éditoriale supplémentaire',
      'weight' => 99,
    ]);
    $sentinel->save();
    $sentinel_id = (int) $sentinel->id();

    $first = _emerging_digital_content_ensure_initial_blog_categories();
    self::assertSame(['created' => 5, 'updated' => 0], $first);

    $expected = [
      'd4390eaa-6097-4aee-89f8-eeed57401508' => [
        'Décisions web / agence senior',
        'Web decisions / senior agency',
        0,
      ],
      'e7610b1c-2723-491e-837f-b0eff435f507' => [
        'PHP / architecture / frameworks',
        'PHP / architecture / frameworks',
        1,
      ],
      '5d072809-6653-42a5-a404-bb6bb18a465e' => [
        'Drupal comme expertise forte',
        'Drupal expertise',
        2,
      ],
      'fc8248c8-015b-4215-9354-ef554a16416d' => [
        'IA encadrée',
        'Governed AI',
        3,
      ],
      'f2f77a08-e528-4ac7-9d78-24464bc99f21' => [
        'Qualité web / SEO / accessibilité',
        'Web quality / SEO / accessibility',
        4,
      ],
    ];

    $storage = $this->container->get('entity_type.manager')->getStorage('taxonomy_term');
    foreach ($expected as $uuid => [$fr, $en, $weight]) {
      $matches = array_values($storage->loadByProperties(['uuid' => $uuid]));
      self::assertCount(1, $matches);
      $term = $matches[0];

      self::assertSame('blog_categories', $term->bundle());
      self::assertSame('fr', $term->language()->getId());
      self::assertSame($fr, $term->label());
      self::assertSame($weight, (int) $term->get('weight')->value);
      self::assertTrue($term->isPublished());
      self::assertTrue($term->hasTranslation('en'));
      self::assertSame($en, $term->getTranslation('en')->label());
    }

    $second = _emerging_digital_content_ensure_initial_blog_categories();
    self::assertSame(['created' => 0, 'updated' => 0], $second);

    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', 'blog_categories')
      ->execute();
    self::assertCount(6, $ids);

    $sentinel_reloaded = $storage->load($sentinel_id);
    self::assertNotNull($sentinel_reloaded);
    self::assertSame('Catégorie éditoriale supplémentaire', $sentinel_reloaded->label());
    self::assertSame(99, (int) $sentinel_reloaded->get('weight')->value);
  }

  /**
   * Ensures an absent vocabulary fails closed instead of being recreated.
   */
  public function testMissingVocabularyFailsClosed(): void {
    $vocabulary = Vocabulary::load('blog_categories');
    self::assertNotNull($vocabulary);
    $vocabulary->delete();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Required blog_categories vocabulary does not exist.');

    _emerging_digital_content_ensure_initial_blog_categories();
  }

  /**
   * Ensures duplicate matching source labels fail closed.
   */
  public function testAmbiguousExistingLabelFailsClosed(): void {
    foreach ([10, 11] as $weight) {
      Term::create([
        'vid' => 'blog_categories',
        'langcode' => 'fr',
        'name' => 'Décisions web / agence senior',
        'weight' => $weight,
      ])->save();
    }

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Multiple Blog categories already use the FR label "Décisions web / agence senior".',
    );

    _emerging_digital_content_ensure_initial_blog_categories();
  }

}

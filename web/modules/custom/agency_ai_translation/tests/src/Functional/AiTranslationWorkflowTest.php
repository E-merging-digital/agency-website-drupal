<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_ai_translation\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\agency_ai_translation\Support\StaticTranslationHttpClient;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;

/**
 * Vérifie les workflows critiques de traduction IA.
 *
 * @group agency_ai_translation
 */
final class AiTranslationWorkflowTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_ai_translation',
    'content_translation',
    'language',
    'locale',
    'node',
    'path',
    'system',
    'user',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Utilisateur dédié aux scénarios de traduction.
   *
   * @var \Drupal\user\UserInterface
   */
  private UserInterface $translatorUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!ConfigurableLanguage::load('fr')) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }
    if (!ConfigurableLanguage::load('en')) {
      ConfigurableLanguage::createFromLangcode('en')->save();
    }

    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);

    $this->container->get('state')->set('agency_ai_translation.api_key', 'test-key');
    $this->container->set('http_client', new StaticTranslationHttpClient());

    $this->translatorUser = $this->drupalCreateUser([
      'access administration pages',
      'access content overview',
      'administer content types',
      'administer languages',
      'administer url aliases',
      'create page content',
      'edit any page content',
      'trigger ai translation',
    ]);
  }

  /**
   * Teste le workflow de traduction individuelle avec confirmation.
   */
  public function testSingleActionCreatesTranslationAndAlias(): void {
    $this->drupalLogin($this->translatorUser);

    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Bonjour monde',
      'langcode' => 'fr',
      'status' => 1,
    ]);

    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/fr/bonjour-monde',
      'langcode' => 'fr',
    ])->save();

    $this->drupalGet('/admin/content');
    $this->assertSession()->linkExists('Générer EN (IA)');

    $this->clickLink('Générer EN (IA)');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Source : FR. Cible : EN.');

    $this->submitForm([], 'Lancer la traduction IA');
    $this->assertSession()->statusCodeEquals(200);

    $reloaded = $this->container->get('entity_type.manager')->getStorage('node')->load($node->id());
    self::assertNotNull($reloaded);
    self::assertTrue($reloaded->hasTranslation('en'));

    $translation = $reloaded->getTranslation('en');
    self::assertStringStartsWith('EN: ', $translation->label());

    $enAliases = $this->container->get('entity_type.manager')->getStorage('path_alias')->loadByProperties([
      'path' => '/node/' . $node->id(),
      'langcode' => 'en',
    ]);

    if ($enAliases !== []) {
      $alias = reset($enAliases);
      self::assertNotFalse($alias);
      self::assertNotSame('/node/' . $node->id(), $alias->getAlias());
    }
  }

  /**
   * Teste l’action de masse depuis /admin/content.
   */
  public function testBulkActionCreatesTranslation(): void {
    $this->drupalLogin($this->translatorUser);

    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Contenu à traduire en masse',
      'langcode' => 'fr',
      'status' => 1,
    ]);

    $this->drupalGet('/admin/content');
    $this->assertSession()->fieldExists('Action');
    $this->assertSession()->fieldExists('Langue cible (IA)');

    $edit = [
      'nodes[' . $node->id() . ']' => TRUE,
      'action' => 'agency_ai_translate_nodes_bulk_action',
      'agency_ai_translation_target_langcode' => 'en',
    ];

    $this->submitForm($edit, 'Apply to selected items');

    $reloaded = $this->container->get('entity_type.manager')->getStorage('node')->load($node->id());
    self::assertNotNull($reloaded);
    self::assertTrue($reloaded->hasTranslation('en'));
    self::assertStringStartsWith('EN: ', $reloaded->getTranslation('en')->label());
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_ai_translation\Functional;

use Drupal\agency_ai_translation\Service\AiTranslationClient;
use Drupal\agency_ai_translation\Service\AiTranslationManager;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\agency_ai_translation\Support\StaticTranslationHttpClient;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Vérifie les workflows critiques de traduction IA.
 *
 * @group agency_ai_translation
 */
#[RunTestsInSeparateProcesses]
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

    if (!NodeType::load('page')) {
      NodeType::create([
        'type' => 'page',
        'name' => 'Page',
      ])->save();
    }

    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);

    $this->config('agency_ai_translation.settings')
      ->set('endpoint', 'https://api.openai.com/v1/chat/completions')
      ->set('model', 'gpt-4o-mini')
      ->set('openai_key_id', '')
      ->set('system_prompt', 'Test translation prompt.')
      ->save();

    $this->container->get('state')->set('agency_ai_translation.api_key', 'test-key');
    $testHttpClient = new StaticTranslationHttpClient();
    $this->container->set('http_client', $testHttpClient);
    $moduleHandler = $this->container->get('module_handler');
    $keyRepository = $moduleHandler->moduleExists('key')
      ? $this->container->get('key.repository')
      : NULL;

    $aiClient = new AiTranslationClient(
      $this->container->get('config.factory'),
      $this->container->get('language_manager'),
      $testHttpClient,
      $this->container->get('logger.channel.agency_ai_translation'),
      $this->container->get('state'),
      $keyRepository,
    );
    $this->container->set('agency_ai_translation.client', $aiClient);
    $pathautoGenerator = $moduleHandler->moduleExists('pathauto')
      ? $this->container->get('pathauto.generator')
      : NULL;

    $translationManager = new AiTranslationManager(
      $aiClient,
      $this->container->get('entity_field.manager'),
      $pathautoGenerator,
    );
    $this->container->set('agency_ai_translation.manager', $translationManager);

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
    $this->assertSession()->pageTextNotContains('Échec de la traduction IA');

    $nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
    $nodeStorage->resetCache([$node->id()]);
    $reloaded = $nodeStorage->load($node->id());
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
    $checkbox = $this->assertSession()->elementExists('xpath', sprintf('//tr[.//a[normalize-space()="%s"]]//input[@type="checkbox"]', $node->label()));
    $checkboxName = (string) $checkbox->getAttribute('name');
    self::assertNotSame('', $checkboxName);

    $edit = [
      $checkboxName => TRUE,
      'action' => 'agency_ai_translate_nodes_bulk_action',
      'agency_ai_translation_target_langcode' => 'en',
    ];

    $this->submitForm($edit, 'Apply to selected items');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('contenu en erreur');

    $nodeStorage = $this->container->get('entity_type.manager')->getStorage('node');
    $nodeStorage->resetCache([$node->id()]);
    $reloaded = $nodeStorage->load($node->id());
    self::assertNotNull($reloaded);
    self::assertTrue($reloaded->hasTranslation('en'));
    self::assertStringStartsWith('EN: ', $reloaded->getTranslation('en')->label());
  }

}

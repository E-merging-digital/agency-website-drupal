<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\block\Entity\Block;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Vérifie les URL du switcher de langue sur contenu traduit.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class LanguageSwitcherAliasTest extends BrowserTestBase {
  private const SWITCHER_BLOCK_SELECTOR = '[id*="block-test-language-switcher"]';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'content_translation',
    'language',
    'node',
    'path_alias',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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

    $this->config('system.site')->set('default_langcode', 'fr')->save();

    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['en' => 'en', 'fr' => ''])
      ->set('url.domains', ['en' => '', 'fr' => ''])
      ->set('selected_langcode', 'site_default')
      ->save();

    $this->config('language.types')
      ->set('all', [
        'language_interface',
        'language_content',
        'language_url',
      ])
      ->set('configurable', [
        'language_interface',
        'language_content',
      ])
      ->set('negotiation.language_interface.enabled', [
        'language-url' => -8,
        'language-selected' => -6,
      ])
      ->set('negotiation.language_content.enabled', [
        'language-content-entity' => -10,
        'language-url' => -8,
        'language-selected' => -6,
      ])
      ->set('negotiation.language_url.enabled', [
        'language-url' => -8,
      ])
      ->save();

    if (!NodeType::load('page')) {
      NodeType::create([
        'type' => 'page',
        'name' => 'Basic page',
      ])->save();
    }
    self::assertNotNull(NodeType::load('page'));

    $contentLanguageSettings = ContentLanguageSettings::loadByEntityTypeBundle('node', 'page');
    self::assertNotNull($contentLanguageSettings);
    $contentLanguageSettings
      ->setDefaultLangcode('fr')
      ->setLanguageAlterable(TRUE)
      ->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);

    drupal_flush_all_caches();

    Block::create([
      'id' => 'test_language_switcher',
      'theme' => $this->defaultTheme,
      'region' => 'sidebar_first',
      'plugin' => 'language_block:language_url',
      'weight' => 0,
      'visibility' => [],
      'settings' => [
        'id' => 'language_block:language_url',
        'label' => 'Language switcher',
        'label_display' => FALSE,
        'provider' => 'language',
      ],
    ])->save();
    drupal_flush_all_caches();
  }

  /**
   * Vérifie que le switcher cible l'alias traduit sans query string fallback.
   */
  public function testLanguageSwitcherUsesTranslatedAliases(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'cookies',
      'langcode' => 'fr',
      'status' => Node::PUBLISHED,
    ]);
    $node->save();

    $node->addTranslation('en', [
      'title' => 'cookie-policy',
      'status' => Node::PUBLISHED,
    ])->save();

    $node = Node::load($node->id());
    self::assertNotNull($node);
    self::assertSame('fr', $node->language()->getId());
    self::assertTrue($node->isPublished());
    self::assertTrue($node->hasTranslation('en'));

    $englishTranslation = $node->getTranslation('en');
    self::assertTrue($englishTranslation->isPublished());

    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/cookies',
      'langcode' => 'fr',
    ])->save();
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/cookie-policy',
      'langcode' => 'en',
    ])->save();

    drupal_flush_all_caches();

    /** @var \Drupal\path_alias\AliasRepositoryInterface $aliasRepository */
    $aliasRepository = $this->container->get('path_alias.repository');
    self::assertSame('/cookies', $aliasRepository->lookupBySystemPath('/node/' . $node->id(), 'fr')['alias'] ?? NULL);
    self::assertSame('/cookie-policy', $aliasRepository->lookupBySystemPath('/node/' . $node->id(), 'en')['alias'] ?? NULL);

    /** @var \Drupal\path_alias\AliasManagerInterface $aliasManager */
    $aliasManager = $this->container->get('path_alias.manager');
    self::assertSame('/node/' . $node->id(), $aliasManager->getPathByAlias('/cookies', 'fr'));
    self::assertSame('/node/' . $node->id(), $aliasManager->getPathByAlias('/cookie-policy', 'en'));

    /** @var \Drupal\Core\Language\LanguageManagerInterface $languageManager */
    $languageManager = $this->container->get('language_manager');
    $frenchLanguage = $languageManager->getLanguage('fr');
    $englishLanguage = $languageManager->getLanguage('en');
    self::assertNotNull($frenchLanguage);
    self::assertNotNull($englishLanguage);

    $frenchUrl = $node->toUrl('canonical', ['language' => $frenchLanguage]);
    $englishUrl = $englishTranslation->toUrl('canonical', ['language' => $englishLanguage]);
    self::assertStringContainsString('/cookies', $frenchUrl->toString());
    self::assertStringContainsString('/en/cookie-policy', $englishUrl->toString());

    $this->drupalGet($frenchUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('test-language-switcher');
    $this->assertSession()->elementExists('css', self::SWITCHER_BLOCK_SELECTOR);
    $frenchSwitcherHrefs = $this->getLanguageSwitcherHrefs();
    self::assertContains('/en/cookie-policy', $frenchSwitcherHrefs);
    foreach ($frenchSwitcherHrefs as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }

    $this->drupalGet($englishUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('test-language-switcher');
    $this->assertSession()->elementExists('css', self::SWITCHER_BLOCK_SELECTOR);
    $englishSwitcherHrefs = $this->getLanguageSwitcherHrefs();
    self::assertContains('/cookies', $englishSwitcherHrefs);
    foreach ($englishSwitcherHrefs as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }
  }

  /**
   * Vérifie que la langue non traduite n'affiche pas de faux lien actif.
   */
  public function testLanguageSwitcherDoesNotLinkMissingTranslation(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'cookies-only-fr',
      'langcode' => 'fr',
      'status' => Node::PUBLISHED,
    ]);
    $node->save();

    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/cookies-only-fr',
      'langcode' => 'fr',
    ])->save();

    drupal_flush_all_caches();

    /** @var \Drupal\Core\Language\LanguageManagerInterface $languageManager */
    $languageManager = $this->container->get('language_manager');
    $frenchLanguage = $languageManager->getLanguage('fr');
    self::assertNotNull($frenchLanguage);
    $frenchUrl = $node->toUrl('canonical', ['language' => $frenchLanguage]);
    self::assertStringContainsString('/cookies-only-fr', $frenchUrl->toString());

    $this->drupalGet($frenchUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('test-language-switcher');
    $this->assertSession()->elementExists('css', self::SWITCHER_BLOCK_SELECTOR);
    $switcherHrefs = $this->getLanguageSwitcherHrefs();
    self::assertNotContains('/en/cookies-only-fr', $switcherHrefs);
    foreach ($switcherHrefs as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }
  }

  /**
   * Récupère les href des liens du language switcher affiché.
   *
   * @return string[]
   *   Liste des href.
   */
  private function getLanguageSwitcherHrefs(): array {
    $switcherBlock = $this->getSession()
      ->getPage()
      ->find('css', self::SWITCHER_BLOCK_SELECTOR);
    self::assertNotNull($switcherBlock);

    $links = $switcherBlock->findAll('css', 'a[href]');
    $hrefs = [];
    foreach ($links as $link) {
      $href = (string) $link->getAttribute('href');
      if ($href !== '') {
        $hrefs[] = $href;
      }
    }
    return $hrefs;
  }

}

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
 * Vérifie le rendu réel du switcher sur contenu traduit/non traduit.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class LanguageSwitcherAliasTest extends BrowserTestBase {

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
  protected $defaultTheme = 'emerging_digital';

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
      ->set('url.prefixes', ['fr' => '', 'en' => 'en'])
      ->set('url.domains', ['fr' => '', 'en' => ''])
      ->save();

    if (!NodeType::load('page')) {
      NodeType::create([
        'type' => 'page',
        'name' => 'Basic page',
      ])->save();
    }

    $settings = ContentLanguageSettings::loadByEntityTypeBundle('node', 'page');
    self::assertNotNull($settings);
    $settings->setDefaultLangcode('fr')->setLanguageAlterable(TRUE)->save();
    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);

    Block::create([
      'id' => 'test_language_switcher',
      'theme' => $this->defaultTheme,
      'region' => 'header_language',
      'plugin' => 'language_block:language_content',
      'weight' => 0,
      'visibility' => [],
      'settings' => [
        'id' => 'language_block:language_content',
        'label' => 'Language switcher',
        'label_display' => FALSE,
        'provider' => 'language',
      ],
    ])->save();

    drupal_flush_all_caches();
  }

  /**
   * Vérifie le switcher sur contenu traduit FR/EN.
   */
  public function testTranslatedContentSwitcherLinksAndActiveLanguage(): void {
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

    $this->drupalGet('/cookies');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextContains('css', '.language-switcher__current', 'Français');
    $frenchLinks = $this->getSwitcherMenuLinks();
    self::assertContains('/en/cookie-policy', $frenchLinks);
    foreach ($frenchLinks as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }

    $this->drupalGet('/en/cookie-policy');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextContains('css', '.language-switcher__current', 'English');
    $englishLinks = $this->getSwitcherMenuLinks();
    self::assertContains('/cookies', $englishLinks);
    foreach ($englishLinks as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }
  }

  /**
   * Vérifie l'absence de faux lien EN sur contenu non traduit.
   */
  public function testUntranslatedContentDoesNotExposeFakeEnglishLink(): void {
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

    $this->drupalGet('/cookies-only-fr');
    $this->assertSession()->statusCodeEquals(200);

    $links = $this->getSwitcherMenuLinks();
    self::assertNotContains('/en/cookies-only-fr', $links);
    foreach ($links as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }
  }

  /**
   * Retourne les href des liens du menu du switcher.
   *
   * @return string[]
   *   Liens du menu.
   */
  private function getSwitcherMenuLinks(): array {
    $items = $this->getSession()->getPage()->findAll('css', '.language-switcher__menu a[href]');
    $hrefs = [];
    foreach ($items as $item) {
      $href = (string) $item->getAttribute('href');
      if ($href !== '') {
        $hrefs[] = $href;
      }
    }
    return $hrefs;
  }

}

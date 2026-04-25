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
    'agency_language_switcher',
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
    $frenchLinks = $this->getSwitcherMenuLinks();
    $foundExpectedEnglishLink = $this->containsPath($frenchLinks, '/en/cookie-policy');
    self::assertTrue(
      $foundExpectedEnglishLink,
      $this->buildSwitcherDebugMessage('/en/cookie-policy', $frenchLinks)
    );
    self::assertFalse($this->containsPath($frenchLinks, '/fr/cookie-policy'));
    self::assertFalse($this->containsPath($frenchLinks, '/cookies'));
    foreach ($frenchLinks as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }

    $this->drupalGet('/en/cookie-policy');
    $this->assertSession()->statusCodeEquals(200);
    $englishLinks = $this->getSwitcherMenuLinks();
    self::assertTrue($this->containsPath($englishLinks, '/cookies'));
    self::assertFalse($this->containsPath($englishLinks, '/en/cookie-policy'));
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
    self::assertFalse($this->containsPath($links, '/en/cookies-only-fr'));
    foreach ($links as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }
  }

  /**
   * Indique si une liste de liens contient un path donné.
   *
   * @param string[] $hrefs
   *   Liste des href.
   * @param string $expectedPath
   *   Path attendu.
   */
  private function containsPath(array $hrefs, string $expectedPath): bool {
    foreach ($hrefs as $href) {
      $path = parse_url($href, PHP_URL_PATH);
      if (is_string($path) && $path === $expectedPath) {
        return TRUE;
      }
      if ($href === $expectedPath) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Retourne les href des liens du menu du switcher.
   *
   * @return string[]
   *   Liens du menu.
   */
  private function getSwitcherMenuLinks(): array {
    $items = $this->getSession()->getPage()->findAll('css', '#block-test-language-switcher a[href]');
    $hrefs = [];
    foreach ($items as $item) {
      $href = (string) $item->getAttribute('href');
      if ($href !== '') {
        $hrefs[] = $href;
      }
    }
    return $hrefs;
  }

  /**
   * Construit un message d'erreur détaillé pour diagnostiquer les hrefs.
   *
   * @param string $expectedPath
   *   Lien attendu.
   * @param string[] $hrefs
   *   Hrefs collectés.
   */
  private function buildSwitcherDebugMessage(string $expectedPath, array $hrefs): string {
    $currentUrl = $this->getSession()->getCurrentUrl();
    $headerRegion = $this->getSession()->getPage()->find('css', '.page-header__aside');
    $headerSnippet = $headerRegion ? trim($headerRegion->getHtml()) : '[header_language not found]';
    return sprintf(
      'Expected language switcher link "%s" not found. Current URL: %s. Header snippet: %s. Actual hrefs: %s',
      $expectedPath,
      $currentUrl,
      $headerSnippet,
      implode(', ', $hrefs)
    );
  }

}

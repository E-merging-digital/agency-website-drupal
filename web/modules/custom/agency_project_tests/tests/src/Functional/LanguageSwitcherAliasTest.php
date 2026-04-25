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
    'lang_dropdown',
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

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();

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
    $settings
      ->setDefaultLangcode('fr')
      ->setLanguageAlterable(TRUE)
      ->save();

    $this->container
      ->get('content_translation.manager')
      ->setEnabled('node', 'page', TRUE);

    Block::create([
      'id' => 'test_language_switcher',
      'theme' => $this->defaultTheme,
      'region' => 'header_language',
      'plugin' => 'language_dropdown_block:language_content',
      'weight' => 0,
      'visibility' => [],
      'settings' => [
        'id' => 'language_dropdown_block:language_content',
        'label' => 'Language switcher',
        'label_display' => FALSE,
        'provider' => 'lang_dropdown',
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
    self::assertTrue(
      $this->containsPath($frenchLinks, '/en/cookie-policy'),
      $this->buildSwitcherDebugMessage('/en/cookie-policy', $frenchLinks)
    );

    foreach ($frenchLinks as $href) {
      self::assertStringNotContainsString('language_content_entity', $href);
    }

    $this->drupalGet('/en/cookie-policy');
    $this->assertSession()->statusCodeEquals(200);

    $englishLinks = $this->getSwitcherMenuLinks();
    self::assertTrue(
      $this->containsPath($englishLinks, '/cookies'),
      $this->buildSwitcherDebugMessage('/cookies', $englishLinks)
    );

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
   * Retourne toutes les valeurs de navigation exposées par le switcher.
   *
   * @return string[]
   *   Valeurs href/value collectées dans le header language.
   */
  private function getSwitcherMenuLinks(): array {
    $page = $this->getSession()->getPage();
    $container = $page->find('css', '.page-header__aside');

    if (!$container) {
      return [];
    }

    $items = $container->findAll(
      'css',
      implode(', ', [
        'a[href]',
        'select option[value]',
        'select[data-drupal-selector] option[value]',
        'input[value]',
      ])
    );

    $hrefs = [];
    foreach ($items as $item) {
      $href = (string) ($item->getAttribute('href') ?? $item->getAttribute('value'));
      if ($href !== '') {
        $hrefs[] = $this->normalizeSwitcherUrl($href);
      }
    }

    return array_values(array_unique(array_filter($hrefs)));
  }

  /**
   * Normalise les URLs collectées dans href/value.
   */
  private function normalizeSwitcherUrl(string $value): string {
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5);

    if ($value === '' || str_starts_with($value, 'javascript:')) {
      return '';
    }

    $path = parse_url($value, PHP_URL_PATH);
    if (is_string($path) && $path !== '') {
      $query = parse_url($value, PHP_URL_QUERY);
      return $query ? $path . '?' . $query : $path;
    }

    return $value;
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
    $page = $this->getSession()->getPage();
    $currentUrl = $this->getSession()->getCurrentUrl();
    $headerRegion = $page->find('css', '.page-header__aside');

    $headerSnippet = $headerRegion
      ? trim($headerRegion->getHtml())
      : '[header_language not found]';
    $pageHrefs = array_map(
      static fn($node) => (string) $node->getAttribute('href'),
      $page->findAll('css', 'a[href]')
    );
    $pageSelects = array_map(
      static fn($node) => trim($node->getOuterHtml()),
      $page->findAll('css', 'select')
    );
    $pageOptions = array_map(
      static fn($node) => (string) $node->getAttribute('value'),
      $page->findAll('css', 'option[value]')
    );
    $block = Block::load('test_language_switcher');
    $blockDebug = $block
      ? sprintf(
        'exists=yes, plugin=%s, theme=%s, region=%s',
        $block->getPluginId(),
        (string) $block->get('theme'),
        (string) $block->get('region')
      )
      : 'exists=no';

    return sprintf(
      'Expected: %s. Current URL: %s. Header snippet: %s. Block debug: %s. Actual hrefs: %s. Page a[href]: %s. Page selects: %s. Page option[value]: %s',
      $expectedPath,
      $currentUrl,
      $headerSnippet,
      $blockDebug,
      implode(', ', $hrefs),
      implode(', ', $pageHrefs),
      implode(' || ', $pageSelects),
      implode(', ', $pageOptions)
    );
  }

}

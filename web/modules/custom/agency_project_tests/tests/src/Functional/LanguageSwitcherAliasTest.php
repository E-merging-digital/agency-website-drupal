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

/**
 * Vérifie le rendu réel du switcher lang_dropdown.
 *
 * Couvre les cas de contenu traduit et non traduit.
 *
 * @group agency_project_tests
 */
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
        'showall' => 0,
        'tohome' => 0,
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

    $frenchLinks = $this->getSwitcherHrefs();
    self::assertTrue(
      $this->containsPath($frenchLinks, '/en/cookie-policy'),
      $this->buildSwitcherDebugMessage('/en/cookie-policy', $frenchLinks)
    );

    foreach ($frenchLinks as $href) {
      self::assertStringNotContainsString('language_content_entity', $href, $this->buildSwitcherDebugMessage('no language_content_entity query', $frenchLinks));
    }

    $this->drupalGet('/en/cookie-policy');
    $this->assertSession()->statusCodeEquals(200);

    $englishLinks = $this->getSwitcherHrefs();
    self::assertTrue(
      $this->containsPath($englishLinks, '/cookies'),
      $this->buildSwitcherDebugMessage('/cookies', $englishLinks)
    );

    foreach ($englishLinks as $href) {
      self::assertStringNotContainsString('language_content_entity', $href, $this->buildSwitcherDebugMessage('no language_content_entity query', $englishLinks));
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

    $links = $this->getSwitcherHrefs();

    self::assertFalse(
      $this->containsPath($links, '/en/cookies-only-fr'),
      $this->buildSwitcherDebugMessage('no fake EN link /en/cookies-only-fr', $links)
    );

    foreach ($links as $href) {
      self::assertStringNotContainsString('language_content_entity', $href, $this->buildSwitcherDebugMessage('no language_content_entity query', $links));
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
   * Retourne les href collectés depuis le conteneur réel du bloc.
   *
   * @return string[]
   *   Hrefs trouvés.
   */
  private function getSwitcherHrefs(): array {
    $page = $this->getSession()->getPage();
    $switcherContainer = $page->find('css', '#block-test-language-switcher');

    if (!$switcherContainer) {
      return [];
    }

    $items = $switcherContainer->findAll('css', 'a[href]');

    $hrefs = [];
    foreach ($items as $item) {
      $href = trim((string) $item->getAttribute('href'));
      if ($href !== '') {
        $hrefs[] = $href;
      }
    }

    return $hrefs;
  }

  /**
   * Construit un message d'erreur détaillé pour diagnostiquer les hrefs.
   *
   * @param string $expected
   *   L'attendu pour le contexte d'erreur.
   * @param string[] $hrefs
   *   Hrefs collectés.
   */
  private function buildSwitcherDebugMessage(string $expected, array $hrefs): string {
    $currentUrl = $this->getSession()->getCurrentUrl();
    $headerRegion = $this->getSession()
      ->getPage()
      ->find('css', '.page-header__aside');

    $headerSnippet = $headerRegion ? trim($headerRegion->getHtml()) : '[header_language not found]';

    return sprintf(
      'Expected: %s. Current URL: %s. Header snippet: %s. Actual hrefs: %s',
      $expected,
      $currentUrl,
      $headerSnippet,
      implode(', ', $hrefs)
    );
  }

}

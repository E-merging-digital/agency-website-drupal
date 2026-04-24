<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\block\Entity\Block;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
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

    $this->config('language.content_settings.node.page')
      ->set('third_party_settings.content_translation.enabled', TRUE)
      ->set('language_alterable', TRUE)
      ->save();

    Block::create([
      'id' => 'test_language_switcher',
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'plugin' => 'language_block:language_url',
      'visibility' => [],
      'settings' => [
        'id' => 'language_block:language_url',
        'label' => 'Language switcher',
        'label_display' => FALSE,
        'provider' => 'language',
      ],
    ])->save();
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
    $frenchCurrentUrl = $this->getSession()->getCurrentUrl();
    $frenchHtml = $this->getSession()->getPage()->getContent();
    fwrite(STDERR, sprintf("LanguageSwitcherAliasTest FR visited URL: %s\n", $frenchCurrentUrl));
    self::assertSame(
      200,
      $this->getSession()->getStatusCode(),
      sprintf(
        'Expected HTTP 200 for FR URL "%s" (generated: "%s"). HTML snippet: %s',
        $frenchCurrentUrl,
        $frenchUrl->toString(),
        mb_substr(trim(strip_tags($frenchHtml)), 0, 500)
      )
    );
    $this->assertSession()->linkByHrefExists('/en/cookie-policy');
    $this->assertStringNotContainsString(
      'language_content_entity=en',
      $frenchHtml
    );

    $this->drupalGet($englishUrl);
    $englishCurrentUrl = $this->getSession()->getCurrentUrl();
    $englishHtml = $this->getSession()->getPage()->getContent();
    fwrite(STDERR, sprintf("LanguageSwitcherAliasTest EN visited URL: %s\n", $englishCurrentUrl));
    self::assertSame(
      200,
      $this->getSession()->getStatusCode(),
      sprintf(
        'Expected HTTP 200 for EN URL "%s" (generated: "%s"). HTML snippet: %s',
        $englishCurrentUrl,
        $englishUrl->toString(),
        mb_substr(trim(strip_tags($englishHtml)), 0, 500)
      )
    );
    $this->assertSession()->linkByHrefExists('/cookies');
    $this->assertStringNotContainsString(
      'language_content_entity=fr',
      $englishHtml
    );
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;

/**
 * Couvre la logique du hook emerging_digital_language_switch_links_alter().
 *
 * @group agency_project_tests
 */
final class EmergingDigitalLanguageSwitchLinksAlterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
    'language',
    'node',
    'path_alias',
    'system',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    require_once DRUPAL_ROOT . '/themes/custom/emerging_digital/emerging_digital.theme';

    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'language', 'node']);

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

    drupal_flush_all_caches();
  }

  /**
   * Vérifie le mapping des liens FR/EN sur contenu traduit.
   */
  public function testAlterUsesTranslatedAliases(): void {
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

    $languageManager = $this->container->get('language_manager');
    $links = [
      'fr' => [
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()], [
          'language' => $languageManager->getLanguage('fr'),
          'query' => ['language_content_entity' => 'fr'],
        ]),
      ],
      'en' => [
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()], [
          'language' => $languageManager->getLanguage('en'),
          'query' => ['language_content_entity' => 'en'],
        ]),
      ],
    ];

    $currentUrl = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], [
      'language' => $languageManager->getLanguage('fr'),
    ]);

    emerging_digital_language_switch_links_alter($links, LanguageInterface::TYPE_URL, $currentUrl);

    self::assertArrayHasKey('url', $links['fr']);
    self::assertArrayHasKey('url', $links['en']);
    self::assertStringContainsString('/cookies', $links['fr']['url']->toString());
    self::assertStringContainsString('/en/cookie-policy', $links['en']['url']->toString());
    self::assertArrayNotHasKey('language_content_entity', (array) $links['fr']['url']->getOption('query'));
    self::assertArrayNotHasKey('language_content_entity', (array) $links['en']['url']->getOption('query'));
  }

  /**
   * Vérifie la suppression du lien EN quand la traduction n'existe pas.
   */
  public function testAlterRemovesMissingTranslationLink(): void {
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

    $languageManager = $this->container->get('language_manager');
    $links = [
      'fr' => [
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()], [
          'language' => $languageManager->getLanguage('fr'),
          'query' => ['language_content_entity' => 'fr'],
        ]),
      ],
      'en' => [
        'url' => Url::fromRoute('entity.node.canonical', ['node' => $node->id()], [
          'language' => $languageManager->getLanguage('en'),
          'query' => ['language_content_entity' => 'en'],
        ]),
      ],
    ];

    $currentUrl = Url::fromRoute('entity.node.canonical', ['node' => $node->id()], [
      'language' => $languageManager->getLanguage('fr'),
    ]);

    emerging_digital_language_switch_links_alter($links, LanguageInterface::TYPE_URL, $currentUrl);

    self::assertArrayHasKey('url', $links['fr']);
    self::assertArrayNotHasKey('url', $links['en']);
    self::assertArrayNotHasKey('language_content_entity', (array) $links['fr']['url']->getOption('query'));
  }

}

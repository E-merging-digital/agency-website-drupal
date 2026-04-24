<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\block\Entity\Block;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\pathauto\Entity\PathautoPattern;
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
    'pathauto',
    'token',
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
      ->set('url.prefixes', ['en' => 'en', 'fr' => 'fr'])
      ->set('url.domains', ['en' => '', 'fr' => ''])
      ->save();

    $this->config('language.types')
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

    PathautoPattern::create([
      'id' => 'test_node_page_fr',
      'label' => 'Pattern FR page',
      'type' => 'canonical_entities:node',
      'pattern' => '[node:title]',
      'selection_criteria' => [
        'bundle' => [
          'id' => 'entity_bundle:node',
          'context_mapping' => ['node' => 'node'],
          'bundles' => ['page' => 'page'],
          'negate' => FALSE,
        ],
        'lang' => [
          'id' => 'language',
          'context_mapping' => ['language' => 'node:langcode:language'],
          'langcodes' => ['fr' => 'fr'],
          'negate' => FALSE,
        ],
      ],
      'selection_logic' => 'and',
      'weight' => -20,
    ])->save();

    PathautoPattern::create([
      'id' => 'test_node_page_en',
      'label' => 'Pattern EN page',
      'type' => 'canonical_entities:node',
      'pattern' => '[node:title]',
      'selection_criteria' => [
        'bundle' => [
          'id' => 'entity_bundle:node',
          'context_mapping' => ['node' => 'node'],
          'bundles' => ['page' => 'page'],
          'negate' => FALSE,
        ],
        'lang' => [
          'id' => 'language',
          'context_mapping' => ['language' => 'node:langcode:language'],
          'langcodes' => ['en' => 'en'],
          'negate' => FALSE,
        ],
      ],
      'selection_logic' => 'and',
      'weight' => -19,
    ])->save();

    Block::create([
      'id' => 'test_language_switcher',
      'theme' => 'stark',
      'region' => 'sidebar_first',
      'plugin' => 'language_block:language_content',
      'visibility' => [],
      'settings' => [
        'id' => 'language_block:language_content',
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

    /** @var \Drupal\pathauto\PathautoGeneratorInterface $generator */
    $generator = $this->container->get('pathauto.generator');
    $generator->updateEntityAlias($node, 'insert');
    $generator->updateEntityAlias($node->getTranslation('en'), 'insert');

    $this->drupalGet('/fr/cookies');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists('/en/cookie-policy');
    $this->assertStringNotContainsString(
      'language_content_entity=en',
      $this->getSession()->getPage()->getContent()
    );

    $this->drupalGet('/en/cookie-policy');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkByHrefExists('/fr/cookies');
    $this->assertStringNotContainsString(
      'language_content_entity=fr',
      $this->getSession()->getPage()->getContent()
    );
  }

}

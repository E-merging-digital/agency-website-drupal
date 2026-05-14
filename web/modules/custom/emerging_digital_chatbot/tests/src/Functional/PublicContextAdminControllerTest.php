<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the protected public context inspection screen.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class PublicContextAdminControllerTest extends BrowserTestBase {

  private const INSPECTION_PATH = '/admin/config/services/emerging-digital-chatbot/public-context';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'emerging_digital_chatbot',
    'field',
    'filter',
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
  protected $defaultTheme = 'stark';

  /**
   * Authorized admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  private UserInterface $authorizedUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!ConfigurableLanguage::load('fr')) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }

    $this->createPageTypeWithBodyField();

    $this->config('emerging_digital_chatbot.settings')
      ->set('mode', 'ai')
      ->set('future_ai.enabled', TRUE)
      ->set('future_ai.openai_key_id', 'secret-key-id-that-must-not-leak')
      ->set('future_ai.prompts.fr.system', 'Hidden French system prompt.')
      ->set('future_ai.prompts.en.system', 'Hidden English system prompt.')
      ->set('future_ai.context.max_context_chars', 1000)
      ->set('future_ai.context.allowed_public_paths.fr', ['/fr/public-context'])
      ->set('future_ai.context.allowed_public_paths.en', ['/en/public-context'])
      ->save();

    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Contexte public FR',
      'langcode' => 'fr',
      'status' => 1,
      'body' => [
        'value' => implode('', [
          '<h2>Drupal public FR</h2>',
          '<script>alert("bad")</script>',
          '<p>api_key = sk-test-secret-1234567890abcdef</p>',
          '<p>Texte public FR inspectable.</p>',
        ]),
        'format' => 'plain_text',
      ],
    ]);
    $node->addTranslation('en', [
      'title' => 'Public context EN',
      'status' => 1,
      'body' => [
        'value' => '<h2>Drupal public EN</h2><p>Public EN inspectable.</p>',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/public-context',
      'langcode' => 'fr',
    ])->save();
    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/public-context',
      'langcode' => 'en',
    ])->save();

    $authorizedUser = $this->drupalCreateUser([
      'access administration pages',
      'inspect emerging digital chatbot public context',
    ]);
    if (!$authorizedUser instanceof UserInterface) {
      self::fail('Could not create a user with the inspection permission.');
    }
    $this->authorizedUser = $authorizedUser;
  }

  /**
   * Tests anonymous and unauthorized users cannot inspect public context.
   */
  public function testAccessIsRestricted(): void {
    $this->drupalGet(self::INSPECTION_PATH);
    $this->assertSession()->statusCodeEquals(403);

    $userWithoutPermission = $this->drupalCreateUser(['access administration pages']);
    if (!$userWithoutPermission instanceof UserInterface) {
      self::fail('Could not create a user without the inspection permission.');
    }
    $this->drupalLogin($userWithoutPermission);

    $this->drupalGet(self::INSPECTION_PATH);
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * Tests an authorized user can inspect sanitized French public context.
   */
  public function testAuthorizedUserCanInspectFrenchContext(): void {
    $this->drupalLogin($this->authorizedUser);

    $this->drupalGet(self::INSPECTION_PATH, ['query' => ['langcode' => 'fr']]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Inspected language');
    $this->assertSession()->pageTextContains('fr');
    $this->assertSession()->pageTextContains('ready');
    $this->assertSession()->pageTextContains('/fr/public-context');
    $this->assertSession()->pageTextContains('Context length');
    $this->assertSession()->pageTextContains('max_context_chars');
    $this->assertSession()->pageTextContains('1000');
    $this->assertSession()->pageTextContains('Contexte public FR');
    $this->assertSession()->pageTextContains('Texte public FR inspectable.');

    $this->assertSession()->responseNotContains('<h2>Drupal public FR</h2>');
    $this->assertSession()->responseNotContains('&lt;h2&gt;Drupal public FR&lt;/h2&gt;');
    $this->assertSession()->responseNotContains('<script>alert("bad")</script>');
    $this->assertSession()->pageTextNotContains('alert("bad")');
    $this->assertSession()->pageTextNotContains('api_key');
    $this->assertSession()->pageTextNotContains('sk-test-secret');
    $this->assertSession()->pageTextNotContains('secret-key-id-that-must-not-leak');
    $this->assertSession()->pageTextNotContains('Hidden French system prompt.');
  }

  /**
   * Tests French and English contexts are inspectable independently.
   */
  public function testFrenchAndEnglishContextsAreInspectable(): void {
    $this->drupalLogin($this->authorizedUser);

    $this->drupalGet(self::INSPECTION_PATH, ['query' => ['langcode' => 'en']]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Inspected language');
    $this->assertSession()->pageTextContains('en');
    $this->assertSession()->pageTextContains('/en/public-context');
    $this->assertSession()->pageTextContains('Public context EN');
    $this->assertSession()->pageTextContains('Public EN inspectable.');
    $this->assertSession()->pageTextNotContains('Contexte public FR');
    $this->assertSession()->pageTextNotContains('Texte public FR inspectable.');
    $this->assertSession()->pageTextNotContains('Hidden English system prompt.');
  }

  /**
   * Tests the disabled and empty states are explicit.
   */
  public function testDisabledAndEmptyContextMessagesAreVisible(): void {
    $this->drupalLogin($this->authorizedUser);

    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.enabled', FALSE)
      ->save();

    $this->drupalGet(self::INSPECTION_PATH, ['query' => ['langcode' => 'fr']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('disabled');
    $this->assertSession()->pageTextContains('future_ai.enabled is disabled.');
    $this->assertSession()->pageTextContains('The public context is empty for this language.');
    $this->assertSession()->pageTextContains('/fr/public-context');

    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.enabled', TRUE)
      ->set('future_ai.context.allowed_public_paths.fr', [])
      ->save();

    $this->drupalGet(self::INSPECTION_PATH, ['query' => ['langcode' => 'fr']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('empty');
    $this->assertSession()->pageTextContains('No allowed public paths are configured for this language.');
    $this->assertSession()->pageTextContains('No public context text was generated.');
  }

  /**
   * Creates a translatable page bundle with a body field.
   */
  private function createPageTypeWithBodyField(): void {
    if (!NodeType::load('page')) {
      NodeType::create([
        'type' => 'page',
        'name' => 'Page',
      ])->save();
    }

    if (!FieldStorageConfig::loadByName('node', 'body')) {
      FieldStorageConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'type' => 'text_with_summary',
        'translatable' => TRUE,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', 'page', 'body')) {
      FieldConfig::create([
        'field_name' => 'body',
        'entity_type' => 'node',
        'bundle' => 'page',
        'label' => 'Body',
      ])->save();
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Functional;

use Drupal\emerging_digital_chatbot\FutureAi\FutureAiMonitoring;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\key\Entity\Key;
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
    'key',
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
   * Previous external AI allowance env value.
   */
  private string|false $previousAllowExternalAi;

  /**
   * Previous OpenAI key env value.
   */
  private string|false $previousOpenAiApiKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->previousAllowExternalAi = getenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');
    $this->previousOpenAiApiKey = getenv('OPENAI_API_KEY');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');
    putenv('OPENAI_API_KEY=sk-functional-secret-never-exposed');

    if (!ConfigurableLanguage::load('fr')) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }

    $this->createOpenAiKey();
    $this->createPageTypeWithBodyField();

    $this->config('emerging_digital_chatbot.settings')
      ->set('mode', 'ai')
      ->set('future_ai.enabled', TRUE)
      ->set('future_ai.openai_key_id', 'openai_api_key')
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

    $this->seedMonitoringSummary();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv(
      'EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI',
      $this->previousAllowExternalAi,
    );
    $this->restoreEnv('OPENAI_API_KEY', $this->previousOpenAiApiKey);

    parent::tearDown();
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
    $this->assertSession()->pageTextContains('Future AI provider status');
    $this->assertSession()->pageTextContains('Future AI state');
    $this->assertSession()->pageTextContains('enabled');
    $this->assertSession()->pageTextContains('Active provider');
    $this->assertSession()->pageTextContains('openai');
    $this->assertSession()->pageTextContains('Environment');
    $this->assertSession()->pageTextContains('blocked');
    $this->assertSession()->pageTextContains('Reason');
    $this->assertSession()->pageTextContains('environment_blocked');
    $this->assertSession()->pageTextContains('Key status');
    $this->assertSession()->pageTextContains('available');
    $this->assertSession()->pageTextContains('External calls allowed');
    $this->assertSession()->pageTextContains('no');
    $this->assertSession()->pageTextContains('Future AI monitoring');
    $this->assertSession()->pageTextContains('Period');
    $this->assertSession()->pageTextContains('since_last_cache_clear');
    $this->assertSession()->pageTextContains('Technical events');
    $this->assertSession()->pageTextContains('Successes');
    $this->assertSession()->pageTextContains('Blocked calls');
    $this->assertSession()->pageTextContains('Provider errors');
    $this->assertSession()->pageTextContains('Fallbacks returned');
    $this->assertSession()->pageTextContains('provider_timeout');
    $this->assertSession()->pageTextContains('fallback_used');
    $this->assertSession()->pageTextContains('Contexte public FR');
    $this->assertSession()->pageTextContains('Texte public FR inspectable.');

    $this->assertSession()->responseNotContains('<h2>Drupal public FR</h2>');
    $this->assertSession()->responseNotContains('&lt;h2&gt;Drupal public FR&lt;/h2&gt;');
    $this->assertSession()->responseNotContains('<script>alert("bad")</script>');
    $this->assertSession()->pageTextNotContains('alert("bad")');
    $this->assertSession()->pageTextNotContains('api_key');
    $this->assertSession()->pageTextNotContains('sk-test-secret');
    $this->assertSession()->pageTextNotContains('sk-functional-secret');
    $this->assertSession()->pageTextNotContains('openai_api_key');
    $this->assertSession()->pageTextNotContains('Hidden French system prompt.');
    $this->assertSession()->pageTextNotContains('visitor message');
    $this->assertSession()->pageTextNotContains('provider payload');
  }

  /**
   * Tests the provider status updates when the environment is explicitly open.
   */
  public function testAllowedEnvironmentStatusIsVisible(): void {
    $this->drupalLogin($this->authorizedUser);
    $settings['settings']['emerging_digital_chatbot.allow_external_ai'] = (object) [
      'value' => TRUE,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);

    $this->drupalGet(self::INSPECTION_PATH, ['query' => ['langcode' => 'fr']]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Future AI provider status');
    $this->assertSession()->pageTextContains('Environment');
    $this->assertSession()->pageTextContains('allowed');
    $this->assertSession()->pageTextContains('Key status');
    $this->assertSession()->pageTextContains('available');
    $this->assertSession()->pageTextContains('External calls allowed');
    $this->assertSession()->pageTextContains('yes');
    $this->assertSession()->pageTextContains('Reason');
    $this->assertSession()->pageTextContains('none');

    $this->assertSession()->pageTextNotContains('sk-functional-secret');
    $this->assertSession()->pageTextNotContains('openai_api_key');
    $this->assertSession()->pageTextNotContains('Hidden French system prompt.');
  }

  /**
   * Tests provider and Key blockers are visible without leaking identifiers.
   */
  public function testProviderAndKeyBlockersAreVisibleWithoutLeaks(): void {
    $this->drupalLogin($this->authorizedUser);
    $settings['settings']['emerging_digital_chatbot.allow_external_ai'] = (object) [
      'value' => TRUE,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);

    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'disabled_provider')
      ->set('future_ai.openai_key_id', 'missing_openai_key')
      ->save();

    $this->drupalGet(self::INSPECTION_PATH, ['query' => ['langcode' => 'fr']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Active provider');
    $this->assertSession()->pageTextContains('disabled_provider');
    $this->assertSession()->pageTextContains('Reason');
    $this->assertSession()->pageTextContains('unsupported_provider');
    $this->assertSession()->pageTextContains('Key status');
    $this->assertSession()->pageTextContains('missing');
    $this->assertSession()->pageTextContains('External calls allowed');
    $this->assertSession()->pageTextContains('no');
    $this->assertSession()->pageTextNotContains('missing_openai_key');

    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'openai_responses')
      ->save();

    $this->drupalGet(self::INSPECTION_PATH, ['query' => ['langcode' => 'fr']]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('openai');
    $this->assertSession()->pageTextContains('key_missing');
    $this->assertSession()->pageTextContains('missing');
    $this->assertSession()->pageTextContains('no');
    $this->assertSession()->pageTextNotContains('missing_openai_key');
    $this->assertSession()->pageTextNotContains('sk-functional-secret');
    $this->assertSession()->pageTextNotContains('Hidden French system prompt.');
  }

  /**
   * Tests the mock provider status is visible without exposing data.
   */
  public function testMockProviderStatusIsVisibleWithoutLeaks(): void {
    $this->drupalLogin($this->authorizedUser);
    $settings['settings']['emerging_digital_chatbot.allow_mock_provider'] = (object) [
      'value' => TRUE,
      'required' => TRUE,
    ];
    $this->writeSettings($settings);

    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'mock')
      ->set('future_ai.prompts.fr.system', 'Hidden mock system prompt.')
      ->save();

    $this->drupalGet(self::INSPECTION_PATH, ['query' => ['langcode' => 'fr']]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Future AI provider status');
    $this->assertSession()->pageTextContains('Active provider');
    $this->assertSession()->pageTextContains('mock');
    $this->assertSession()->pageTextContains('Environment');
    $this->assertSession()->pageTextContains('allowed');
    $this->assertSession()->pageTextContains('Reason');
    $this->assertSession()->pageTextContains('none');
    $this->assertSession()->pageTextContains('Key status');
    $this->assertSession()->pageTextContains('not_required');
    $this->assertSession()->pageTextContains('External calls allowed');
    $this->assertSession()->pageTextContains('no');

    $this->assertSession()->pageTextNotContains('sk-functional-secret');
    $this->assertSession()->pageTextNotContains('openai_api_key');
    $this->assertSession()->pageTextNotContains('Hidden mock system prompt.');
    $this->assertSession()->pageTextNotContains('visitor message');
    $this->assertSession()->pageTextNotContains('provider payload');
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
    $this->assertSession()->pageTextContains('future_ai_disabled');
    $this->assertSession()->pageTextContains('External calls allowed');
    $this->assertSession()->pageTextContains('no');
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
    $this->assertSession()->pageTextContains(
      'No allowed public paths are configured for this language.',
    );
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

  /**
   * Creates a Key entity backed by the OPENAI_API_KEY environment variable.
   */
  private function createOpenAiKey(): void {
    if (Key::load('openai_api_key')) {
      return;
    }

    Key::create([
      'id' => 'openai_api_key',
      'label' => 'OpenAI API key',
      'key_type' => 'authentication',
      'key_type_settings' => [],
      'key_provider' => 'env',
      'key_provider_settings' => [
        'env_variable' => 'OPENAI_API_KEY',
        'base64_encoded' => FALSE,
        'strip_line_breaks' => FALSE,
      ],
      'key_input' => 'none',
      'key_input_settings' => [],
    ])->save();
  }

  /**
   * Seeds anonymous technical counters for the admin summary assertions.
   */
  private function seedMonitoringSummary(): void {
    $monitoring = $this->container
      ->get('emerging_digital_chatbot.future_ai_monitoring');
    self::assertInstanceOf(FutureAiMonitoring::class, $monitoring);

    $monitoring->recordSuccess();
    $monitoring->recordBlocked('environment_blocked');
    $monitoring->recordProviderError('provider_timeout');
    $monitoring->recordFallback();
  }

  /**
   * Restores an environment variable after a test-only override.
   */
  private function restoreEnv(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }

    putenv($name . '=' . $value);
  }

}

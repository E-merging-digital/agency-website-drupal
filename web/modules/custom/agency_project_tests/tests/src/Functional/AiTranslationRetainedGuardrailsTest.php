<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\agency_ai_translation\Access\AiTranslationAccess;
use Drupal\agency_ai_translation\Service\AiTranslationClient;
use Drupal\agency_ai_translation\Service\AiTranslationManager;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves guardrails retained while standalone AI Translate lacks parity.
 *
 * @group agency_project_tests
 * @group agency_ai
 */
#[Group('agency_ai')]
#[RunTestsInSeparateProcesses]
final class AiTranslationRetainedGuardrailsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_ai_translation',
    'content_translation',
    'language',
    'node',
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

    foreach (['fr', 'en'] as $langcode) {
      if (!ConfigurableLanguage::load($langcode)) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }

    if (!NodeType::load('page')) {
      NodeType::create([
        'type' => 'page',
        'name' => 'Page',
      ])->save();
    }

    $this->container->get('content_translation.manager')->setEnabled('node', 'page', TRUE);
    $this->config('agency_ai_translation.settings')
      ->set('endpoint', 'https://provider.example.invalid/v1/chat/completions')
      ->set('model', 'deterministic-test-model')
      ->set('openai_key_id', '')
      ->set('system_prompt', 'Translate the supplied editorial content only.')
      ->save();
    $this->container->get('state')->set('agency_ai_translation.api_key', 'test-key');
  }

  /**
   * AI-created or AI-updated translations must require human publication.
   */
  public function testGeneratedTranslationIsUnpublished(): void {
    $manager = $this->createManager([
      new Response(200, [], json_encode([
        'choices' => [
          ['message' => ['content' => 'Human review required']],
        ],
      ], JSON_THROW_ON_ERROR)),
    ]);

    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Source publiée',
      'langcode' => 'fr',
      'status' => 1,
    ]);

    $manager->translateEntityToLanguage($node, 'en', 'fr');

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());
    self::assertNotNull($reloaded);
    self::assertTrue($reloaded->isPublished());
    self::assertTrue($reloaded->hasTranslation('en'));
    self::assertFalse($reloaded->getTranslation('en')->isPublished());
  }

  /**
   * Provider failure must not overwrite source or human-reviewed translation.
   */
  public function testProviderFailurePreservesExistingContent(): void {
    $node = $this->drupalCreateNode([
      'type' => 'page',
      'title' => 'Source française intacte',
      'langcode' => 'fr',
      'status' => 1,
    ]);
    $node->addTranslation('en', [
      'title' => 'Human reviewed English title',
      'status' => 0,
    ]);
    $node->save();

    $manager = $this->createManager([
      new ConnectException(
        'Provider unavailable',
        new Request('POST', 'https://provider.example.invalid/v1/chat/completions'),
      ),
    ]);

    try {
      $manager->translateEntityToLanguage($node, 'en', 'fr');
      self::fail('The deterministic provider failure must propagate.');
    }
    catch (ConnectException $exception) {
      self::assertSame('Provider unavailable', $exception->getMessage());
    }

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());
    self::assertNotNull($reloaded);
    self::assertSame('Source française intacte', $reloaded->label());
    self::assertTrue($reloaded->hasTranslation('en'));
    self::assertSame(
      'Human reviewed English title',
      $reloaded->getTranslation('en')->label(),
    );
  }

  /**
   * The AI permission never replaces native bundle translation permission.
   */
  public function testAccessRequiresNativeTranslationPermission(): void {
    $aiOnlyAccount = $this->drupalCreateUser([
      'create page content',
      'edit own page content',
      'trigger ai translation',
    ]);
    self::assertNotFalse($aiOnlyAccount);
    $aiOnlyNode = $this->drupalCreateNode([
      'type' => 'page',
      'uid' => $aiOnlyAccount->id(),
      'langcode' => 'fr',
    ]);
    self::assertFalse(AiTranslationAccess::allowed($aiOnlyNode, $aiOnlyAccount));

    $translatorAccount = $this->drupalCreateUser([
      'create page content',
      'edit own page content',
      'translate page node',
      'trigger ai translation',
    ]);
    self::assertNotFalse($translatorAccount);
    $translatorNode = $this->drupalCreateNode([
      'type' => 'page',
      'uid' => $translatorAccount->id(),
      'langcode' => 'fr',
    ]);
    self::assertTrue(AiTranslationAccess::allowed($translatorNode, $translatorAccount));
  }

  /**
   * Creates a translation manager with deterministic HTTP responses.
   *
   * @param array<int, mixed> $queue
   *   Guzzle MockHandler response/exception queue.
   */
  private function createManager(array $queue): AiTranslationManager {
    $httpClient = new Client([
      'handler' => HandlerStack::create(new MockHandler($queue)),
    ]);
    $client = new AiTranslationClient(
      $this->container->get('config.factory'),
      $this->container->get('language_manager'),
      $httpClient,
      $this->container->get('logger.channel.agency_ai_translation'),
      $this->container->get('state'),
      NULL,
      TRUE,
    );

    return new AiTranslationManager(
      $client,
      $this->container->get('entity_field.manager'),
    );
  }

}

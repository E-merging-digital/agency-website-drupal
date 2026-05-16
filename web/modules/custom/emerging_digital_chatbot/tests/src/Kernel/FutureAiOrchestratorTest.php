<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiEnvironmentGuard;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiGatewayInterface;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiMonitoring;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiOrchestrator;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderGatewayInterface;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderRegistry;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseReason;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseStatus;
use Drupal\emerging_digital_chatbot\FutureAi\MockFutureAiProviderGateway;
use Drupal\emerging_digital_chatbot\FutureAi\PublicAiContextProvider;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\key\KeyRepositoryInterface;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Tests deterministic Future AI orchestration.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class FutureAiOrchestratorTest extends KernelTestBase {

  private const API_KEY = 'sk-orchestrator-secret-never-exposed';

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
   * Previous external AI allowance env value.
   */
  private string|false $previousAllowExternalAi;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->previousAllowExternalAi = getenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER');

    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['emerging_digital_chatbot', 'filter', 'node']);
    $this->container
      ->get('cache.default')
      ->delete('emerging_digital_chatbot.future_ai_monitoring');

    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->createPageTypeWithBodyField();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv(
      'EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI',
      $this->previousAllowExternalAi,
    );
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER');

    parent::tearDown();
  }

  /**
   * Tests Future AI disabled falls back before provider use.
   */
  public function testDisabledFutureAiFallsBackWithoutProviderCall(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('mode', 'ai')
      ->set('future_ai.enabled', FALSE)
      ->save();

    $provider = new RecordingProviderGateway();
    $responseContract = $this->createOrchestrator($provider)->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal',
    ]);
    $response = $responseContract->toArray();

    self::assertInstanceOf(FutureAiResponse::class, $responseContract);
    self::assertSame(0, $provider->calls);
    self::assertTrue($response['fallback']);
    self::assertSame('guide_only', $response['status']);
    self::assertFalse($response['stored']);
    self::assertSame('fr', $response['langcode']);
    self::assertIsArray($response['futureAi']);
    self::assertFalse($response['futureAi']['enabled']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_future_ai_disabled']);
  }

  /**
   * Tests a blocked runtime prevents provider use.
   */
  public function testBlockedEnvironmentFallsBackWithoutProviderCall(): void {
    $this->configureEnabledFutureAi();
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');

    $provider = new RecordingProviderGateway();
    $response = $this->createOrchestrator($provider)->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal',
    ])->toArray();

    self::assertSame(0, $provider->calls);
    self::assertTrue($response['fallback']);
    self::assertSame('environment_blocked', $response['status']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_environment_blocked']);
  }

  /**
   * Tests a missing Key prevents provider use.
   */
  public function testMissingKeyFallsBackWithoutProviderCall(): void {
    $this->configureEnabledFutureAi();

    $provider = new RecordingProviderGateway();
    $response = $this
      ->createOrchestrator($provider, '')
      ->respond([
        'langcode' => 'fr',
        'message' => 'Question Drupal',
      ])->toArray();

    self::assertSame(0, $provider->calls);
    self::assertTrue($response['fallback']);
    self::assertSame('key_missing', $response['status']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_key_missing']);
  }

  /**
   * Tests unsupported providers prevent provider use.
   */
  public function testUnsupportedProviderFallsBackWithoutProviderCall(): void {
    $this->configureEnabledFutureAi();
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'future_provider_not_enabled')
      ->save();

    $provider = new RecordingProviderGateway();
    $response = $this->createOrchestrator($provider)->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal',
    ])->toArray();

    self::assertSame(0, $provider->calls);
    self::assertTrue($response['fallback']);
    self::assertSame('unsupported_provider', $response['status']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_unsupported_provider']);
  }

  /**
   * Tests missing provider services prevent provider dispatch.
   */
  public function testMissingProviderServiceFallsBack(): void {
    $this->configureEnabledFutureAi();

    $registry = new FutureAiProviderRegistry(
      $this->container->get('config.factory'),
      [],
    );
    $response = $this->createOrchestratorWithRegistry($registry)
      ->respond([
        'langcode' => 'fr',
        'message' => 'Question Drupal',
      ])->toArray();

    self::assertTrue($response['fallback']);
    self::assertSame('unsupported_provider', $response['status']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_unsupported_provider']);
  }

  /**
   * Tests empty public context prevents provider use.
   */
  public function testEmptyContextFallsBackWithoutProviderCall(): void {
    $this->configureEnabledFutureAi();

    $provider = new RecordingProviderGateway();
    $response = $this->createOrchestrator($provider)->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal',
    ])->toArray();

    self::assertSame(0, $provider->calls);
    self::assertTrue($response['fallback']);
    self::assertSame('context_empty', $response['status']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_context_empty']);
  }

  /**
   * Tests sensitive visitor input prevents provider use.
   */
  public function testSensitiveInputFallsBackWithoutProviderCall(): void {
    $this->configureEnabledFutureAi();
    $this->createPublicContextPage();

    $provider = new RecordingProviderGateway();
    $response = $this->createOrchestrator($provider)->respond([
      'langcode' => 'fr',
      'message' => '',
      'blocked_sensitive_input' => TRUE,
    ])->toArray();

    self::assertSame(0, $provider->calls);
    self::assertTrue($response['fallback']);
    self::assertSame('sensitive_input_blocked', $response['status']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_fallback_used']);
  }

  /**
   * Tests provider timeout maps to fallback and monitoring.
   */
  public function testProviderTimeoutFallsBack(): void {
    $this->configureEnabledFutureAi();
    $this->createPublicContextPage();

    $provider = new RecordingProviderGateway('provider_timeout');
    $response = $this->createOrchestrator($provider)->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal',
    ])->toArray();

    self::assertSame(1, $provider->calls);
    self::assertTrue($response['fallback']);
    self::assertSame('provider_timeout', $response['status']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_provider_timeout']);
  }

  /**
   * Tests successful orchestration passes public context to the provider.
   */
  public function testSuccessWithValidPublicContext(): void {
    $this->configureEnabledFutureAi();
    $this->createPublicContextPage();

    $provider = new RecordingProviderGateway('ai_response', 'Orientation utile.');
    $logger = new OrchestratorMemoryLogger();
    $responseContract = $this->createOrchestrator($provider, self::API_KEY, $logger)
      ->respond([
        'langcode' => 'fr',
        'message' => 'Question Drupal',
      ]);
    $response = $responseContract->toArray();

    self::assertSame(1, $provider->calls);
    self::assertSame(FutureAiResponseReason::Success, $responseContract->getReason());
    self::assertSame(self::API_KEY, $provider->apiKey);
    self::assertStringContainsString('Public Drupal context:', $provider->context);
    self::assertStringContainsString('Contexte orchestrateur', $provider->context);
    self::assertSame('ai_response', $response['status']);
    self::assertSame('Orientation utile.', $response['message']);
    self::assertFalse($response['fallback']);
    self::assertFalse($response['stored']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('Question Drupal', $exposed);
    self::assertSame('1', $this->getMonitoringSummary()['reason_success']);
  }

  /**
   * Tests mock selection remains fail-closed without runtime allowance.
   */
  public function testMockProviderSelectionRequiresRuntimeAllowance(): void {
    $this->configureEnabledFutureAi('mock');
    $this->createPublicContextPage();
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER');

    $registry = new FutureAiProviderRegistry(
      $this->container->get('config.factory'),
      [new MockFutureAiProviderGateway()],
    );
    $response = $this
      ->createOrchestratorWithRegistry($registry, '')
      ->respond([
        'langcode' => 'fr',
        'message' => 'Question Drupal',
      ])->toArray();

    self::assertTrue($response['fallback']);
    self::assertSame('unsupported_provider', $response['status']);
    self::assertSame('1', $this->getMonitoringSummary()['reason_unsupported_provider']);
  }

  /**
   * Tests the mock provider returns a controlled response without leaks.
   */
  public function testMockProviderReturnsControlledResponseWithoutSecrets(): void {
    $this->configureEnabledFutureAi('mock');
    $this->createPublicContextPage();
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER=true');

    $logger = new OrchestratorMemoryLogger();
    $registry = new FutureAiProviderRegistry(
      $this->container->get('config.factory'),
      [new MockFutureAiProviderGateway()],
    );
    $responseContract = $this
      ->createOrchestratorWithRegistry($registry, '', $logger)
      ->respond([
        'langcode' => 'fr',
        'message' => 'Question Drupal avec sk-mock-secret',
      ]);
    $response = $responseContract->toArray();

    self::assertSame(FutureAiResponseReason::Success, $responseContract->getReason());
    self::assertSame('ai_response', $response['status']);
    self::assertSame(
      'Reponse de demonstration controlee. Un membre de l\'equipe peut '
        . 'reprendre votre demande.',
      $response['message'],
    );
    self::assertFalse($response['fallback']);
    self::assertFalse($response['stored']);
    self::assertSame('fr', $response['langcode']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('sk-mock-secret', $exposed);
    self::assertStringNotContainsString('Question Drupal', $exposed);
    self::assertStringNotContainsString('Contexte orchestrateur', $exposed);
    self::assertStringNotContainsString('Texte public pour Future AI.', $exposed);
    self::assertSame('1', $this->getMonitoringSummary()['reason_success']);
  }

  /**
   * Configures the active Future AI mode for orchestration tests.
   */
  private function configureEnabledFutureAi(string $provider = 'openai'): void {
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true');

    $this->config('emerging_digital_chatbot.settings')
      ->set('mode', 'ai')
      ->set('future_ai.enabled', TRUE)
      ->set('future_ai.provider', $provider)
      ->set('future_ai.openai_key_id', 'openai_api_key')
      ->set('future_ai.context.allowed_public_paths.fr', [
        '/fr/orchestrator-public',
      ])
      ->save();
  }

  /**
   * Creates one public page used by the orchestrator context.
   */
  private function createPublicContextPage(): void {
    $node = Node::create([
      'type' => 'page',
      'langcode' => 'fr',
      'title' => 'Contexte orchestrateur',
      'status' => 1,
      'body' => [
        'value' => '<p>Texte public pour Future AI.</p>',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/orchestrator-public',
      'langcode' => 'fr',
    ])->save();
  }

  /**
   * Creates a translatable page bundle with a body field.
   */
  private function createPageTypeWithBodyField(): void {
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Body',
    ])->save();
  }

  /**
   * Creates an orchestrator under test.
   */
  private function createOrchestrator(
    RecordingProviderGateway $providerGateway,
    string $apiKey = self::API_KEY,
    ?OrchestratorMemoryLogger $logger = NULL,
  ): FutureAiOrchestrator {
    $providerRegistry = new FutureAiProviderRegistry(
      $this->container->get('config.factory'),
      [$providerGateway],
    );

    return $this->createOrchestratorWithRegistry(
      $providerRegistry,
      $apiKey,
      $logger,
    );
  }

  /**
   * Creates an orchestrator under test with a specific provider registry.
   */
  private function createOrchestratorWithRegistry(
    FutureAiProviderRegistry $providerRegistry,
    string $apiKey = self::API_KEY,
    ?OrchestratorMemoryLogger $logger = NULL,
  ): FutureAiOrchestrator {
    $logger ??= new OrchestratorMemoryLogger();

    return new FutureAiOrchestrator(
      $this->getChatbotConfig(),
      new FutureAiEnvironmentGuard(
        $this->getChatbotConfig(),
        $this->container->get('config.factory'),
        $this->getKeyRepository($apiKey),
        $providerRegistry,
      ),
      $this->getPublicAiContextProvider(),
      $providerRegistry,
      $this->getFallbackGateway(),
      $this->getMonitoring($logger),
      $logger,
    );
  }

  /**
   * Gets the chatbot config service.
   */
  private function getChatbotConfig(): ChatbotConfig {
    $config = $this->container->get('emerging_digital_chatbot.config');
    self::assertInstanceOf(ChatbotConfig::class, $config);

    return $config;
  }

  /**
   * Gets the public context provider service.
   */
  private function getPublicAiContextProvider(): PublicAiContextProvider {
    $provider = $this->container
      ->get('emerging_digital_chatbot.public_ai_context_provider');
    self::assertInstanceOf(PublicAiContextProvider::class, $provider);

    return $provider;
  }

  /**
   * Gets the fallback gateway service.
   */
  private function getFallbackGateway(): FutureAiGatewayInterface {
    $gateway = $this->container->get('emerging_digital_chatbot.fallback_ai_gateway');
    self::assertInstanceOf(FutureAiGatewayInterface::class, $gateway);

    return $gateway;
  }

  /**
   * Gets monitoring with the supplied logger.
   */
  private function getMonitoring(OrchestratorMemoryLogger $logger): FutureAiMonitoring {
    $cache = $this->container->get('cache.default');
    self::assertInstanceOf(CacheBackendInterface::class, $cache);

    return new FutureAiMonitoring(
      $cache,
      $this->container->get('datetime.time'),
      $logger,
    );
  }

  /**
   * Gets sanitized monitoring counters.
   *
   * @return array<string, string>
   *   Monitoring counters.
   */
  private function getMonitoringSummary(): array {
    return $this->getMonitoring(new OrchestratorMemoryLogger())
      ->getAdminSummary();
  }

  /**
   * Gets a Key repository stub.
   */
  private function getKeyRepository(string $apiKey): KeyRepositoryInterface {
    return new class($apiKey) implements KeyRepositoryInterface {

      public function __construct(
        private readonly string $apiKey,
      ) {
      }

      /**
       * {@inheritdoc}
       */
      public function getKeys(?array $key_ids = NULL): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByProvider($key_provider_id): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByType($key_type_id): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByTags(array $tags): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByStorageMethod($storage_method): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByTypeGroup($type_group): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKey($key_id): ?Key {
        if ($key_id !== 'openai_api_key' || $this->apiKey === '') {
          return NULL;
        }

        return new class($this->apiKey) extends Key {

          public function __construct(
            private readonly string $apiKey,
          ) {
          }

          /**
           * Gets the fake secret value.
           */
          public function getKeyValue($reset = FALSE): string {
            return $this->apiKey;
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function getKeyNamesAsOptions(array $filters): array {
        return [];
      }

    };
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

/**
 * Provider gateway test double.
 */
final class RecordingProviderGateway implements FutureAiProviderGatewayInterface {

  /**
   * Number of provider calls.
   */
  public int $calls = 0;

  /**
   * Captured prompt context.
   */
  public string $context = '';

  /**
   * Captured API key.
   */
  public string $apiKey = '';

  public function __construct(
    private readonly string $status = 'ai_response',
    private readonly string $message = '',
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return FutureAiProviderRegistry::PROVIDER_OPENAI;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function respond(
    array $payload,
    string $langcode,
    string $promptContext,
    string $apiKey,
  ): FutureAiResponse {
    $this->calls++;
    $this->context = $promptContext;
    $this->apiKey = $apiKey;

    if ($this->status === FutureAiResponseStatus::AiResponse->value) {
      return FutureAiResponse::aiResponse($this->message, $langcode);
    }

    $status = FutureAiResponseStatus::providerFailureFromValue($this->status);

    return FutureAiResponse::providerFailure($status, $langcode);
  }

}

/**
 * Captures orchestrator logs for assertions.
 */
final class OrchestratorMemoryLogger extends AbstractLogger {

  /**
   * Captured records.
   *
   * @var array<int, array{level: mixed, message: string,
   *   context: array<string, mixed>}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log(
    $level,
    string|\Stringable $message,
    array $context = [],
  ): void {
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

}

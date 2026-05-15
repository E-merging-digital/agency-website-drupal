<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiEnvironmentGuard;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiGatewayInterface;
use Drupal\emerging_digital_chatbot\FutureAi\OpenAiResponsesGateway;
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
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

/**
 * Tests the prepared OpenAI Responses gateway.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class OpenAiResponsesGatewayTest extends KernelTestBase {

  private const API_KEY = 'sk-test-secret-never-exposed';

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
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['emerging_digital_chatbot', 'filter', 'node']);

    ConfigurableLanguage::createFromLangcode('fr')->save();

    $type = NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ]);
    $type->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => $type->id(),
      'label' => 'Body',
    ])->save();
  }

  /**
   * Tests that disabled future AI cannot reach the HTTP gateway.
   */
  public function testDisabledFutureAiNeverCallsHttpClient(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.enabled', FALSE)
      ->set('future_ai.openai_key_id', 'openai_api_key')
      ->save();

    $history = [];
    $gateway = $this->createGateway([
      new Response(200, [], '{"output_text":"Unexpected"}'),
    ], $history);

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => 'Bonjour',
    ]);

    self::assertSame([], $history);
    self::assertTrue($response['fallback']);
    self::assertSame('future_ai_disabled', $response['status']);
    self::assertSame(
      $this->getChatbotConfig()->getFutureAiFallbackMessage('fr'),
      $response['message'],
    );
  }

  /**
   * Tests the OpenAI Responses payload, public context and timeout.
   */
  public function testEnabledGatewayBuildsResponsesPayload(): void {
    $this->configureEnabledGateway();
    $this->createPublicContextPage();

    $history = [];
    $logger = new MemoryLogger();
    $gateway = $this->createGateway([
      new Response(200, [], '{"output_text":"Orientation utile."}'),
    ], $history, $logger);

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => '<strong>Je veux un site Drupal</strong><script>bad()</script>',
      'need' => 'drupal_project',
      'project_type' => 'creation',
      'organization_type' => 'association',
      'url' => 'https://example.com',
    ]);

    self::assertSame('ai_response', $response['status']);
    self::assertSame('Orientation utile.', $response['message']);
    self::assertFalse($response['fallback']);
    self::assertFalse($response['stored']);
    self::assertCount(1, $history);

    $transaction = $history[0];
    $request = $transaction['request'];
    $options = $transaction['options'];
    $sentPayload = json_decode(
      (string) $request->getBody(),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $inputText = $sentPayload['input'][0]['content'][0]['text'];

    self::assertSame('POST', $request->getMethod());
    self::assertSame(
      'https://api.openai.com/v1/responses',
      (string) $request->getUri(),
    );
    self::assertSame(
      'Bearer ' . self::API_KEY,
      $request->getHeaderLine('Authorization'),
    );
    self::assertSame(13, $options['timeout']);
    self::assertSame('gpt-test-responses', $sentPayload['model']);
    self::assertSame('System prompt configured for tests.', $sentPayload['instructions']);
    self::assertSame(0.4, $sentPayload['temperature']);
    self::assertSame(123, $sentPayload['max_output_tokens']);
    self::assertFalse($sentPayload['store']);
    self::assertSame('emerging_digital_chatbot', $sentPayload['metadata']['module']);
    self::assertSame('fr', $sentPayload['metadata']['langcode']);
    self::assertSame('public_pages_v1', $sentPayload['metadata']['rag_profile']);

    self::assertStringContainsString('Context profile: public_pages_v1.', $inputText);
    self::assertStringContainsString('Public Drupal context:', $inputText);
    self::assertStringContainsString('Page publique gateway', $inputText);
    self::assertStringContainsString('Drupal context visible', $inputText);
    self::assertStringContainsString('message: Je veux un site Drupal', $inputText);
    self::assertStringContainsString('need: drupal_project', $inputText);
    self::assertStringContainsString(
      'voluntary_public_url: https://example.com',
      $inputText,
    );
    self::assertStringNotContainsString('<strong>', $inputText);
    self::assertStringNotContainsString('<script>', $inputText);
    self::assertStringNotContainsString('bad()', $inputText);
    self::assertStringNotContainsString('TRUNCATED_MARKER', $inputText);

    $exposed = json_encode(
      [$response, $sentPayload, $logger->records],
      JSON_THROW_ON_ERROR,
    );
    self::assertStringNotContainsString(self::API_KEY, $exposed);
  }

  /**
   * Tests HTTP provider errors use the configured fallback safely.
   */
  public function testHttpErrorUsesFallbackWithoutLeakingSecrets(): void {
    $this->configureEnabledGateway();

    $history = [];
    $logger = new MemoryLogger();
    $gateway = $this->createGateway([
      new Response(500, [], 'provider error ' . self::API_KEY),
    ], $history, $logger);

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => 'Aider mon site Drupal',
    ]);

    self::assertCount(1, $history);
    self::assertTrue($response['fallback']);
    self::assertSame('ai_unavailable', $response['status']);
    self::assertSame(
      $this->getChatbotConfig()->getFutureAiFallbackMessage('fr'),
      $response['message'],
    );

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('Aider mon site Drupal', $exposed);
  }

  /**
   * Tests invalid provider credentials fall back without leaking provider data.
   */
  public function testInvalidProviderKeyUsesFallbackWithoutLeakingSecrets(): void {
    $this->configureEnabledGateway();

    $history = [];
    $logger = new MemoryLogger();
    $gateway = $this->createGateway([
      new Response(401, [], implode(' ', [
        'invalid_api_key',
        self::API_KEY,
        'System prompt configured for tests.',
        'Aider mon site Drupal',
      ])),
    ], $history, $logger);

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => 'Aider mon site Drupal',
    ]);

    self::assertCount(1, $history);
    self::assertTrue($response['fallback']);
    self::assertSame('ai_unavailable', $response['status']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('invalid_api_key', $exposed);
    self::assertStringNotContainsString(
      'System prompt configured for tests.',
      $exposed,
    );
    self::assertStringNotContainsString('Aider mon site Drupal', $exposed);
  }

  /**
   * Tests provider timeouts use fallback without logging request content.
   */
  public function testProviderTimeoutUsesFallbackWithoutLeakingPayload(): void {
    $this->configureEnabledGateway();

    $logger = new MemoryLogger();
    $httpClient = new class(self::API_KEY) implements ClientInterface {

      /**
       * Number of request attempts.
       */
      public int $requests = 0;

      public function __construct(
        private readonly string $apiKey,
      ) {
      }

      /**
       * {@inheritdoc}
       */
      public function send(
        RequestInterface $request,
        array $options = [],
      ): ResponseInterface {
        throw new \BadMethodCallException('send() is not used in this test.');
      }

      /**
       * {@inheritdoc}
       */
      public function sendAsync(
        RequestInterface $request,
        array $options = [],
      ): PromiseInterface {
        throw new \BadMethodCallException(
          'sendAsync() is not used in this test.',
        );
      }

      /**
       * {@inheritdoc}
       */
      public function request(
        string $method,
        $uri,
        array $options = [],
      ): ResponseInterface {
        $this->requests++;

        throw new ConnectException(
          'Timeout while sending ' . $this->apiKey,
          new Psr7Request($method, (string) $uri),
        );
      }

      /**
       * {@inheritdoc}
       */
      public function requestAsync(
        string $method,
        $uri,
        array $options = [],
      ): PromiseInterface {
        throw new \BadMethodCallException(
          'requestAsync() is not used in this test.',
        );
      }

      /**
       * {@inheritdoc}
       */
      public function getConfig(?string $option = NULL) {
        return NULL;
      }

    };
    $gateway = $this->createGatewayWithHttpClient($httpClient, $logger);

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal timeout',
    ]);

    self::assertSame(1, $httpClient->requests);
    self::assertTrue($response['fallback']);
    self::assertSame('ai_unavailable', $response['status']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('Question Drupal timeout', $exposed);
    self::assertStringNotContainsString('Timeout while sending', $exposed);
  }

  /**
   * Tests empty and invalid provider responses fall back.
   */
  public function testEmptyAndInvalidResponsesUseFallback(): void {
    $this->configureEnabledGateway();

    foreach ([
      'empty_ai_response' => new Response(200, [], '{"output_text":""}'),
      'ai_unavailable' => new Response(200, [], '{invalid-json'),
    ] as $expectedStatus => $providerResponse) {
      $history = [];
      $gateway = $this->createGateway([$providerResponse], $history);

      $response = $gateway->respond([
        'langcode' => 'fr',
        'message' => 'Question Drupal',
      ]);

      self::assertCount(1, $history);
      self::assertTrue($response['fallback']);
      self::assertSame($expectedStatus, $response['status']);
      self::assertSame(
        $this->getChatbotConfig()->getFutureAiFallbackMessage('fr'),
        $response['message'],
      );
    }
  }

  /**
   * Tests a missing key prevents any provider request.
   */
  public function testMissingKeyUsesFallbackWithoutHttpCall(): void {
    $this->configureEnabledGateway();

    $history = [];
    $gateway = $this->createGateway([
      new Response(200, [], '{"output_text":"Unexpected"}'),
    ], $history, NULL, '');

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal',
    ]);

    self::assertCount(0, $history);
    self::assertTrue($response['fallback']);
    self::assertSame('key_missing', $response['status']);
  }

  /**
   * Tests unsupported providers prevent provider requests.
   */
  public function testUnsupportedProviderUsesFallbackWithoutHttpCall(): void {
    $this->configureEnabledGateway();
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'disabled_provider')
      ->save();

    $history = [];
    $logger = new MemoryLogger();
    $gateway = $this->createGateway([
      new Response(200, [], '{"output_text":"Unexpected"}'),
    ], $history, $logger);

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal',
    ]);

    self::assertCount(0, $history);
    self::assertTrue($response['fallback']);
    self::assertSame('unsupported_provider', $response['status']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('Question Drupal', $exposed);
  }

  /**
   * Tests sensitive visitor input is blocked before any provider request.
   */
  public function testSensitiveInputUsesFallbackWithoutHttpCall(): void {
    $this->configureEnabledGateway();

    $history = [];
    $logger = new MemoryLogger();
    $gateway = $this->createGateway([
      new Response(200, [], '{"output_text":"Unexpected"}'),
    ], $history, $logger);

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => '',
      'blocked_sensitive_input' => TRUE,
    ]);

    self::assertCount(0, $history);
    self::assertTrue($response['fallback']);
    self::assertSame('sensitive_input_blocked', $response['status']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
  }

  /**
   * Tests a blocked environment prevents any provider request.
   */
  public function testBlockedEnvironmentUsesFallbackWithoutHttpCall(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.enabled', TRUE)
      ->set('future_ai.openai_key_id', 'openai_api_key')
      ->save();

    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');
    $history = [];
    $logger = new MemoryLogger();
    $gateway = $this->createGateway([
      new Response(200, [], '{"output_text":"Unexpected"}'),
    ], $history, $logger);

    $response = $gateway->respond([
      'langcode' => 'fr',
      'message' => 'Question Drupal',
    ]);

    self::assertCount(0, $history);
    self::assertTrue($response['fallback']);
    self::assertSame('environment_blocked', $response['status']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('Question Drupal', $exposed);
  }

  /**
   * Configures future AI for gateway tests.
   */
  private function configureEnabledGateway(): void {
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true');

    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.enabled', TRUE)
      ->set('future_ai.openai_key_id', 'openai_api_key')
      ->set('future_ai.model', 'gpt-test-responses')
      ->set('future_ai.temperature', 0.4)
      ->set('future_ai.max_output_tokens', 123)
      ->set('future_ai.timeout_seconds', 13)
      ->set('future_ai.prompts.fr.system', 'System prompt configured for tests.')
      ->set('future_ai.context.max_context_chars', 900)
      ->set('future_ai.context.allowed_public_paths.fr', ['/fr/gateway-public'])
      ->save();
  }

  /**
   * Creates one public page used by the context provider.
   */
  private function createPublicContextPage(): void {
    $node = Node::create([
      'type' => 'page',
      'langcode' => 'fr',
      'title' => 'Page publique gateway',
      'status' => 1,
      'body' => [
        'value' => '<h2>Drupal context visible</h2><script>bad()</script><p>'
        . str_repeat('contenu public ', 80)
        . 'TRUNCATED_MARKER</p>',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/gateway-public',
      'langcode' => 'fr',
    ])->save();
  }

  /**
   * Creates the gateway with a history-enabled mock HTTP client.
   *
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   *   Queued Guzzle responses.
   * @param array<int, array<string, mixed>> $history
   *   Captured request history.
   * @param \Drupal\Tests\emerging_digital_chatbot\Kernel\MemoryLogger|null $logger
   *   Optional logger used for assertions.
   * @param string $apiKey
   *   Fake API key returned by the Key repository stub.
   */
  private function createGateway(
    array $responses,
    array &$history,
    ?MemoryLogger $logger = NULL,
    string $apiKey = self::API_KEY,
  ): OpenAiResponsesGateway {
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new OpenAiResponsesGateway(
      $this->getChatbotConfig(),
      new Client(['handler' => $stack]),
      $logger ?? new MemoryLogger(),
      $this->getPublicAiContextProvider(),
      $this->getFallbackGateway(),
      new FutureAiEnvironmentGuard(
        $this->getChatbotConfig(),
        $this->container->get('config.factory'),
        $this->getKeyRepository($apiKey),
      ),
    );
  }

  /**
   * Creates the gateway with a custom HTTP client.
   */
  private function createGatewayWithHttpClient(
    ClientInterface $httpClient,
    MemoryLogger $logger,
  ): OpenAiResponsesGateway {
    return new OpenAiResponsesGateway(
      $this->getChatbotConfig(),
      $httpClient,
      $logger,
      $this->getPublicAiContextProvider(),
      $this->getFallbackGateway(),
      new FutureAiEnvironmentGuard(
        $this->getChatbotConfig(),
        $this->container->get('config.factory'),
        $this->getKeyRepository(self::API_KEY),
      ),
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
   * Gets a key repository stub.
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

}

/**
 * Captures gateway logs for assertions.
 */
final class MemoryLogger extends AbstractLogger {

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

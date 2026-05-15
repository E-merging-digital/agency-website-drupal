<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\OpenAiResponsesGateway;
use Drupal\KernelTests\KernelTestBase;
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
 * Tests the OpenAI Responses provider gateway.
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
    'path_alias',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['emerging_digital_chatbot']);
  }

  /**
   * Tests the OpenAI Responses payload and success parsing.
   */
  public function testGatewayBuildsResponsesPayload(): void {
    $this->configureGateway();

    $history = [];
    $logger = new OpenAiGatewayMemoryLogger();
    $gateway = $this->createGateway([
      new Response(200, [], '{"output_text":"Orientation utile."}'),
    ], $history, $logger);

    $response = $gateway->respond(
      [
        'langcode' => 'fr',
        'message' => '<strong>Je veux un site Drupal</strong><script>bad()</script>',
        'need' => 'drupal_project',
        'project_type' => 'creation',
        'organization_type' => 'association',
        'url' => 'https://example.com',
      ],
      'fr',
      'Public Drupal context: Page publique gateway',
      self::API_KEY,
    );

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
    self::assertStringContainsString('Public Drupal context:', $inputText);
    self::assertStringContainsString('message: Je veux un site Drupal', $inputText);
    self::assertStringContainsString('need: drupal_project', $inputText);
    self::assertStringContainsString(
      'voluntary_public_url: https://example.com',
      $inputText,
    );
    self::assertStringNotContainsString('<strong>', $inputText);
    self::assertStringNotContainsString('<script>', $inputText);
    self::assertStringNotContainsString('bad()', $inputText);

    $exposed = json_encode(
      [$response, $sentPayload, $logger->records],
      JSON_THROW_ON_ERROR,
    );
    self::assertStringNotContainsString(self::API_KEY, $exposed);
  }

  /**
   * Tests provider HTTP errors are sanitized provider failures.
   */
  public function testHttpErrorReturnsProviderErrorWithoutLeakingSecrets(): void {
    $this->configureGateway();

    $history = [];
    $logger = new OpenAiGatewayMemoryLogger();
    $gateway = $this->createGateway([
      new Response(500, [], 'provider error ' . self::API_KEY),
    ], $history, $logger);

    $response = $gateway->respond(
      ['langcode' => 'fr', 'message' => 'Aider mon site Drupal'],
      'fr',
      'Public Drupal context: Page publique gateway',
      self::API_KEY,
    );

    self::assertCount(1, $history);
    self::assertTrue($response['fallback']);
    self::assertSame('provider_error', $response['status']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('Aider mon site Drupal', $exposed);
  }

  /**
   * Tests provider timeouts are reported without logging request content.
   */
  public function testProviderTimeoutReturnsTimeoutWithoutLeakingPayload(): void {
    $this->configureGateway();

    $logger = new OpenAiGatewayMemoryLogger();
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
    $gateway = new OpenAiResponsesGateway(
      $this->getChatbotConfig(),
      $httpClient,
      $logger,
    );

    $response = $gateway->respond(
      ['langcode' => 'fr', 'message' => 'Question Drupal timeout'],
      'fr',
      'Public Drupal context: Page publique gateway',
      self::API_KEY,
    );

    self::assertSame(1, $httpClient->requests);
    self::assertTrue($response['fallback']);
    self::assertSame('provider_timeout', $response['status']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('Question Drupal timeout', $exposed);
    self::assertStringNotContainsString('Timeout while sending', $exposed);
  }

  /**
   * Tests invalid, empty and guarded provider responses fail closed.
   */
  public function testInvalidEmptyAndGuardedResponsesFailClosed(): void {
    $this->configureGateway();

    foreach ([
      'empty_ai_response' => new Response(200, [], '{"output_text":""}'),
      'provider_error' => new Response(200, [], '{invalid-json'),
      'guardrail_fallback' => new Response(200, [], '{"output_text":"Prix: 100 euros"}'),
    ] as $expectedStatus => $providerResponse) {
      $history = [];
      $gateway = $this->createGateway([$providerResponse], $history);

      $response = $gateway->respond(
        ['langcode' => 'fr', 'message' => 'Question Drupal'],
        'fr',
        'Public Drupal context: Page publique gateway',
        self::API_KEY,
      );

      self::assertCount(1, $history);
      self::assertTrue($response['fallback']);
      self::assertSame($expectedStatus, $response['status']);
      self::assertSame('', $response['message']);
    }
  }

  /**
   * Configures deterministic provider settings for gateway tests.
   */
  private function configureGateway(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.model', 'gpt-test-responses')
      ->set('future_ai.temperature', 0.4)
      ->set('future_ai.max_output_tokens', 123)
      ->set('future_ai.timeout_seconds', 13)
      ->set('future_ai.prompts.fr.system', 'System prompt configured for tests.')
      ->save();
  }

  /**
   * Creates the gateway with a history-enabled mock HTTP client.
   *
   * @param \Psr\Http\Message\ResponseInterface[] $responses
   *   Queued Guzzle responses.
   * @param array<int, array<string, mixed>> $history
   *   Captured request history.
   * @param \Drupal\Tests\emerging_digital_chatbot\Kernel\OpenAiGatewayMemoryLogger|null $logger
   *   Optional logger used for assertions.
   */
  private function createGateway(
    array $responses,
    array &$history,
    ?OpenAiGatewayMemoryLogger $logger = NULL,
  ): OpenAiResponsesGateway {
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return new OpenAiResponsesGateway(
      $this->getChatbotConfig(),
      new Client(['handler' => $stack]),
      $logger ?? new OpenAiGatewayMemoryLogger(),
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

}

/**
 * Captures gateway logs for assertions.
 */
final class OpenAiGatewayMemoryLogger extends AbstractLogger {

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

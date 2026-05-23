<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse;
use Drupal\emerging_digital_chatbot\FutureAi\OpenAiResponsesGateway;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;

/**
 * Tests the locked OpenAI Responses provider gateway.
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
   * Tests OpenAI remains registered but impossible to execute.
   */
  public function testGatewayIsLockedAndDoesNotSendHttpRequest(): void {
    $logger = new OpenAiGatewayMemoryLogger();
    $httpClient = new OpenAiGatewayForbiddenHttpClient();
    $gateway = new OpenAiResponsesGateway(
      $this->getChatbotConfig(),
      $httpClient,
      $logger,
    );

    $responseContract = $gateway->respond(
      [
        'langcode' => 'fr',
        'message' => 'Question Drupal avec sk-hidden',
        'need' => 'drupal_project',
      ],
      'fr',
      'Public Drupal context: Page publique gateway',
      self::API_KEY,
    );
    $response = $responseContract->toArray();

    self::assertFalse($gateway->isEnabled());
    self::assertInstanceOf(FutureAiResponse::class, $responseContract);
    self::assertSame(0, $httpClient->requests);
    self::assertTrue($response['fallback']);
    self::assertSame('unsupported_provider', $response['status']);
    self::assertSame('', $response['message']);
    self::assertFalse($response['stored']);

    $exposed = json_encode([$response, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('sk-hidden', $exposed);
    self::assertStringNotContainsString('Question Drupal', $exposed);
    self::assertStringContainsString('provider is locked', $exposed);
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
 * HTTP client that records accidental provider execution attempts.
 */
final class OpenAiGatewayForbiddenHttpClient implements ClientInterface {

  /**
   * Number of request attempts.
   */
  public int $requests = 0;

  /**
   * {@inheritdoc}
   */
  public function send(
    RequestInterface $request,
    array $options = [],
  ): ResponseInterface {
    $this->requests++;
    throw new \RuntimeException('OpenAI HTTP send must stay locked.');
  }

  /**
   * {@inheritdoc}
   */
  public function sendAsync(
    RequestInterface $request,
    array $options = [],
  ): PromiseInterface {
    $this->requests++;
    throw new \RuntimeException('OpenAI HTTP sendAsync must stay locked.');
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
    throw new \RuntimeException('OpenAI HTTP request must stay locked.');
  }

  /**
   * {@inheritdoc}
   */
  public function requestAsync(
    string $method,
    $uri,
    array $options = [],
  ): PromiseInterface {
    $this->requests++;
    throw new \RuntimeException('OpenAI HTTP requestAsync must stay locked.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfig(?string $option = NULL) {
    return NULL;
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

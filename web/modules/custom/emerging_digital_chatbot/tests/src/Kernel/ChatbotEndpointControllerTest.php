<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\Core\Flood\FloodInterface;
use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\Controller\ChatbotEndpointController;
use Drupal\emerging_digital_chatbot\FutureAi\ChatbotPayloadSanitizer;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiGatewayInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the chatbot endpoint future AI safety gate.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class ChatbotEndpointControllerTest extends KernelTestBase {

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
   * Tests disabled future AI keeps the guided MVP and skips the gateway.
   */
  public function testDisabledFutureAiKeepsGuidedResponseWithoutGatewayCall(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('mode', 'ai')
      ->set('future_ai.enabled', FALSE)
      ->save();

    $gateway = new class() implements FutureAiGatewayInterface {

      /**
       * Number of calls to the gateway boundary.
       */
      public int $calls = 0;

      /**
       * {@inheritdoc}
       */
      public function respond(array $payload): array {
        $this->calls++;

        return [
          'status' => 'unexpected_external_gateway',
          'message' => 'Unexpected gateway call.',
          'fallback' => FALSE,
          'stored' => TRUE,
          'langcode' => 'fr',
        ];
      }

    };

    $controller = new ChatbotEndpointController(
      $this->getChatbotConfig(),
      $gateway,
      $this->getPayloadSanitizer(),
      $this->getAllowingFlood(),
      new NullLogger(),
    );

    $request = Request::create(
      '/api/emerging-digital-chatbot/conversation',
      'POST',
      [],
      [],
      [],
      ['REMOTE_ADDR' => '127.0.0.1'],
      json_encode([
        'langcode' => 'fr',
        'message' => 'Je cherche une aide Drupal.',
      ], JSON_THROW_ON_ERROR),
    );
    $response = $controller->conversation($request);
    $data = json_decode((string) $response->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);

    self::assertSame(0, $gateway->calls);
    self::assertSame(200, $response->getStatusCode());
    self::assertSame('guide_only', $data['status']);
    self::assertTrue($data['fallback']);
    self::assertFalse($data['stored']);
    self::assertSame('fr', $data['langcode']);
    self::assertSame(
      $this->getChatbotConfig()->getFutureAiFallbackMessage('fr'),
      $data['message'],
    );
    self::assertIsArray($data['futureAi']);
    self::assertFalse($data['futureAi']['enabled']);
    self::assertSame('none', $data['futureAi']['retentionPolicy']);
    self::assertIsArray($data['futureAi']['context']);
    self::assertSame('public_pages_v1', $data['futureAi']['context']['profile']);
    self::assertSame('fr', $data['futureAi']['context']['langcode']);
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
   * Gets the payload sanitizer service.
   */
  private function getPayloadSanitizer(): ChatbotPayloadSanitizer {
    $sanitizer = $this->container->get('emerging_digital_chatbot.payload_sanitizer');
    self::assertInstanceOf(ChatbotPayloadSanitizer::class, $sanitizer);

    return $sanitizer;
  }

  /**
   * Gets a flood backend that always allows test requests.
   */
  private function getAllowingFlood(): FloodInterface {
    return new class() implements FloodInterface {

      /**
       * {@inheritdoc}
       */
      public function register($name, $window = 3600, $identifier = NULL): void {
      }

      /**
       * {@inheritdoc}
       */
      public function clear($name, $identifier = NULL): void {
      }

      /**
       * {@inheritdoc}
       */
      public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function garbageCollection(): void {
      }

    };
  }

}

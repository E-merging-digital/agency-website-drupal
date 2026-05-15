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
 * Tests the chatbot endpoint HTTP boundary.
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
   * Tests the controller delegates sanitized payloads to the orchestrator.
   */
  public function testEndpointDelegatesToFutureAiOrchestrator(): void {
    $orchestrator = new class() implements FutureAiGatewayInterface {

      /**
       * Captured sanitized payload.
       *
       * @var array<string, mixed>
       */
      public array $payload = [];

      /**
       * {@inheritdoc}
       */
      public function respond(array $payload): array {
        $this->payload = $payload;

        return [
          'status' => 'guide_only',
          'message' => 'Fallback stable.',
          'fallback' => TRUE,
          'stored' => FALSE,
          'langcode' => 'fr',
        ];
      }

    };

    $controller = new ChatbotEndpointController(
      $this->getChatbotConfig(),
      $orchestrator,
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
        'message' => '<strong>Je cherche une aide Drupal.</strong>',
        'unexpected_object' => ['ignored' => TRUE],
      ], JSON_THROW_ON_ERROR),
    );
    $response = $controller->conversation($request);
    $data = json_decode(
      (string) $response->getContent(),
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );

    self::assertSame(200, $response->getStatusCode());
    self::assertSame('guide_only', $data['status']);
    self::assertSame('fr', $orchestrator->payload['langcode']);
    self::assertSame(
      'Je cherche une aide Drupal.',
      $orchestrator->payload['message'],
    );
    self::assertArrayNotHasKey('unexpected_object', $orchestrator->payload);
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
      public function isAllowed(
        $name,
        $threshold,
        $window = 3600,
        $identifier = NULL,
      ): bool {
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

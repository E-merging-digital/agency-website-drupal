<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\ChatbotPayloadSanitizer;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiGatewayInterface;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseReason;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseStatus;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Prepared server endpoint for future guided or AI-assisted conversations.
 */
final class ChatbotEndpointController extends ControllerBase {

  public function __construct(
    private readonly ChatbotConfig $chatbotConfig,
    private readonly FutureAiGatewayInterface $futureAiOrchestrator,
    private readonly ChatbotPayloadSanitizer $payloadSanitizer,
    private readonly FloodInterface $flood,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('emerging_digital_chatbot.config'),
      $container->get('emerging_digital_chatbot.future_ai_orchestrator'),
      $container->get('emerging_digital_chatbot.payload_sanitizer'),
      $container->get('flood'),
      $container->get('logger.channel.emerging_digital_chatbot'),
    );
  }

  /**
   * Handles a conversation request without storing the submitted content.
   */
  public function conversation(Request $request): JsonResponse {
    if (!$this->isAllowedByRateLimit($request)) {
      $langcode = $this->chatbotConfig->getCurrentLangcode();

      return $this->jsonResponse(FutureAiResponse::fallback(
        FutureAiResponseStatus::RateLimited,
        FutureAiResponseReason::RateLimited,
        $this->chatbotConfig->getFutureAiFallbackMessage($langcode),
        $langcode,
      )->toArray(), 429);
    }

    try {
      $decoded = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $this->logger->notice('Chatbot endpoint rejected invalid JSON.');
      $langcode = $this->chatbotConfig->getCurrentLangcode();

      return $this->jsonResponse(FutureAiResponse::fallback(
        FutureAiResponseStatus::InvalidPayload,
        FutureAiResponseReason::InvalidPayload,
        $this->chatbotConfig->getFutureAiFallbackMessage($langcode),
        $langcode,
      )->toArray(), 400);
    }

    $payload = is_array($decoded) ? $this->payloadSanitizer->sanitize($decoded) : [];
    $response = $this->futureAiOrchestrator->respond($payload)->toArray();

    return $this->jsonResponse($response);
  }

  /**
   * Applies a conservative flood limit to the server endpoint.
   */
  private function isAllowedByRateLimit(Request $request): bool {
    $event = 'emerging_digital_chatbot.conversation';
    $window = $this->chatbotConfig->getFutureAiRateLimitWindow();
    $identifier = hash('sha256', (string) ($request->getClientIp() ?: 'unknown'));

    if (!$this->flood->isAllowed(
      $event,
      $this->chatbotConfig->getFutureAiRateLimit(),
      $window,
      $identifier,
    )) {
      $this->logger->notice('Chatbot endpoint rate limit reached.');
      return FALSE;
    }

    $this->flood->register($event, $window, $identifier);
    return TRUE;
  }

  /**
   * Returns no-store JSON for all endpoint outcomes.
   *
   * @param array<string, mixed> $response
   *   Response payload.
   * @param int $status
   *   HTTP status code.
   */
  private function jsonResponse(array $response, int $status = 200): JsonResponse {
    $json = new JsonResponse($response, $status);
    $json->headers->set('Cache-Control', 'no-store, private');
    return $json;
  }

}

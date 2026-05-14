<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\ChatbotPayloadSanitizer;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiGatewayInterface;
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
    private readonly FutureAiGatewayInterface $futureAiGateway,
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
      $container->get('emerging_digital_chatbot.future_ai_gateway'),
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
      return $this->jsonResponse([
        'status' => 'rate_limited',
        'message' => $this->chatbotConfig->getFutureAiFallbackMessage($this->chatbotConfig->getCurrentLangcode()),
        'fallback' => TRUE,
        'stored' => FALSE,
        'langcode' => $this->chatbotConfig->getCurrentLangcode(),
      ], 429);
    }

    try {
      $decoded = json_decode($request->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      $this->logger->notice('Chatbot endpoint rejected invalid JSON.');

      return $this->jsonResponse([
        'status' => 'invalid_payload',
        'message' => $this->chatbotConfig->getFutureAiFallbackMessage($this->chatbotConfig->getCurrentLangcode()),
        'fallback' => TRUE,
        'stored' => FALSE,
        'langcode' => $this->chatbotConfig->getCurrentLangcode(),
      ], 400);
    }

    $payload = is_array($decoded) ? $this->payloadSanitizer->sanitize($decoded) : [];
    $langcode = (string) ($payload['langcode'] ?? $this->chatbotConfig->getCurrentLangcode());

    if ($this->chatbotConfig->getMode() !== 'ai' || !$this->chatbotConfig->isFutureAiEnabled()) {
      $response = [
        'status' => 'guide_only',
        'message' => $this->chatbotConfig->getFutureAiFallbackMessage($langcode),
        'fallback' => TRUE,
        'stored' => FALSE,
        'langcode' => $langcode,
        'futureAi' => $this->chatbotConfig->getFutureAiSummary($langcode),
      ];
    }
    else {
      $response = $this->futureAiGateway->respond($payload);
    }

    return $this->jsonResponse($response);
  }

  /**
   * Applies a conservative flood limit to the server endpoint.
   */
  private function isAllowedByRateLimit(Request $request): bool {
    $event = 'emerging_digital_chatbot.conversation';
    $window = $this->chatbotConfig->getFutureAiRateLimitWindow();
    $identifier = hash('sha256', (string) ($request->getClientIp() ?: 'unknown'));

    if (!$this->flood->isAllowed($event, $this->chatbotConfig->getFutureAiRateLimit(), $window, $identifier)) {
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

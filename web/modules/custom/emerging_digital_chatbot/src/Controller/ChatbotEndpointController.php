<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiGatewayInterface;
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
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('emerging_digital_chatbot.config'),
      $container->get('emerging_digital_chatbot.future_ai_gateway'),
    );
  }

  /**
   * Handles a conversation request without storing the submitted content.
   */
  public function conversation(Request $request): JsonResponse {
    $decoded = json_decode($request->getContent(), TRUE);
    $payload = is_array($decoded) ? $this->sanitizePayload($decoded) : [];

    if ($this->chatbotConfig->getMode() !== 'ai' || !$this->chatbotConfig->isFutureAiEnabled()) {
      $response = [
        'status' => 'guide_only',
        'message' => 'This MVP only supports guided choices. No free-form AI response was generated.',
        'stored' => FALSE,
        'langcode' => $this->chatbotConfig->getCurrentLangcode(),
        'futureAi' => $this->chatbotConfig->getFutureAiSummary(),
      ];
    }
    else {
      $response = $this->futureAiGateway->respond($payload);
    }

    $json = new JsonResponse($response);
    $json->headers->set('Cache-Control', 'no-store, private');
    return $json;
  }

  /**
   * Keeps the prepared endpoint conservative until a real AI phase exists.
   *
   * @param array<string, mixed> $payload
   *   Raw decoded request payload.
   *
   * @return array<string, mixed>
   *   Sanitized payload with scalar values only.
   */
  private function sanitizePayload(array $payload): array {
    $allowed_keys = ['flow', 'langcode', 'message'];
    $sanitized = [];

    foreach ($allowed_keys as $key) {
      $value = $payload[$key] ?? NULL;
      if (is_scalar($value)) {
        $sanitized[$key] = mb_substr(trim((string) $value), 0, 500);
      }
    }

    return $sanitized;
  }

}

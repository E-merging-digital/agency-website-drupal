<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\emerging_digital_chatbot\ChatbotConfig;

/**
 * Fallback gateway: no external AI call is performed.
 */
final class NullFutureAiGateway implements FutureAiGatewayInterface {

  public function __construct(
    private readonly ChatbotConfig $chatbotConfig,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function respond(array $payload): array {
    $langcode = (string) ($payload['langcode'] ?? $this->chatbotConfig->getCurrentLangcode());

    return [
      'status' => 'guide_only',
      'message' => $this->chatbotConfig->getFutureAiFallbackMessage($langcode),
      'fallback' => TRUE,
      'stored' => FALSE,
      'langcode' => $langcode,
    ];
  }

}

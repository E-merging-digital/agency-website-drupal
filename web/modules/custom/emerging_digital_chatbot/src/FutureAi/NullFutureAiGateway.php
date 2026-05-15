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
  public function respond(array $payload): FutureAiResponse {
    $langcode = (string) ($payload['langcode'] ?? $this->chatbotConfig->getCurrentLangcode());

    return FutureAiResponse::fallback(
      FutureAiResponseStatus::GuideOnly,
      FutureAiResponseReason::GuideOnly,
      $this->chatbotConfig->getFutureAiFallbackMessage($langcode),
      $langcode,
    );
  }

}

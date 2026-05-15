<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

/**
 * Explicit fail-closed provider entry for disabled or local test selection.
 */
final class NullFutureAiProviderGateway implements FutureAiProviderGatewayInterface {

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return FutureAiProviderRegistry::PROVIDER_NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function respond(
    array $payload,
    string $langcode,
    string $promptContext,
    string $apiKey,
  ): FutureAiResponse {
    return FutureAiResponse::fallback(
      FutureAiResponseStatus::UnsupportedProvider,
      FutureAiResponseReason::UnsupportedProvider,
      '',
      $langcode,
    );
  }

}

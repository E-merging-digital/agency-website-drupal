<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

/**
 * Describes one stateless Future AI provider gateway.
 */
interface FutureAiProviderGatewayInterface {

  /**
   * Gets the stable provider identifier used in configuration.
   */
  public function getProviderId(): string;

  /**
   * Determines whether this provider can be selected for real AI dispatch.
   */
  public function isEnabled(): bool;

  /**
   * Sends one already-authorized request to a provider.
   *
   * @param array<string, mixed> $payload
   *   Sanitized visitor payload.
   * @param string $langcode
   *   Resolved response language.
   * @param string $promptContext
   *   Public-only prompt context built by Drupal.
   * @param string $apiKey
   *   Runtime provider secret resolved outside the gateway.
   *
   * @return \Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse
   *   Provider result safe for the orchestrator to inspect.
   */
  public function respond(
    array $payload,
    string $langcode,
    string $promptContext,
    string $apiKey,
  ): FutureAiResponse;

}

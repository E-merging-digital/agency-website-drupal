<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

/**
 * Describes the future server-side AI boundary.
 */
interface FutureAiGatewayInterface {

  /**
   * Returns a guarded response for future AI mode.
   *
   * @param array<string, mixed> $payload
   *   Sanitized request payload.
   *
   * @return \Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse
   *   A response contract safe to expose as JSON through serialization.
   */
  public function respond(array $payload): FutureAiResponse;

}

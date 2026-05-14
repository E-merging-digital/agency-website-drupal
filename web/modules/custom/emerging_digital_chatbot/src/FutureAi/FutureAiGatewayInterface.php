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
   * @return array<string, mixed>
   *   A response payload safe to expose as JSON.
   */
  public function respond(array $payload): array;

}

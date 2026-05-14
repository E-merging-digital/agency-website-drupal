<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

/**
 * Placeholder gateway: no external AI call is performed in the MVP.
 */
final class NullFutureAiGateway implements FutureAiGatewayInterface {

  /**
   * {@inheritdoc}
   */
  public function respond(array $payload): array {
    return [
      'status' => 'guide_only',
      'message' => 'AI mode is prepared but disabled for this MVP.',
      'stored' => FALSE,
    ];
  }

}

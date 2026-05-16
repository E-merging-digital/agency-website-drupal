<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\Core\Site\Settings;

/**
 * Deterministic local Future AI provider for controlled runtime demos.
 */
final class MockFutureAiProviderGateway implements FutureAiProviderGatewayInterface {

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return FutureAiProviderRegistry::PROVIDER_MOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    $settingsValue = Settings::get(
      'emerging_digital_chatbot.allow_mock_provider',
      NULL,
    );
    if (is_bool($settingsValue)) {
      return $settingsValue;
    }

    $envValue = getenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER');
    if (!is_string($envValue)) {
      return FALSE;
    }

    return in_array(strtolower(trim($envValue)), [
      '1',
      'true',
      'yes',
      'on',
    ], TRUE);
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
    return FutureAiResponse::aiResponse(
      $this->getControlledMessage($langcode),
      $langcode,
    );
  }

  /**
   * Gets the deterministic public mock response.
   */
  private function getControlledMessage(string $langcode): string {
    if ($langcode === 'en') {
      return 'Controlled demo response. A team member can follow up on your request.';
    }

    return 'Reponse de demonstration controlee. Un membre de l\'equipe peut '
      . 'reprendre votre demande.';
  }

}

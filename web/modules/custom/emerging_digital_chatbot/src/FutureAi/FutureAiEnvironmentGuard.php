<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\key\KeyRepositoryInterface;

/**
 * Centralizes environment and key checks before external Future AI calls.
 */
final class FutureAiEnvironmentGuard {

  public function __construct(
    private readonly ChatbotConfig $chatbotConfig,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?KeyRepositoryInterface $keyRepository = NULL,
    private readonly ?FutureAiProviderRegistry $providerRegistry = NULL,
  ) {
  }

  /**
   * Determines whether an external Future AI call is currently allowed.
   */
  public function allowsExternalCalls(): bool {
    return $this->getBlockReason() === '';
  }

  /**
   * Returns a short technical reason when external calls are blocked.
   */
  public function getBlockReason(): string {
    if (!$this->chatbotConfig->isFutureAiEnabled()) {
      return 'future_ai_disabled';
    }

    if (!$this->hasSupportedActiveProvider()) {
      return 'unsupported_provider';
    }

    if (!$this->isEnvironmentExplicitlyAllowed()) {
      return 'environment_blocked';
    }

    $keyStatus = $this->getKeyStatus();
    if ($keyStatus !== 'available') {
      return 'key_' . $keyStatus;
    }

    return '';
  }

  /**
   * Resolves the configured provider API key through Drupal Key only.
   */
  public function resolveApiKey(): string {
    if (!$this->chatbotConfig->isFutureAiEnabled()
      || !$this->hasSupportedActiveProvider()
      || !$this->isEnvironmentExplicitlyAllowed()) {
      return '';
    }

    $resolution = $this->resolveConfiguredKey();

    return $resolution['value'];
  }

  /**
   * Gets a sanitized summary safe for administration screens.
   *
   * @return array<string, string>
   *   Sanitized provider status.
   */
  public function getAdminSummary(): array {
    $reason = $this->getBlockReason();

    return [
      'future_ai_state' => $this->chatbotConfig->isFutureAiEnabled()
        ? 'enabled'
        : 'disabled',
      'provider' => $this->getActiveProviderId(),
      'environment' => $this->isEnvironmentExplicitlyAllowed()
        ? 'allowed'
        : 'blocked',
      'reason' => $reason !== '' ? $reason : 'none',
      'key_status' => $this->getKeyStatus(),
      'external_calls_allowed' => $reason === '' ? 'yes' : 'no',
    ];
  }

  /**
   * Checks whether the current runtime explicitly allows external AI calls.
   */
  private function isEnvironmentExplicitlyAllowed(): bool {
    $settingsValue = Settings::get(
      'emerging_digital_chatbot.allow_external_ai',
      NULL,
    );
    if (is_bool($settingsValue)) {
      return $settingsValue;
    }

    $envValue = getenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');
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
   * Gets the active provider id without exposing adjacent configuration.
   */
  private function getProvider(): string {
    $provider = $this->configFactory
      ->get('emerging_digital_chatbot.settings')
      ->get('future_ai.provider');

    return is_string($provider) && trim($provider) !== ''
      ? trim($provider)
      : FutureAiProviderRegistry::PROVIDER_OPENAI;
  }

  /**
   * Determines whether the active provider can use the current guard policy.
   */
  private function hasSupportedActiveProvider(): bool {
    if ($this->providerRegistry) {
      $provider = $this->providerRegistry->getActiveProvider();

      return $provider !== NULL
        && $provider->isEnabled()
        && $provider->getProviderId() === FutureAiProviderRegistry::PROVIDER_OPENAI;
    }

    return $this->getActiveProviderId() === FutureAiProviderRegistry::PROVIDER_OPENAI;
  }

  /**
   * Gets the normalized active provider id without adjacent configuration.
   */
  private function getActiveProviderId(): string {
    if ($this->providerRegistry) {
      $providerId = $this->providerRegistry->getActiveProviderId();

      return $providerId !== '' ? $providerId : 'invalid';
    }

    $providerId = FutureAiProviderRegistry::normalizeProviderId(
      $this->getProvider(),
    );

    return $providerId !== '' ? $providerId : 'invalid';
  }

  /**
   * Returns the configured Key status without exposing its id or value.
   */
  private function getKeyStatus(): string {
    return $this->resolveConfiguredKey()['status'];
  }

  /**
   * Resolves the configured Key entity and value.
   *
   * @return array{status: string, value: string}
   *   Safe key resolution metadata plus the secret value for provider use.
   */
  private function resolveConfiguredKey(): array {
    $keyId = $this->getConfiguredKeyId();
    if ($keyId === '') {
      return [
        'status' => 'missing',
        'value' => '',
      ];
    }

    if (!$this->keyRepository) {
      return [
        'status' => 'unreadable',
        'value' => '',
      ];
    }

    try {
      $key = $this->keyRepository->getKey($keyId);
      if (!$key) {
        return [
          'status' => 'missing',
          'value' => '',
        ];
      }

      $value = trim((string) $key->getKeyValue());
    }
    catch (\Throwable) {
      return [
        'status' => 'unreadable',
        'value' => '',
      ];
    }

    if ($value === '') {
      return [
        'status' => 'missing',
        'value' => '',
      ];
    }

    return [
      'status' => 'available',
      'value' => $value,
    ];
  }

  /**
   * Gets the provider Key id from non-secret configuration.
   */
  private function getConfiguredKeyId(): string {
    $providerConfig = $this->configFactory->get('ai_provider_openai.settings');
    $providerKeyId = $this->firstNonEmptyString([
      $providerConfig->get('key_id'),
      $providerConfig->get('api_key'),
      $providerConfig->get('api_key_name'),
      $providerConfig->get('key'),
    ]);
    if ($providerKeyId !== NULL) {
      return $providerKeyId;
    }

    $configuredKeyId = $this->configFactory
      ->get('emerging_digital_chatbot.settings')
      ->get('future_ai.openai_key_id');

    return is_string($configuredKeyId) ? trim($configuredKeyId) : '';
  }

  /**
   * Returns the first non-empty string from config candidates.
   *
   * @param array<int, mixed> $values
   *   Candidate values.
   */
  private function firstNonEmptyString(array $values): ?string {
    foreach ($values as $value) {
      if (is_string($value) && trim($value) !== '') {
        return trim($value);
      }
    }

    return NULL;
  }

}

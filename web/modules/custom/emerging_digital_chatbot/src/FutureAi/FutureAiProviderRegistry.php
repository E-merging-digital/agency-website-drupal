<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Registers Future AI providers and resolves the configured active provider.
 */
final class FutureAiProviderRegistry {

  public const PROVIDER_OPENAI = 'openai';
  public const PROVIDER_OPENAI_RESPONSES_LEGACY = 'openai_responses';
  public const PROVIDER_NULL = 'null';

  /**
   * Registered provider gateways keyed by normalized provider id.
   *
   * @var array<string, \Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderGatewayInterface>
   */
  private array $providers = [];

  /**
   * Config factory.
   */
  private ConfigFactoryInterface $configFactory;

  /**
   * Builds the registry from tagged provider services.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param iterable<\Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderGatewayInterface> $providers
   *   Provider gateway services.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    iterable $providers = [],
  ) {
    $this->configFactory = $configFactory;

    foreach ($providers as $provider) {
      $this->registerProvider($provider);
    }
  }

  /**
   * Registers one provider gateway.
   */
  public function registerProvider(FutureAiProviderGatewayInterface $provider): void {
    $providerId = self::normalizeProviderId($provider->getProviderId());
    if ($providerId === '') {
      return;
    }

    $this->providers[$providerId] = $provider;
  }

  /**
   * Gets a provider by stable id or legacy alias.
   */
  public function getProvider(string $providerId): ?FutureAiProviderGatewayInterface {
    $providerId = self::normalizeProviderId($providerId);
    if ($providerId === '') {
      return NULL;
    }

    return $this->providers[$providerId] ?? NULL;
  }

  /**
   * Gets the configured active provider when it is registered.
   */
  public function getActiveProvider(): ?FutureAiProviderGatewayInterface {
    return $this->getProvider($this->getActiveProviderId());
  }

  /**
   * Gets the normalized active provider id.
   */
  public function getActiveProviderId(): string {
    $provider = $this->configFactory
      ->get('emerging_digital_chatbot.settings')
      ->get('future_ai.provider');

    return self::normalizeProviderId(
      is_string($provider) && trim($provider) !== ''
        ? $provider
        : self::PROVIDER_OPENAI,
    );
  }

  /**
   * Normalizes provider ids while preserving compatibility aliases.
   */
  public static function normalizeProviderId(string $providerId): string {
    $providerId = strtolower(trim($providerId));
    if ($providerId === self::PROVIDER_OPENAI_RESPONSES_LEGACY) {
      return self::PROVIDER_OPENAI;
    }

    return preg_match('/^[a-z0-9_-]+$/', $providerId) === 1
      ? $providerId
      : '';
  }

}

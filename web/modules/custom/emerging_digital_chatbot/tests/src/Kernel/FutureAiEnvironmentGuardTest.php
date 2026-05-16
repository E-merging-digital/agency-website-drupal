<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiEnvironmentGuard;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderGatewayInterface;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderRegistry;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse;
use Drupal\emerging_digital_chatbot\FutureAi\MockFutureAiProviderGateway;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\Entity\Key;
use Drupal\key\KeyRepositoryInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the centralized Future AI environment guard.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class FutureAiEnvironmentGuardTest extends KernelTestBase {

  private const API_KEY = 'sk-test-secret-never-exposed';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'emerging_digital_chatbot',
    'path_alias',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['emerging_digital_chatbot']);
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER');
  }

  /**
   * Tests that enabled Future AI still needs explicit environment allowance.
   */
  public function testEnvironmentBlockedByDefault(): void {
    $this->configureFutureAi(TRUE);

    $guard = $this->createGuard($this->getKeyRepository(self::API_KEY));

    self::assertFalse($guard->allowsExternalCalls());
    self::assertSame('environment_blocked', $guard->getBlockReason());
    self::assertSame('', $guard->resolveApiKey());

    $summary = $guard->getAdminSummary();
    self::assertSame('enabled', $summary['future_ai_state']);
    self::assertSame('openai', $summary['provider']);
    self::assertSame('blocked', $summary['environment']);
    self::assertSame('environment_blocked', $summary['reason']);
    self::assertSame('available', $summary['key_status']);
    self::assertSame('no', $summary['external_calls_allowed']);
  }

  /**
   * Tests explicitly allowed environment with a readable Key.
   */
  public function testEnvironmentAllowedWithReadableKey(): void {
    $this->configureFutureAi(TRUE);
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true');

    $guard = $this->createGuard($this->getKeyRepository(self::API_KEY));

    self::assertTrue($guard->allowsExternalCalls());
    self::assertSame('', $guard->getBlockReason());
    self::assertSame(self::API_KEY, $guard->resolveApiKey());

    $summary = $guard->getAdminSummary();
    self::assertSame('allowed', $summary['environment']);
    self::assertSame('available', $summary['key_status']);
    self::assertSame('yes', $summary['external_calls_allowed']);

    $exposed = json_encode($summary, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString(self::API_KEY, $exposed);
    self::assertStringNotContainsString('openai_api_key', $exposed);
  }

  /**
   * Tests disabled Future AI blocks external calls even in allowed envs.
   */
  public function testDisabledFutureAiBlocksCalls(): void {
    $this->configureFutureAi(FALSE);
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true');

    $guard = $this->createGuard($this->getKeyRepository(self::API_KEY));

    self::assertFalse($guard->allowsExternalCalls());
    self::assertSame('future_ai_disabled', $guard->getBlockReason());
    self::assertSame('disabled', $guard->getAdminSummary()['future_ai_state']);
  }

  /**
   * Tests unsupported providers block external calls even with a valid Key.
   */
  public function testUnsupportedProviderBlocksCalls(): void {
    $this->configureFutureAi(TRUE);
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'disabled_provider')
      ->save();
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true');

    $guard = $this->createGuard($this->getKeyRepository(self::API_KEY));

    self::assertFalse($guard->allowsExternalCalls());
    self::assertSame('unsupported_provider', $guard->getBlockReason());
    self::assertSame('', $guard->resolveApiKey());

    $summary = $guard->getAdminSummary();
    self::assertSame('disabled_provider', $summary['provider']);
    self::assertSame('unsupported_provider', $summary['reason']);
    self::assertSame('no', $summary['external_calls_allowed']);
  }

  /**
   * Tests a missing Key entity blocks external calls.
   */
  public function testMissingKeyBlocksCalls(): void {
    $this->configureFutureAi(TRUE);
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true');

    $guard = $this->createGuard($this->getKeyRepository(''));

    self::assertFalse($guard->allowsExternalCalls());
    self::assertSame('key_missing', $guard->getBlockReason());
    self::assertSame('missing', $guard->getAdminSummary()['key_status']);
    self::assertSame('', $guard->resolveApiKey());
  }

  /**
   * Tests an unreadable Key blocks external calls.
   */
  public function testUnreadableKeyBlocksCalls(): void {
    $this->configureFutureAi(TRUE);
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true');

    $guard = $this->createGuard($this->getUnreadableKeyRepository());

    self::assertFalse($guard->allowsExternalCalls());
    self::assertSame('key_unreadable', $guard->getBlockReason());
    self::assertSame('unreadable', $guard->getAdminSummary()['key_status']);
    self::assertSame('', $guard->resolveApiKey());
  }

  /**
   * Tests the mock provider is blocked unless its runtime flag is explicit.
   */
  public function testMockProviderBlocksByDefaultWithoutKeyLookup(): void {
    $this->configureFutureAi(TRUE, 'mock');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI=true');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER');

    $guard = $this->createGuard(
      $this->getUnreadableKeyRepository(),
      [new MockFutureAiProviderGateway()],
    );

    self::assertFalse($guard->allowsProviderDispatch());
    self::assertFalse($guard->allowsExternalCalls());
    self::assertSame('unsupported_provider', $guard->getBlockReason());
    self::assertSame('', $guard->resolveApiKey());

    $summary = $guard->getAdminSummary();
    self::assertSame('mock', $summary['provider']);
    self::assertSame('blocked', $summary['environment']);
    self::assertSame('unsupported_provider', $summary['reason']);
    self::assertSame('not_required', $summary['key_status']);
    self::assertSame('no', $summary['external_calls_allowed']);
  }

  /**
   * Tests the mock provider needs no external allowance or API key.
   */
  public function testMockProviderAllowsLocalDispatchOnlyWhenExplicit(): void {
    $this->configureFutureAi(TRUE, 'mock');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_EXTERNAL_AI');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER=true');

    $guard = $this->createGuard(
      $this->getUnreadableKeyRepository(),
      [new MockFutureAiProviderGateway()],
    );

    self::assertTrue($guard->allowsProviderDispatch());
    self::assertFalse($guard->allowsExternalCalls());
    self::assertFalse($guard->requiresProviderApiKey());
    self::assertSame('', $guard->getBlockReason());
    self::assertSame('', $guard->resolveApiKey());

    $summary = $guard->getAdminSummary();
    self::assertSame('mock', $summary['provider']);
    self::assertSame('allowed', $summary['environment']);
    self::assertSame('none', $summary['reason']);
    self::assertSame('not_required', $summary['key_status']);
    self::assertSame('no', $summary['external_calls_allowed']);
  }

  /**
   * Configures the Future AI switch and Key id.
   */
  private function configureFutureAi(bool $enabled, string $provider = 'openai'): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.enabled', $enabled)
      ->set('future_ai.provider', $provider)
      ->set('future_ai.openai_key_id', 'openai_api_key')
      ->save();
  }

  /**
   * Creates the guard under test.
   *
   * @param \Drupal\key\KeyRepositoryInterface $keyRepository
   *   Key repository.
   * @param iterable|null $providers
   *   Provider gateways.
   */
  private function createGuard(
    KeyRepositoryInterface $keyRepository,
    ?iterable $providers = NULL,
  ): FutureAiEnvironmentGuard {
    $providerRegistry = new FutureAiProviderRegistry(
      $this->container->get('config.factory'),
      $providers ?? [new EnvironmentGuardProviderGateway()],
    );

    return new FutureAiEnvironmentGuard(
      $this->getChatbotConfig(),
      $this->container->get('config.factory'),
      $keyRepository,
      $providerRegistry,
    );
  }

  /**
   * Gets the chatbot config service.
   */
  private function getChatbotConfig(): ChatbotConfig {
    $config = $this->container->get('emerging_digital_chatbot.config');
    self::assertInstanceOf(ChatbotConfig::class, $config);

    return $config;
  }

  /**
   * Gets a Key repository stub.
   */
  private function getKeyRepository(string $apiKey): KeyRepositoryInterface {
    return new class($apiKey) implements KeyRepositoryInterface {

      public function __construct(
        private readonly string $apiKey,
      ) {
      }

      /**
       * {@inheritdoc}
       */
      public function getKeys(?array $key_ids = NULL): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByProvider($key_provider_id): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByType($key_type_id): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByTags(array $tags): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByStorageMethod($storage_method): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByTypeGroup($type_group): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKey($key_id): ?Key {
        if ($key_id !== 'openai_api_key' || $this->apiKey === '') {
          return NULL;
        }

        return new class($this->apiKey) extends Key {

          public function __construct(
            private readonly string $apiKey,
          ) {
          }

          /**
           * Gets the fake secret value.
           */
          public function getKeyValue($reset = FALSE): string {
            return $this->apiKey;
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function getKeyNamesAsOptions(array $filters): array {
        return [];
      }

    };
  }

  /**
   * Gets a Key repository that fails during value resolution.
   */
  private function getUnreadableKeyRepository(): KeyRepositoryInterface {
    return new class() implements KeyRepositoryInterface {

      /**
       * {@inheritdoc}
       */
      public function getKeys(?array $key_ids = NULL): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByProvider($key_provider_id): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByType($key_type_id): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByTags(array $tags): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByStorageMethod($storage_method): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKeysByTypeGroup($type_group): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function getKey($key_id): ?Key {
        if ($key_id !== 'openai_api_key') {
          return NULL;
        }

        return new class() extends Key {

          public function __construct() {
          }

          /**
           * Throws while resolving the fake secret value.
           */
          public function getKeyValue($reset = FALSE): string {
            throw new \RuntimeException('Unreadable test key.');
          }

        };
      }

      /**
       * {@inheritdoc}
       */
      public function getKeyNamesAsOptions(array $filters): array {
        return [];
      }

    };
  }

}

/**
 * Minimal enabled OpenAI provider for guard tests.
 */
final class EnvironmentGuardProviderGateway implements FutureAiProviderGatewayInterface {

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return FutureAiProviderRegistry::PROVIDER_OPENAI;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return TRUE;
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
    return FutureAiResponse::aiResponse('', $langcode);
  }

}

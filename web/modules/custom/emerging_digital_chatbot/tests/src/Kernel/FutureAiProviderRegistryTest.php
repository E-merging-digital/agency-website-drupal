<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderGatewayInterface;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderRegistry;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Future AI provider registry.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class FutureAiProviderRegistryTest extends KernelTestBase {

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
  }

  /**
   * Tests provider lookup by stable id.
   */
  public function testKnownProviderCanBeRetrieved(): void {
    $openai = new RegistryProviderGateway('openai');
    $registry = $this->createRegistry([$openai]);

    self::assertSame($openai, $registry->getProvider('openai'));
    self::assertSame($openai, $registry->getActiveProvider());
    self::assertSame('openai', $registry->getActiveProviderId());
  }

  /**
   * Tests the historical OpenAI Responses id remains compatible.
   */
  public function testLegacyOpenAiResponsesProviderIdResolvesToOpenAi(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'openai_responses')
      ->save();

    $openai = new RegistryProviderGateway('openai');
    $registry = $this->createRegistry([$openai]);

    self::assertSame($openai, $registry->getActiveProvider());
    self::assertSame('openai', $registry->getActiveProviderId());
  }

  /**
   * Tests the explicit null provider can be resolved but stays disabled.
   */
  public function testNullProviderResolvesAsDisabledProvider(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'null')
      ->save();

    $nullProvider = new RegistryProviderGateway('null', FALSE);
    $registry = $this->createRegistry([$nullProvider]);
    $provider = $registry->getActiveProvider();

    self::assertSame($nullProvider, $provider);
    self::assertSame('null', $registry->getActiveProviderId());
    self::assertFalse($provider->isEnabled());
  }

  /**
   * Tests unknown configured providers fail closed by resolving to NULL.
   */
  public function testUnknownProviderReturnsNull(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'unknown_provider')
      ->save();

    $registry = $this->createRegistry([new RegistryProviderGateway('openai')]);

    self::assertNull($registry->getActiveProvider());
    self::assertSame('unknown_provider', $registry->getActiveProviderId());
  }

  /**
   * Tests missing provider services fail closed.
   */
  public function testMissingProviderServiceReturnsNull(): void {
    $registry = $this->createRegistry([]);

    self::assertSame('openai', $registry->getActiveProviderId());
    self::assertNull($registry->getActiveProvider());
  }

  /**
   * Tests invalid provider ids fail closed.
   */
  public function testInvalidProviderIdReturnsNull(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', '../openai')
      ->save();

    $registry = $this->createRegistry([new RegistryProviderGateway('openai')]);

    self::assertSame('', $registry->getActiveProviderId());
    self::assertNull($registry->getActiveProvider());
  }

  /**
   * Creates a registry under test.
   *
   * @param iterable<\Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderGatewayInterface> $providers
   *   Provider gateways.
   */
  private function createRegistry(iterable $providers): FutureAiProviderRegistry {
    return new FutureAiProviderRegistry(
      $this->container->get('config.factory'),
      $providers,
    );
  }

}

/**
 * Provider gateway test double for registry tests.
 */
final class RegistryProviderGateway implements FutureAiProviderGatewayInterface {

  public function __construct(
    private readonly string $providerId,
    private readonly bool $enabled = TRUE,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function getProviderId(): string {
    return $this->providerId;
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return $this->enabled;
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

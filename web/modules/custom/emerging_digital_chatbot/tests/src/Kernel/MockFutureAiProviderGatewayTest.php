<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\emerging_digital_chatbot\FutureAi\FutureAiProviderRegistry;
use Drupal\emerging_digital_chatbot\FutureAi\MockFutureAiProviderGateway;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the controlled local mock Future AI provider.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class MockFutureAiProviderGatewayTest extends KernelTestBase {

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
   * Previous mock allowance env value.
   */
  private string|false $previousAllowMockProvider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['emerging_digital_chatbot']);
    $this->previousAllowMockProvider = getenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER');
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->restoreEnv(
      'EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER',
      $this->previousAllowMockProvider,
    );

    parent::tearDown();
  }

  /**
   * Tests the mock provider identity and default disabled state.
   */
  public function testMockProviderIsDisabledByDefault(): void {
    $provider = new MockFutureAiProviderGateway();

    self::assertSame('mock', $provider->getProviderId());
    self::assertFalse($provider->isEnabled());
  }

  /**
   * Tests the tagged service is registered in the provider registry.
   */
  public function testTaggedMockProviderIsRegistered(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.provider', 'mock')
      ->save();

    $registry = $this->container
      ->get('emerging_digital_chatbot.future_ai_provider_registry');
    self::assertInstanceOf(FutureAiProviderRegistry::class, $registry);

    $provider = $registry->getProvider('mock');
    self::assertInstanceOf(MockFutureAiProviderGateway::class, $provider);
    self::assertSame($provider, $registry->getActiveProvider());
    self::assertSame('mock', $registry->getActiveProviderId());
    self::assertFalse($provider->isEnabled());
  }

  /**
   * Tests explicit runtime activation through the environment variable.
   */
  public function testMockProviderCanBeEnabledExplicitlyAtRuntime(): void {
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER=true');

    $provider = new MockFutureAiProviderGateway();

    self::assertTrue($provider->isEnabled());
  }

  /**
   * Tests the response is deterministic and excludes all request material.
   */
  public function testMockProviderResponseIsControlledAndStateless(): void {
    putenv('EMERGING_DIGITAL_CHATBOT_ALLOW_MOCK_PROVIDER=true');
    $provider = new MockFutureAiProviderGateway();

    $response = $provider->respond(
      [
        'message' => 'Raw visitor message with sk-secret',
        'need' => 'Confidential need',
      ],
      'fr',
      'Full public RAG context that must not be echoed.',
      '',
    )->toArray();

    self::assertSame('ai_response', $response['status']);
    self::assertSame(
      'Reponse de demonstration controlee. Un membre de l\'equipe peut '
        . 'reprendre votre demande.',
      $response['message'],
    );
    self::assertFalse($response['fallback']);
    self::assertFalse($response['stored']);
    self::assertSame('fr', $response['langcode']);

    $exposed = json_encode($response, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('sk-secret', $exposed);
    self::assertStringNotContainsString('Raw visitor message', $exposed);
    self::assertStringNotContainsString('Confidential need', $exposed);
    self::assertStringNotContainsString('Full public RAG context', $exposed);
  }

  /**
   * Restores an environment variable after a test-only override.
   */
  private function restoreEnv(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }

    putenv($name . '=' . $value);
  }

}

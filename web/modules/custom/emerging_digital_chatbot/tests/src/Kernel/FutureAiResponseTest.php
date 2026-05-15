<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseReason;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseStatus;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the typed Future AI response contract.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class FutureAiResponseTest extends KernelTestBase {

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
   * Tests success serialization keeps the existing public shape.
   */
  public function testSuccessSerializesToPublicArray(): void {
    $response = FutureAiResponse::aiResponse('Orientation utile.', 'fr');

    self::assertSame(FutureAiResponseStatus::AiResponse, $response->getStatus());
    self::assertSame(FutureAiResponseReason::Success, $response->getReason());
    self::assertSame([
      'status' => 'ai_response',
      'message' => 'Orientation utile.',
      'fallback' => FALSE,
      'stored' => FALSE,
      'langcode' => 'fr',
    ], $response->toArray());
  }

  /**
   * Tests fallback serialization can include only the public Future AI summary.
   */
  public function testFallbackSerializesWithoutSensitiveSummaryData(): void {
    $response = FutureAiResponse::fallback(
      FutureAiResponseStatus::GuideOnly,
      FutureAiResponseReason::FutureAiDisabled,
      'Fallback stable.',
      'fr',
      [
        'enabled' => FALSE,
        'provider' => 'openai_responses',
        'model' => 'gpt-test',
        'promptVersion' => 'v1',
        'ragProfile' => 'public_pages_v1',
        'retentionPolicy' => 'none',
        'promptPrepared' => TRUE,
        'secret' => 'sk-test-secret-never-exposed',
        'context' => [
          'profile' => 'public_pages_v1',
          'langcode' => 'fr',
          'maxContextChars' => 5000,
          'allowedPublicPaths' => ['/fr/services'],
          'rawPrompt' => 'Hidden system prompt.',
        ],
      ],
    );

    $serialized = $response->toArray();
    self::assertSame('guide_only', $serialized['status']);
    self::assertTrue($serialized['fallback']);
    self::assertArrayHasKey('futureAi', $serialized);

    $exposed = json_encode($serialized, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('sk-test-secret', $exposed);
    self::assertStringNotContainsString('Hidden system prompt.', $exposed);
    self::assertStringContainsString('public_pages_v1', $exposed);
  }

  /**
   * Tests controlled provider failures normalize unknown statuses.
   */
  public function testProviderFailureStatusesAreControlled(): void {
    self::assertSame(
      FutureAiResponseStatus::ProviderError,
      FutureAiResponseStatus::providerFailureFromValue('raw_provider_payload'),
    );
    self::assertSame(
      FutureAiResponseStatus::ProviderTimeout,
      FutureAiResponseStatus::providerFailureFromValue('provider_timeout'),
    );
  }

  /**
   * Tests detailed reasons map to the controlled monitoring vocabulary.
   */
  public function testReasonsAreControlledForMonitoring(): void {
    self::assertSame(
      'fallback_used',
      FutureAiResponseReason::SensitiveInputBlocked->toMonitoringValue(),
    );
    self::assertSame(
      'provider_error',
      FutureAiResponseReason::ProviderError->toMonitoringValue(),
    );
    self::assertNull(FutureAiResponseReason::fromValue('visitor message'));
  }

}

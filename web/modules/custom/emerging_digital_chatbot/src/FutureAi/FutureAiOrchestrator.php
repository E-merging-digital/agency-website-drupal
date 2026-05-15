<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\emerging_digital_chatbot\ChatbotConfig;
use Psr\Log\LoggerInterface;

/**
 * Centralizes deterministic Future AI decisions before provider calls.
 */
final class FutureAiOrchestrator implements FutureAiGatewayInterface {

  public function __construct(
    private readonly ChatbotConfig $chatbotConfig,
    private readonly FutureAiEnvironmentGuard $environmentGuard,
    private readonly PublicAiContextProvider $contextProvider,
    private readonly FutureAiProviderRegistry $providerRegistry,
    private readonly FutureAiGatewayInterface $fallbackGateway,
    private readonly FutureAiMonitoring $monitoring,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function respond(array $payload): FutureAiResponse {
    $langcode = $this->getPayloadLangcode($payload);

    if ($this->chatbotConfig->getMode() !== 'ai') {
      $this->monitoring->recordFallback();

      return $this->fallback(
        FutureAiResponseStatus::GuideOnly,
        FutureAiResponseReason::GuideOnly,
        $langcode,
        TRUE,
      );
    }

    if (!$this->chatbotConfig->isFutureAiEnabled()) {
      $this->monitoring->recordBlocked(FutureAiResponseReason::FutureAiDisabled);

      return $this->fallback(
        FutureAiResponseStatus::GuideOnly,
        FutureAiResponseReason::FutureAiDisabled,
        $langcode,
        TRUE,
      );
    }

    if (!empty($payload['blocked_sensitive_input'])) {
      $this->monitoring->recordFallback();

      return $this->fallback(
        FutureAiResponseStatus::SensitiveInputBlocked,
        FutureAiResponseReason::SensitiveInputBlocked,
        $langcode,
      );
    }

    $message = trim((string) ($payload['message'] ?? ''));
    if ($message === '') {
      $this->monitoring->recordFallback();

      return $this->fallback(
        FutureAiResponseStatus::EmptyMessage,
        FutureAiResponseReason::EmptyMessage,
        $langcode,
      );
    }

    $providerGateway = $this->providerRegistry->getActiveProvider();
    if ($providerGateway === NULL || !$providerGateway->isEnabled()) {
      $this->logger->warning('Chatbot AI provider blocked: @reason', [
        '@reason' => FutureAiResponseReason::UnsupportedProvider->value,
      ]);
      $this->monitoring->recordBlocked(
        FutureAiResponseReason::UnsupportedProvider,
      );

      return $this->fallback(
        FutureAiResponseStatus::UnsupportedProvider,
        FutureAiResponseReason::UnsupportedProvider,
        $langcode,
      );
    }

    if (!$this->environmentGuard->allowsExternalCalls()) {
      $reason = $this->getControlledReason(
        $this->environmentGuard->getBlockReason(),
      );
      $this->logger->warning('Chatbot AI provider blocked: @reason', [
        '@reason' => $reason->value,
      ]);
      $this->monitoring->recordBlocked($reason);

      return $this->fallback(
        $this->getStatusForReason($reason),
        $reason,
        $langcode,
      );
    }

    $apiKey = $this->environmentGuard->resolveApiKey();
    if ($apiKey === '') {
      $this->monitoring->recordBlocked(FutureAiResponseReason::KeyMissing);

      return $this->fallback(
        FutureAiResponseStatus::KeyMissing,
        FutureAiResponseReason::KeyMissing,
        $langcode,
      );
    }

    $context = $this->contextProvider->buildContextContract($langcode);
    if (!$context['enabled'] || $context['status'] !== 'ready') {
      $this->monitoring->recordBlocked(FutureAiResponseReason::ContextEmpty);

      return $this->fallback(
        FutureAiResponseStatus::ContextEmpty,
        FutureAiResponseReason::ContextEmpty,
        $langcode,
      );
    }

    $providerResult = $providerGateway->respond(
      $payload,
      $langcode,
      $this->buildPromptContext($context),
      $apiKey,
    );

    if ($providerResult->isAiResponse()) {
      $this->monitoring->recordSuccess();

      return $providerResult;
    }

    $status = $this->getProviderFailureStatus($providerResult);
    $reason = $providerResult->getReason();
    if ($status === FutureAiResponseStatus::ProviderTimeout) {
      $this->monitoring->recordProviderError(
        FutureAiResponseReason::ProviderTimeout,
      );
    }
    elseif ($status === FutureAiResponseStatus::ProviderError) {
      $this->monitoring->recordProviderError(
        FutureAiResponseReason::ProviderError,
      );
    }
    else {
      $this->monitoring->recordFallback();
    }

    return $this->fallback($status, $reason, $langcode);
  }

  /**
   * Gets the sanitized payload language.
   *
   * @param array<string, mixed> $payload
   *   Sanitized payload.
   */
  private function getPayloadLangcode(array $payload): string {
    $langcode = (string) ($payload['langcode'] ?? '');

    return in_array($langcode, ['fr', 'en'], TRUE)
      ? $langcode
      : $this->chatbotConfig->getCurrentLangcode();
  }

  /**
   * Builds the provider prompt context from an already-inspected contract.
   *
   * @param array<string, mixed> $context
   *   Public context contract.
   */
  private function buildPromptContext(array $context): string {
    $lines = [
      'Context profile: ' . $context['profile'] . '.',
      'Public context status: ' . $context['status'] . '.',
      'Allowed context: published public pages only.',
      'Excluded context: admin pages, drafts, webform submissions, '
      . 'private files, CRM data, visitor conversations, and personal data.',
      'Retrieval status: Drupal public context builder active; '
      . 'no vector store or autonomous tool call is active.',
      'Allowed public paths: ' . implode(', ', $context['paths']),
      'Public Drupal context:',
      $context['text'],
    ];

    return mb_substr(
      implode("\n", $lines),
      0,
      $this->chatbotConfig->getFutureAiMaxContextChars(),
    );
  }

  /**
   * Normalizes provider failures to the controlled public status vocabulary.
   *
   * @param \Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponse $providerResult
   *   Provider response.
   */
  private function getProviderFailureStatus(
    FutureAiResponse $providerResult,
  ): FutureAiResponseStatus {
    return FutureAiResponseStatus::providerFailureFromValue(
      $providerResult->getStatus()->value,
    );
  }

  /**
   * Gets a deterministic local fallback response.
   */
  private function fallback(
    FutureAiResponseStatus $status,
    FutureAiResponseReason $reason,
    string $langcode,
    bool $includeSummary = FALSE,
  ): FutureAiResponse {
    $response = $this->fallbackGateway->respond([
      'langcode' => $langcode,
      'reason' => $reason->value,
    ]);

    return FutureAiResponse::fallback(
      $status,
      $reason,
      $response->getMessage(),
      $langcode,
      $includeSummary
        ? $this->chatbotConfig->getFutureAiSummary($langcode)
        : NULL,
    );
  }

  /**
   * Gets a controlled reason from environment guard values.
   */
  private function getControlledReason(string $reason): FutureAiResponseReason {
    return FutureAiResponseReason::fromValue($reason)
      ?? FutureAiResponseReason::FallbackUsed;
  }

  /**
   * Maps a controlled reason to the compatible public status value.
   */
  private function getStatusForReason(
    FutureAiResponseReason $reason,
  ): FutureAiResponseStatus {
    return match ($reason) {
      FutureAiResponseReason::ContextEmpty => FutureAiResponseStatus::ContextEmpty,
      FutureAiResponseReason::EnvironmentBlocked =>
        FutureAiResponseStatus::EnvironmentBlocked,
      FutureAiResponseReason::KeyMissing => FutureAiResponseStatus::KeyMissing,
      FutureAiResponseReason::KeyUnreadable => FutureAiResponseStatus::KeyUnreadable,
      FutureAiResponseReason::ProviderTimeout =>
        FutureAiResponseStatus::ProviderTimeout,
      FutureAiResponseReason::UnsupportedProvider =>
        FutureAiResponseStatus::UnsupportedProvider,
      default => FutureAiResponseStatus::ProviderError,
    };
  }

}

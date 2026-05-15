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
    private readonly FutureAiProviderGatewayInterface $providerGateway,
    private readonly FutureAiGatewayInterface $fallbackGateway,
    private readonly FutureAiMonitoring $monitoring,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function respond(array $payload): array {
    $langcode = $this->getPayloadLangcode($payload);

    if ($this->chatbotConfig->getMode() !== 'ai') {
      $this->monitoring->recordFallback();

      return $this->fallback('guide_only', $langcode, TRUE);
    }

    if (!$this->chatbotConfig->isFutureAiEnabled()) {
      $this->monitoring->recordBlocked('future_ai_disabled');

      return $this->fallback('guide_only', $langcode, TRUE);
    }

    if (!empty($payload['blocked_sensitive_input'])) {
      $this->monitoring->recordFallback();

      return $this->fallback('sensitive_input_blocked', $langcode);
    }

    $message = trim((string) ($payload['message'] ?? ''));
    if ($message === '') {
      $this->monitoring->recordFallback();

      return $this->fallback('empty_message', $langcode);
    }

    if (!$this->environmentGuard->allowsExternalCalls()) {
      $reason = $this->environmentGuard->getBlockReason();
      $this->logger->warning('Chatbot AI provider blocked: @reason', [
        '@reason' => $reason,
      ]);
      $this->monitoring->recordBlocked($reason);

      return $this->fallback($reason, $langcode);
    }

    $apiKey = $this->environmentGuard->resolveApiKey();
    if ($apiKey === '') {
      $this->monitoring->recordBlocked('key_missing');

      return $this->fallback('key_missing', $langcode);
    }

    $context = $this->contextProvider->buildContextContract($langcode);
    if (!$context['enabled'] || $context['status'] !== 'ready') {
      $this->monitoring->recordBlocked('context_empty');

      return $this->fallback('context_empty', $langcode);
    }

    $providerResult = $this->providerGateway->respond(
      $payload,
      $langcode,
      $this->buildPromptContext($context),
      $apiKey,
    );

    if (($providerResult['status'] ?? '') === 'ai_response'
      && empty($providerResult['fallback'])
      && is_string($providerResult['message'] ?? NULL)
      && trim((string) $providerResult['message']) !== '') {
      $this->monitoring->recordSuccess();

      return [
        'status' => 'ai_response',
        'message' => (string) $providerResult['message'],
        'fallback' => FALSE,
        'stored' => FALSE,
        'langcode' => $langcode,
      ];
    }

    $status = $this->getProviderFailureStatus($providerResult);
    if ($status === 'provider_timeout') {
      $this->monitoring->recordProviderError('provider_timeout');
    }
    elseif ($status === 'provider_error') {
      $this->monitoring->recordProviderError('provider_error');
    }
    else {
      $this->monitoring->recordFallback();
    }

    return $this->fallback($status, $langcode);
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
   * @param array<string, mixed> $providerResult
   *   Provider result.
   */
  private function getProviderFailureStatus(array $providerResult): string {
    $status = (string) ($providerResult['status'] ?? 'provider_error');

    return in_array($status, [
      'provider_timeout',
      'provider_error',
      'guardrail_fallback',
      'empty_ai_response',
    ], TRUE) ? $status : 'provider_error';
  }

  /**
   * Gets a deterministic local fallback response.
   *
   * @return array<string, mixed>
   *   Fallback response.
   */
  private function fallback(
    string $status,
    string $langcode,
    bool $includeSummary = FALSE,
  ): array {
    $response = $this->fallbackGateway->respond([
      'langcode' => $langcode,
      'reason' => $status,
    ]);
    $response['status'] = $status;
    $response['fallback'] = TRUE;
    $response['stored'] = FALSE;
    $response['langcode'] = $langcode;

    if ($includeSummary) {
      $response['futureAi'] = $this->chatbotConfig->getFutureAiSummary($langcode);
    }

    return $response;
  }

}

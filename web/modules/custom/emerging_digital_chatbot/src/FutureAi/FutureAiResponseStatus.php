<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

/**
 * Controlled public Future AI response status values.
 */
enum FutureAiResponseStatus: string {

  case AiResponse = 'ai_response';
  case ContextEmpty = 'context_empty';
  case EmptyAiResponse = 'empty_ai_response';
  case EmptyMessage = 'empty_message';
  case EnvironmentBlocked = 'environment_blocked';
  case GuardrailFallback = 'guardrail_fallback';
  case GuideOnly = 'guide_only';
  case InvalidPayload = 'invalid_payload';
  case KeyMissing = 'key_missing';
  case KeyUnreadable = 'key_unreadable';
  case ProviderError = 'provider_error';
  case ProviderTimeout = 'provider_timeout';
  case RateLimited = 'rate_limited';
  case SensitiveInputBlocked = 'sensitive_input_blocked';
  case UnsupportedProvider = 'unsupported_provider';

  /**
   * Gets a controlled provider failure status.
   */
  public static function providerFailureFromValue(string $value): self {
    return match ($value) {
      self::EmptyAiResponse->value => self::EmptyAiResponse,
      self::GuardrailFallback->value => self::GuardrailFallback,
      self::ProviderTimeout->value => self::ProviderTimeout,
      default => self::ProviderError,
    };
  }

}

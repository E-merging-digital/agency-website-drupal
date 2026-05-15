<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

/**
 * Controlled internal Future AI outcome reasons.
 */
enum FutureAiResponseReason: string {

  case ContextEmpty = 'context_empty';
  case EmptyAiResponse = 'empty_ai_response';
  case EmptyMessage = 'empty_message';
  case EnvironmentBlocked = 'environment_blocked';
  case FallbackUsed = 'fallback_used';
  case FutureAiDisabled = 'future_ai_disabled';
  case GuardrailFallback = 'guardrail_fallback';
  case GuideOnly = 'guide_only';
  case InvalidPayload = 'invalid_payload';
  case KeyMissing = 'key_missing';
  case KeyUnreadable = 'key_unreadable';
  case ProviderError = 'provider_error';
  case ProviderTimeout = 'provider_timeout';
  case RateLimited = 'rate_limited';
  case SensitiveInputBlocked = 'sensitive_input_blocked';
  case Success = 'success';
  case UnsupportedProvider = 'unsupported_provider';

  /**
   * Gets a reason from a controlled public value.
   */
  public static function fromValue(string $value): ?self {
    foreach (self::cases() as $case) {
      if ($case->value === $value) {
        return $case;
      }
    }

    return NULL;
  }

  /**
   * Gets monitoring reason values exposed in the admin summary.
   *
   * @return array<int, string>
   *   Controlled monitoring reason values.
   */
  public static function monitoringValues(): array {
    return [
      self::EnvironmentBlocked->value,
      self::FutureAiDisabled->value,
      self::KeyMissing->value,
      self::KeyUnreadable->value,
      self::UnsupportedProvider->value,
      self::ContextEmpty->value,
      self::ProviderTimeout->value,
      self::ProviderError->value,
      self::FallbackUsed->value,
      self::Success->value,
    ];
  }

  /**
   * Converts a detailed reason to the stored monitoring vocabulary.
   */
  public function toMonitoringValue(): string {
    return in_array($this->value, self::monitoringValues(), TRUE)
      ? $this->value
      : self::FallbackUsed->value;
  }

}

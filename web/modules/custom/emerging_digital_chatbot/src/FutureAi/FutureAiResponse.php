<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

/**
 * Typed Future AI response safe for final public serialization.
 */
final readonly class FutureAiResponse {

  /**
   * Sanitized Future AI summary.
   *
   * @var array<string, mixed>|null
   */
  private ?array $futureAiSummary;

  /**
   * Builds a typed response with controlled fields only.
   *
   * @param \Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseStatus $status
   *   Controlled public status.
   * @param \Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseReason $reason
   *   Controlled internal reason.
   * @param string $message
   *   Public message.
   * @param bool $fallback
   *   Whether the response is a fallback.
   * @param bool $stored
   *   Whether the response was stored.
   * @param string $langcode
   *   Public response language code.
   * @param array<string, mixed>|null $futureAiSummary
   *   Optional sanitized Future AI public summary.
   */
  private function __construct(
    private FutureAiResponseStatus $status,
    private FutureAiResponseReason $reason,
    private string $message,
    private bool $fallback,
    private bool $stored,
    private string $langcode,
    ?array $futureAiSummary = NULL,
  ) {
    $this->futureAiSummary = $futureAiSummary !== NULL
      ? self::sanitizeFutureAiSummary($futureAiSummary)
      : NULL;
  }

  /**
   * Creates a successful AI response.
   */
  public static function aiResponse(string $message, string $langcode): self {
    return new self(
      FutureAiResponseStatus::AiResponse,
      FutureAiResponseReason::Success,
      $message,
      FALSE,
      FALSE,
      $langcode,
    );
  }

  /**
   * Creates a local or provider fallback response.
   *
   * @param \Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseStatus $status
   *   Controlled public fallback status.
   * @param \Drupal\emerging_digital_chatbot\FutureAi\FutureAiResponseReason $reason
   *   Controlled internal fallback reason.
   * @param string $message
   *   Public fallback message.
   * @param string $langcode
   *   Public response language code.
   * @param array<string, mixed>|null $futureAiSummary
   *   Optional public Future AI summary.
   */
  public static function fallback(
    FutureAiResponseStatus $status,
    FutureAiResponseReason $reason,
    string $message,
    string $langcode,
    ?array $futureAiSummary = NULL,
  ): self {
    return new self(
      $status,
      $reason,
      $message,
      TRUE,
      FALSE,
      $langcode,
      $futureAiSummary,
    );
  }

  /**
   * Creates a provider failure without carrying provider payload content.
   */
  public static function providerFailure(
    FutureAiResponseStatus $status,
    string $langcode,
  ): self {
    return self::fallback(
      $status,
      FutureAiResponseReason::fromValue($status->value)
        ?? FutureAiResponseReason::ProviderError,
      '',
      $langcode,
    );
  }

  /**
   * Gets the controlled public status.
   */
  public function getStatus(): FutureAiResponseStatus {
    return $this->status;
  }

  /**
   * Gets the controlled internal reason.
   */
  public function getReason(): FutureAiResponseReason {
    return $this->reason;
  }

  /**
   * Gets the public message.
   */
  public function getMessage(): string {
    return $this->message;
  }

  /**
   * Determines whether this is a successful provider response.
   */
  public function isAiResponse(): bool {
    return $this->status === FutureAiResponseStatus::AiResponse
      && !$this->fallback
      && trim($this->message) !== '';
  }

  /**
   * Serializes the response to the stable public HTTP payload.
   *
   * @return array<string, mixed>
   *   Public response payload.
   */
  public function toArray(): array {
    $response = [
      'status' => $this->status->value,
      'message' => $this->message,
      'fallback' => $this->fallback,
      'stored' => $this->stored,
      'langcode' => $this->langcode,
    ];

    if ($this->futureAiSummary !== NULL) {
      $response['futureAi'] = $this->futureAiSummary;
    }

    return $response;
  }

  /**
   * Keeps the optional Future AI summary free of arbitrary extra fields.
   *
   * @param array<string, mixed> $summary
   *   Candidate summary.
   *
   * @return array<string, mixed>
   *   Sanitized public summary.
   */
  private static function sanitizeFutureAiSummary(array $summary): array {
    $context = is_array($summary['context'] ?? NULL)
      ? $summary['context']
      : [];
    $paths = is_array($context['allowedPublicPaths'] ?? NULL)
      ? $context['allowedPublicPaths']
      : [];

    return [
      'enabled' => (bool) ($summary['enabled'] ?? FALSE),
      'provider' => self::sanitizeSummaryText($summary['provider'] ?? '', 80),
      'model' => self::sanitizeSummaryText($summary['model'] ?? '', 120),
      'promptVersion' => self::sanitizeSummaryText(
        $summary['promptVersion'] ?? '',
        80,
      ),
      'ragProfile' => self::sanitizeSummaryText($summary['ragProfile'] ?? '', 80),
      'retentionPolicy' => self::sanitizeSummaryText(
        $summary['retentionPolicy'] ?? '',
        80,
      ),
      'promptPrepared' => (bool) ($summary['promptPrepared'] ?? FALSE),
      'context' => [
        'profile' => self::sanitizeSummaryText($context['profile'] ?? '', 80),
        'langcode' => self::sanitizeSummaryText($context['langcode'] ?? '', 16),
        'maxContextChars' => self::sanitizeSummaryInt(
          $context['maxContextChars'] ?? 0,
        ),
        'allowedPublicPaths' => self::sanitizeSummaryStringList($paths),
      ],
    ];
  }

  /**
   * Sanitizes small public summary strings.
   */
  private static function sanitizeSummaryText(mixed $value, int $maxLength): string {
    if (!is_scalar($value)) {
      return '';
    }

    $text = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value);
    $text = preg_replace('/\s+/u', ' ', (string) $text);

    return mb_substr(trim((string) $text), 0, $maxLength);
  }

  /**
   * Sanitizes a non-negative public summary integer.
   */
  private static function sanitizeSummaryInt(mixed $value): int {
    return is_int($value) ? max(0, $value) : 0;
  }

  /**
   * Sanitizes a list of public path strings.
   *
   * @param array<int|string, mixed> $values
   *   Candidate path values.
   *
   * @return array<int, string>
   *   Sanitized path values.
   */
  private static function sanitizeSummaryStringList(array $values): array {
    $strings = [];
    foreach ($values as $value) {
      $string = self::sanitizeSummaryText($value, 300);
      if ($string !== '') {
        $strings[] = $string;
      }
    }

    return $strings;
  }

}

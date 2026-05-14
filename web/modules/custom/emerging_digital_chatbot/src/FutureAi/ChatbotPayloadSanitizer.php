<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\emerging_digital_chatbot\ChatbotConfig;

/**
 * Sanitizes visitor input before any future AI provider can receive it.
 */
final class ChatbotPayloadSanitizer {

  private const ALLOWED_LANGCODES = ['fr', 'en'];

  public function __construct(
    private readonly ChatbotConfig $chatbotConfig,
  ) {
  }

  /**
   * Keeps only the minimal data allowed for AI-assisted orientation.
   *
   * @param array<string, mixed> $payload
   *   Raw decoded request payload.
   *
   * @return array<string, mixed>
   *   Sanitized scalar payload.
   */
  public function sanitize(array $payload): array {
    $langcode = $this->sanitizeLangcode($payload['langcode'] ?? NULL);
    $message = $this->sanitizeText(
      $payload['message'] ?? '',
      $this->chatbotConfig->getFutureAiMaxInputChars(),
    );
    $blocked = $this->containsSensitiveData($message);

    return [
      'flow' => $this->sanitizeText($payload['flow'] ?? '', 80),
      'langcode' => $langcode,
      'message' => $blocked ? '' : $message,
      'need' => $this->sanitizeText($payload['need'] ?? '', 160),
      'organization_type' => $this->sanitizeText($payload['organization_type'] ?? '', 80),
      'project_type' => $this->sanitizeText($payload['project_type'] ?? '', 80),
      'url' => $this->sanitizeUrl($payload['url'] ?? NULL),
      'blocked_sensitive_input' => $blocked,
    ];
  }

  /**
   * Normalizes one scalar text value.
   */
  private function sanitizeText(mixed $value, int $maxLength): string {
    if (!is_scalar($value)) {
      return '';
    }

    $text = trim(strip_tags((string) $value));
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', (string) $text);

    return mb_substr(trim((string) $text), 0, $maxLength);
  }

  /**
   * Allows only voluntarily supplied public HTTP(S) URLs.
   */
  private function sanitizeUrl(mixed $value): string {
    $url = $this->sanitizeText($value, 300);
    if ($url === '') {
      return '';
    }

    $parts = parse_url($url);
    if (!is_array($parts)) {
      return '';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = (string) ($parts['host'] ?? '');
    if (!in_array($scheme, ['http', 'https'], TRUE) || $host === '') {
      return '';
    }

    return $url;
  }

  /**
   * Restricts the endpoint to the public FR/EN site languages.
   */
  private function sanitizeLangcode(mixed $value): string {
    $langcode = $this->sanitizeText($value, 8);
    if (in_array($langcode, self::ALLOWED_LANGCODES, TRUE)) {
      return $langcode;
    }

    return $this->chatbotConfig->getCurrentLangcode();
  }

  /**
   * Blocks obvious sensitive or personal data before provider calls.
   */
  private function containsSensitiveData(string $message): bool {
    if ($message === '') {
      return FALSE;
    }

    $patterns = [
      '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',
      '/\b(?:\+?\d[\s().-]?){8,}\b/',
      '/\b(?:password|mot de passe|secret|token|api[_\s-]?key|ssh|iban|bic)\b/i',
      '/\b(?:carte bancaire|credit card|card number|num[eé]ro national|registre national)\b/i',
      '/\b(?:medical|m[eé]dical|sant[eé]|health|diagnostic|diagnosis)\b/i',
    ];

    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $message) === 1) {
        return TRUE;
      }
    }

    return FALSE;
  }

}

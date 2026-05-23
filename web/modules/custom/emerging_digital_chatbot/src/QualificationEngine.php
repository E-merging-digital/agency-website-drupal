<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot;

/**
 * Builds the deterministic qualification tree consumed by the widget.
 */
final class QualificationEngine {

  public function __construct(
    private readonly ChatbotConfig $chatbotConfig,
  ) {
  }

  /**
   * Gets a normalized local decision tree for the current language.
   *
   * @return array<string, mixed>
   *   A render-safe payload with messages, steps and CTAs.
   */
  public function buildPayload(): array {
    $payload = $this->chatbotConfig->getWidgetPayload();
    $messages = is_array($payload['messages'] ?? NULL) ? $payload['messages'] : [];
    $flows = is_array($messages['flows'] ?? NULL) ? $messages['flows'] : [];
    $messages['flows'] = $this->normalizeFlows($flows);
    $payload['messages'] = $messages;

    return $payload;
  }

  /**
   * Normalizes configured flows while preserving their deterministic order.
   *
   * @param array<string, mixed> $flows
   *   Raw configured flow values.
   *
   * @return array<string, array<string, mixed>>
   *   Normalized flow values.
   */
  private function normalizeFlows(array $flows): array {
    $normalized = [];
    foreach ($flows as $id => $flow) {
      if (!is_string($id) || !is_array($flow)) {
        continue;
      }

      $label = $this->cleanText($flow['label'] ?? NULL);
      $response = $this->cleanText($flow['response'] ?? NULL);
      if ($label === '' || $response === '') {
        continue;
      }

      $normalized[$id] = [
        'label' => $label,
        'response' => $response,
        'ctas' => $this->normalizeCtas($flow['ctas'] ?? []),
      ];
    }

    return $normalized;
  }

  /**
   * Normalizes CTA definitions.
   *
   * @param mixed $ctas
   *   Raw configured CTAs.
   *
   * @return array<int, array{label: string, path: string}>
   *   Up to four internal CTAs.
   */
  private function normalizeCtas(mixed $ctas): array {
    if (!is_array($ctas)) {
      return [];
    }

    $normalized = [];
    foreach ($ctas as $cta) {
      if (!is_array($cta)) {
        continue;
      }

      $label = $this->cleanText($cta['label'] ?? NULL);
      $path = $this->cleanInternalPath($cta['path'] ?? NULL);
      if ($label === '' || $path === '') {
        continue;
      }

      $normalized[] = [
        'label' => $label,
        'path' => $path,
      ];

      if (count($normalized) === 4) {
        break;
      }
    }

    return $normalized;
  }

  /**
   * Cleans a configured text value.
   */
  private function cleanText(mixed $value): string {
    return is_string($value) ? trim($value) : '';
  }

  /**
   * Keeps CTAs local to avoid external navigation from the widget.
   */
  private function cleanInternalPath(mixed $value): string {
    if (!is_string($value)) {
      return '';
    }

    $path = trim($value);
    if ($path === ''
      || str_starts_with($path, '//')
      || preg_match('/^[a-z][a-z0-9+.-]*:/i', $path) === 1
    ) {
      return '';
    }

    return '/' . ltrim($path, '/');
  }

}

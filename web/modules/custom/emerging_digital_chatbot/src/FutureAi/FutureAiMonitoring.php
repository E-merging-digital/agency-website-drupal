<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Records sanitized technical counters for Future AI outcomes.
 */
final class FutureAiMonitoring {

  private const CACHE_ID = 'emerging_digital_chatbot.future_ai_monitoring';

  private const REASONS = [
    'environment_blocked' => TRUE,
    'future_ai_disabled' => TRUE,
    'key_missing' => TRUE,
    'key_unreadable' => TRUE,
    'unsupported_provider' => TRUE,
    'context_empty' => TRUE,
    'provider_timeout' => TRUE,
    'provider_error' => TRUE,
    'fallback_used' => TRUE,
    'success' => TRUE,
  ];

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Records a successful provider response.
   */
  public function recordSuccess(): void {
    $this->record('success', 'successes', FALSE);
  }

  /**
   * Records a blocked external call.
   */
  public function recordBlocked(string $reason): void {
    $this->record($reason, 'blocks', TRUE);
  }

  /**
   * Records a provider-side technical error.
   */
  public function recordProviderError(string $reason): void {
    $this->record($reason, 'provider_errors', TRUE);
  }

  /**
   * Records a local fallback that is not a provider or guard failure.
   */
  public function recordFallback(): void {
    $this->record('fallback_used', 'local_fallbacks', TRUE);
  }

  /**
   * Gets sanitized counters for the administration screen.
   *
   * @return array<string, string>
   *   Plain scalar values safe for rendering.
   */
  public function getAdminSummary(): array {
    $summary = $this->getSummary();
    $rows = [
      'period' => 'since_last_cache_clear',
      'since' => (string) $summary['since'],
      'updated' => (string) $summary['updated'],
      'events' => (string) $summary['events'],
      'successes' => (string) $summary['successes'],
      'blocks' => (string) $summary['blocks'],
      'provider_errors' => (string) $summary['provider_errors'],
      'fallbacks' => (string) $summary['fallbacks'],
    ];

    foreach (array_keys(self::REASONS) as $reason) {
      $rows['reason_' . $reason] = (string) $summary['reasons'][$reason];
    }

    return $rows;
  }

  /**
   * Records a controlled technical event.
   *
   * @param string $reason
   *   Controlled reason candidate.
   * @param string $counter
   *   Counter to increment.
   * @param bool $fallback
   *   Whether this outcome returned the fallback response.
   *
   * @phpstan-param 'successes'|'blocks'|'provider_errors'|'local_fallbacks' $counter
   */
  private function record(
    string $reason,
    string $counter,
    bool $fallback,
  ): void {
    $reason = $this->sanitizeReason($reason);
    $summary = $this->getSummary();
    $summary['updated'] = $this->time->getRequestTime();
    $summary['events']++;
    $summary[$counter]++;
    $summary['reasons'][$reason]++;
    if ($fallback) {
      $summary['fallbacks']++;
    }

    $this->cache->set(
      self::CACHE_ID,
      $summary,
      CacheBackendInterface::CACHE_PERMANENT,
    );
    $this->logger->notice('Future AI monitoring event: @reason', [
      '@reason' => $reason,
    ]);
  }

  /**
   * Keeps reasons in a short controlled vocabulary.
   */
  private function sanitizeReason(string $reason): string {
    return isset(self::REASONS[$reason]) ? $reason : 'fallback_used';
  }

  /**
   * Gets the current summary, initializing it after a cache clear.
   *
   * @return array{
   *   since: int,
   *   updated: int,
   *   events: int,
   *   successes: int,
   *   blocks: int,
   *   provider_errors: int,
   *   local_fallbacks: int,
   *   fallbacks: int,
   *   reasons: array<string, int>
   *   }
   *   Sanitized technical counters.
   */
  private function getSummary(): array {
    $cached = $this->cache->get(self::CACHE_ID);
    if ($cached && is_array($cached->data)) {
      return $this->normalizeSummary($cached->data);
    }

    return $this->normalizeSummary([]);
  }

  /**
   * Normalizes cached data defensively.
   *
   * @param array<string, mixed> $data
   *   Cached summary candidate.
   *
   * @return array{
   *   since: int,
   *   updated: int,
   *   events: int,
   *   successes: int,
   *   blocks: int,
   *   provider_errors: int,
   *   local_fallbacks: int,
   *   fallbacks: int,
   *   reasons: array<string, int>
   *   }
   *   Safe normalized summary.
   */
  private function normalizeSummary(array $data): array {
    $now = $this->time->getRequestTime();
    $reasons = [];
    $cachedReasons = is_array($data['reasons'] ?? NULL)
      ? $data['reasons']
      : [];

    foreach (array_keys(self::REASONS) as $reason) {
      $value = $cachedReasons[$reason] ?? 0;
      $reasons[$reason] = is_int($value) ? max(0, $value) : 0;
    }

    return [
      'since' => $this->sanitizeCounter($data['since'] ?? $now, $now),
      'updated' => $this->sanitizeCounter($data['updated'] ?? 0, 0),
      'events' => $this->sanitizeCounter($data['events'] ?? 0, 0),
      'successes' => $this->sanitizeCounter($data['successes'] ?? 0, 0),
      'blocks' => $this->sanitizeCounter($data['blocks'] ?? 0, 0),
      'provider_errors' => $this->sanitizeCounter(
        $data['provider_errors'] ?? 0,
        0,
      ),
      'local_fallbacks' => $this->sanitizeCounter(
        $data['local_fallbacks'] ?? 0,
        0,
      ),
      'fallbacks' => $this->sanitizeCounter($data['fallbacks'] ?? 0, 0),
      'reasons' => $reasons,
    ];
  }

  /**
   * Normalizes counters without accepting arbitrary cached values.
   */
  private function sanitizeCounter(mixed $value, int $fallback): int {
    if (!is_int($value)) {
      return $fallback;
    }

    return max(0, $value);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\emerging_digital_chatbot\FutureAi\FutureAiMonitoring;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;

/**
 * Tests sanitized Future AI monitoring counters.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class FutureAiMonitoringTest extends KernelTestBase {

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
   * Tests controlled counters without storing sensitive content.
   */
  public function testTechnicalEventsAreSanitized(): void {
    $logger = new MonitoringMemoryLogger();
    $cache = $this->container->get('cache.default');
    $time = $this->container->get('datetime.time');
    self::assertInstanceOf(CacheBackendInterface::class, $cache);
    self::assertInstanceOf(TimeInterface::class, $time);

    $monitoring = new FutureAiMonitoring(
      $cache,
      $time,
      $logger,
    );

    $monitoring->recordSuccess();
    $monitoring->recordFallback();
    $monitoring->recordBlocked('environment_blocked');
    $monitoring->recordBlocked('key_missing');
    $monitoring->recordProviderError('provider_timeout');
    $monitoring->recordProviderError(
      'provider_error sk-test-secret prompt payload visitor message',
    );

    $summary = $monitoring->getAdminSummary();

    self::assertSame('since_last_cache_clear', $summary['period']);
    self::assertSame('6', $summary['events']);
    self::assertSame('1', $summary['successes']);
    self::assertSame('2', $summary['blocks']);
    self::assertSame('2', $summary['provider_errors']);
    self::assertSame('5', $summary['fallbacks']);
    self::assertSame('1', $summary['reason_success']);
    self::assertSame('2', $summary['reason_fallback_used']);
    self::assertSame('1', $summary['reason_environment_blocked']);
    self::assertSame('1', $summary['reason_key_missing']);
    self::assertSame('1', $summary['reason_provider_timeout']);
    self::assertSame('0', $summary['reason_provider_error']);

    $exposed = json_encode([$summary, $logger->records], JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('sk-test-secret', $exposed);
    self::assertStringNotContainsString('visitor message', $exposed);
    self::assertStringNotContainsString('prompt', $exposed);
    self::assertStringNotContainsString('payload', $exposed);
  }

}

/**
 * Captures monitoring logs for assertions.
 */
final class MonitoringMemoryLogger extends AbstractLogger {

  /**
   * Captured records.
   *
   * @var array<int, array{level: mixed, message: string,
   *   context: array<string, mixed>}>
   */
  public array $records = [];

  /**
   * {@inheritdoc}
   */
  public function log(
    $level,
    string|\Stringable $message,
    array $context = [],
  ): void {
    $this->records[] = [
      'level' => $level,
      'message' => (string) $message,
      'context' => $context,
    ];
  }

}

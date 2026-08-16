<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies Drupal AI observability stays privacy-first and backend agnostic.
 *
 * @group agency_project_tests
 * @group agency_ai
 */
#[Group('agency_ai')]
final class AiObservabilityConfigTest extends TestCase {

  /**
   * Verifies the upstream observability module is enabled.
   */
  public function testObservabilityModuleIsEnabled(): void {
    $extensions = $this->loadConfiguration('core.extension.yml');
    $modules = $extensions['module'] ?? [];

    self::assertArrayHasKey('ai_observability', $modules);
  }

  /**
   * Verifies the baseline logs metadata without full AI payloads.
   */
  public function testObservabilitySettingsArePrivacyFirst(): void {
    $settings = $this->loadConfiguration('ai_observability.settings.yml');

    self::assertTrue($settings['logging_enabled'] ?? FALSE);
    self::assertSame([
      'Drupal\\ai\\Event\\PreGenerateResponseEvent',
      'Drupal\\ai\\Event\\PostGenerateResponseEvent',
      'Drupal\\ai\\Event\\PostStreamingResponseEvent',
    ], $settings['log_event_types'] ?? []);
    self::assertFalse($settings['log_input'] ?? TRUE);
    self::assertFalse($settings['log_output'] ?? TRUE);
    self::assertSame([], $settings['log_tags'] ?? NULL);
    self::assertTrue($settings['fallback_log_message_mode'] ?? FALSE);
    self::assertFalse($settings['otel_enabled'] ?? TRUE);
    self::assertTrue($settings['otel_spans'] ?? FALSE);
    self::assertFalse($settings['otel_spans_store_input'] ?? TRUE);
    self::assertFalse($settings['otel_spans_store_output'] ?? TRUE);
    self::assertTrue($settings['otel_metrics'] ?? FALSE);
  }

  /**
   * Verifies no provider credential or concrete backend is versioned here.
   */
  public function testObservabilitySettingsContainNoProviderSecrets(): void {
    $settings = $this->loadConfiguration('ai_observability.settings.yml');
    $serialized = Yaml::dump($settings);

    self::assertStringNotContainsStringIgnoringCase('api_key', $serialized);
    self::assertStringNotContainsStringIgnoringCase('authorization', $serialized);
    self::assertStringNotContainsStringIgnoringCase('bearer', $serialized);
    self::assertStringNotContainsStringIgnoringCase('endpoint', $serialized);
  }

  /**
   * Loads one configuration file from the repository sync directory.
   */
  private function loadConfiguration(string $filename): array {
    $path = dirname(DRUPAL_ROOT) . '/config/sync/' . $filename;
    self::assertFileExists($path);

    $configuration = Yaml::parseFile($path);
    self::assertIsArray($configuration);

    return $configuration;
  }

}

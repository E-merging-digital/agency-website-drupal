<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the versioned deterministic Drupal AI guardrails baseline.
 *
 * @group agency_project_tests
 * @group agency_ai
 */
#[Group('agency_ai')]
final class AiGuardrailsConfigTest extends TestCase {

  /**
   * Verifies the deterministic input-length guardrail configuration.
   */
  public function testInputLengthGuardrailIsDeterministic(): void {
    $guardrail = $this->loadConfiguration(
      'ai.ai_guardrail.agency_editorial_input_length.yml',
    );

    self::assertSame('agency_editorial_input_length', $guardrail['id'] ?? NULL);
    self::assertSame('input_length_limit', $guardrail['guardrail'] ?? NULL);

    $settings = $guardrail['guardrail_settings'] ?? [];
    self::assertIsArray($settings);
    self::assertSame(20000, $settings['max_length'] ?? NULL);
    self::assertFalse($settings['use_tokens'] ?? TRUE);
    self::assertFalse($settings['check_all_messages'] ?? TRUE);
    self::assertNotSame('', $settings['violation_message'] ?? '');
  }

  /**
   * Verifies the guardrail is pre-generate only and globally applied.
   */
  public function testEditorialBaselineIsGlobalAndPreGenerateOnly(): void {
    $set = $this->loadConfiguration(
      'ai.ai_guardrail_set.agency_editorial_baseline.yml',
    );
    $settings = $this->loadConfiguration('ai.settings.yml');

    self::assertSame('agency_editorial_baseline', $set['id'] ?? NULL);
    self::assertSame(
      ['agency_editorial_input_length'],
      $set['pre_generate_guardrails']['plugin_id'] ?? NULL,
    );
    self::assertSame([], $set['post_generate_guardrails']['plugin_id'] ?? NULL);
    self::assertSame(1.0, $set['stop_threshold'] ?? NULL);
    self::assertSame(
      ['agency_editorial_baseline'],
      $settings['global_guardrails'] ?? NULL,
    );
  }

  /**
   * Verifies editorial users cannot administer the safety baseline.
   */
  public function testContentEditorCannotAdministerGuardrails(): void {
    $role = $this->loadConfiguration('user.role.content_editor.yml');
    $permissions = $role['permissions'] ?? [];

    self::assertIsArray($permissions);
    self::assertNotContains('administer guardrails', $permissions);
    self::assertNotContains('administer guardrail sets', $permissions);
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

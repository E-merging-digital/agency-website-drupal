<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies Article AI Automators remain manual and human-governed.
 *
 * @group agency_project_tests
 * @group agency_ai
 */
#[Group('agency_ai')]
final class AiAutomatorsArticleConfigTest extends TestCase {

  /**
   * Verifies the official Automators modules are enabled.
   */
  public function testRequiredModulesAreEnabled(): void {
    $extensions = $this->loadConfiguration('core.extension.yml');
    $modules = $extensions['module'] ?? [];

    self::assertArrayHasKey('ai_automators', $modules);
    self::assertArrayHasKey('field_widget_actions', $modules);
  }

  /**
   * Verifies the short-description Automator is manual and provider agnostic.
   */
  public function testShortDescriptionUsesManualFieldWidgetAutomator(): void {
    $automator = $this->loadConfiguration(
      'ai_automators.ai_automator.node.article.field_short_description.default.yml',
    );

    self::assertSame('llm_simple_text_long', $automator['rule'] ?? NULL);
    self::assertSame('base', $automator['input_mode'] ?? NULL);
    self::assertSame('body', $automator['base_field'] ?? NULL);
    self::assertSame('field_widget_actions', $automator['worker_type'] ?? NULL);
    self::assertSame(
      'field_widget_actions',
      $automator['plugin_config']['automator_worker_type'] ?? NULL,
    );
    self::assertSame(
      'default_json',
      $automator['plugin_config']['automator_ai_provider'] ?? NULL,
    );
    self::assertArrayNotHasKey(
      'automator_ai_model',
      $automator['plugin_config'] ?? [],
    );
    self::assertNotEmpty($automator['prompt'] ?? '');
  }

  /**
   * Verifies the feature-image alt Automator is manual and provider agnostic.
   */
  public function testFeatureImageUsesManualFieldWidgetAutomator(): void {
    $automator = $this->loadConfiguration(
      'ai_automators.ai_automator.node.article.field_feature_image.default.yml',
    );

    self::assertSame('llm_image_alt_text', $automator['rule'] ?? NULL);
    self::assertSame('base', $automator['input_mode'] ?? NULL);
    self::assertSame('field_widget_actions', $automator['worker_type'] ?? NULL);
    self::assertSame(
      'field_widget_actions',
      $automator['plugin_config']['automator_worker_type'] ?? NULL,
    );
    self::assertSame(
      'default_json',
      $automator['plugin_config']['automator_ai_provider'] ?? NULL,
    );
    self::assertArrayNotHasKey(
      'automator_ai_model',
      $automator['plugin_config'] ?? [],
    );
    self::assertNotEmpty($automator['prompt'] ?? '');
  }

  /**
   * Verifies the Article form exposes only explicit manual generation actions.
   */
  public function testArticleFormActionsAreExplicitAndManual(): void {
    $display = $this->loadConfiguration(
      'core.entity_form_display.node.article.default.yml',
    );

    $short_field = $display['content']['field_short_description'] ?? [];
    $image_field = $display['content']['field_feature_image'] ?? [];
    $short_actions = $short_field['third_party_settings']['field_widget_actions']
      ?? [];
    $image_actions = $image_field['third_party_settings']['field_widget_actions']
      ?? [];

    self::assertCount(1, $short_actions);
    self::assertCount(1, $image_actions);

    $short_action = reset($short_actions);
    $image_action = reset($image_actions);
    self::assertIsArray($short_action);
    self::assertIsArray($image_action);

    self::assertSame('automator_text', $short_action['plugin_id'] ?? NULL);
    self::assertFalse($short_action['automatic'] ?? TRUE);
    self::assertSame(
      'node.article.field_short_description.default',
      $short_action['settings']['automator_id'] ?? NULL,
    );

    self::assertSame('automator_alt_text', $image_action['plugin_id'] ?? NULL);
    self::assertFalse($image_action['automatic'] ?? TRUE);
    self::assertSame(
      'node.article.field_feature_image.default',
      $image_action['settings']['automator_id'] ?? NULL,
    );
  }

  /**
   * Verifies the internal Automator status field stays hidden.
   */
  public function testAutomatorStatusFieldIsHiddenFromArticleDisplays(): void {
    foreach (['default', 'teaser'] as $view_mode) {
      $display = $this->loadConfiguration(
        sprintf('core.entity_view_display.node.article.%s.yml', $view_mode),
      );
      self::assertTrue($display['hidden']['ai_automator_status'] ?? FALSE);
    }
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

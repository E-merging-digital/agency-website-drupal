<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the narrow, human-governed AI CKEditor configuration.
 *
 * @group agency_project_tests
 * @group agency_ai
 */
#[Group('agency_ai')]
final class AiCkeditorConfigTest extends TestCase {

  /**
   * Verifies AI CKEditor is enabled and scoped to the Basic HTML editor.
   */
  public function testAiCkeditorIsEnabledOnBasicHtmlOnly(): void {
    $extensions = $this->loadConfiguration('core.extension.yml');
    self::assertArrayHasKey('ai_ckeditor', $extensions['module'] ?? []);

    $editor = $this->loadConfiguration('editor.editor.basic_html.yml');
    self::assertContains('ai_ckeditor', $editor['dependencies']['module'] ?? []);
    self::assertContains('aickeditor', $editor['settings']['toolbar']['items'] ?? []);

    $source_index = array_search(
      'sourceEditing',
      $editor['settings']['toolbar']['items'] ?? [],
      TRUE,
    );
    $ai_index = array_search(
      'aickeditor',
      $editor['settings']['toolbar']['items'] ?? [],
      TRUE,
    );
    self::assertIsInt($source_index);
    self::assertIsInt($ai_index);
    self::assertSame($source_index - 1, $ai_index);
  }

  /**
   * Verifies the deliberately narrow tool set and provider abstraction.
   */
  public function testEnabledToolsAreExplicitAndProviderAgnostic(): void {
    $editor = $this->loadConfiguration('editor.editor.basic_html.yml');
    $plugins = $editor['settings']['plugins']['ai_ckeditor_ai']['plugins'] ?? [];
    self::assertIsArray($plugins);

    $enabled = [];
    foreach ($plugins as $plugin_id => $plugin) {
      if (($plugin['enabled'] ?? FALSE) === TRUE) {
        $enabled[] = $plugin_id;
        if (array_key_exists('provider', $plugin)) {
          self::assertSame('', $plugin['provider']);
        }
      }
    }

    self::assertSame([
      'ai_ckeditor_completion',
      'ai_ckeditor_modify_prompt',
      'ai_ckeditor_spellfix',
      'ai_ckeditor_summarize',
    ], $enabled);
    self::assertFalse($plugins['ai_ckeditor_tone']['enabled'] ?? TRUE);
    self::assertFalse($plugins['ai_ckeditor_translate']['enabled'] ?? TRUE);
  }

  /**
   * Verifies the AI tool permission is limited to the editorial role.
   */
  public function testAiCkeditorPermissionIsEditorialOnly(): void {
    $content_editor = $this->loadConfiguration('user.role.content_editor.yml');
    $authenticated = $this->loadConfiguration('user.role.authenticated.yml');

    self::assertContains(
      'use ai ckeditor',
      $content_editor['permissions'] ?? [],
    );
    self::assertContains(
      'ai_ckeditor',
      $content_editor['dependencies']['module'] ?? [],
    );
    self::assertNotContains(
      'use ai ckeditor',
      $authenticated['permissions'] ?? [],
    );
  }

  /**
   * Verifies Article remains a normal human-edited rich-text field.
   */
  public function testArticleBodyRemainsHumanEditedAndTranslatable(): void {
    $field = $this->loadConfiguration('field.field.node.article.body.yml');

    self::assertTrue($field['translatable'] ?? FALSE);
    self::assertSame('text_with_summary', $field['field_type'] ?? NULL);
    self::assertSame([], $field['settings']['allowed_formats'] ?? []);
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

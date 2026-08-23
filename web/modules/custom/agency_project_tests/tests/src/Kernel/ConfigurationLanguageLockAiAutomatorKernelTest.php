<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\ai_automators\Entity\AiAutomator;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves Configuration Language Lock on native Drupal AI Automator config.
 *
 * @group agency_project_tests
 * @group agency_ai
 * @group configuration_language_governance
 */
#[Group('agency_ai')]
#[Group('configuration_language_governance')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockAiAutomatorKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'language',
    'file',
    'key',
    'token',
    'ai',
    'ai_automators',
    'config_language_lock',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['system', 'config_language_lock']);

    foreach (['fr', 'en'] as $langcode) {
      if (ConfigurableLanguage::load($langcode) === NULL) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    NodeType::create([
      'type' => 'ai_lock_probe',
      'name' => 'AI lock probe',
    ])->save();

    $storage = FieldStorageConfig::create([
      'field_name' => 'field_ai_lock_probe',
      'entity_type' => 'node',
      'type' => 'string_long',
    ]);
    $storage->save();

    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'ai_lock_probe',
      'label' => 'AI lock probe target',
    ])->save();

    self::assertTrue(
      $this->container->get('module_handler')->moduleExists('ai_automators'),
    );
    self::assertTrue(
      $this->container->get('module_handler')->moduleExists('config_language_lock'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * A providerless Drupal AI Automator created as FR is persisted as EN.
   */
  public function testAiAutomatorCreationUsesLockedEnglishLanguage(): void {
    $this->enableEnglishConfigurationLock();

    $automator = AiAutomator::create($this->automatorValues(
      'node.ai_lock_probe.field_ai_lock_probe.create',
      'Agency AI lock creation probe',
      'Create a concise value without inventing facts.',
      'fr',
    ));
    $automator->save();

    $stored = $this->config(
      'ai_automators.ai_automator.node.ai_lock_probe.field_ai_lock_probe.create',
    );
    self::assertSame('en', $stored->get('langcode'));
    self::assertSame('llm_simple_text_long', $stored->get('rule'));
    self::assertSame('base', $stored->get('input_mode'));
    self::assertSame('field_widget_actions', $stored->get('worker_type'));
    self::assertSame(
      'default_json',
      $stored->get('plugin_config.automator_ai_provider'),
    );
    self::assertNull($stored->get('plugin_config.automator_ai_model'));
    self::assertSame(
      'Create a concise value without inventing facts.',
      $stored->get('prompt'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * Updating a pre-existing FR Automator normalizes it to canonical EN.
   */
  public function testAiAutomatorUpdateUsesLockedEnglishLanguage(): void {
    $id = 'node.ai_lock_probe.field_ai_lock_probe.update';
    AiAutomator::create($this->automatorValues(
      $id,
      'Agency AI lock update probe',
      'Prompt before governed update.',
      'fr',
    ))->save();

    $config_name = 'ai_automators.ai_automator.' . $id;
    self::assertSame('fr', $this->config($config_name)->get('langcode'));

    $this->enableEnglishConfigurationLock();

    // Enabling the lock alone must not rewrite existing Drupal AI config.
    self::assertSame('fr', $this->config($config_name)->get('langcode'));

    $automator = AiAutomator::load($id);
    self::assertInstanceOf(AiAutomator::class, $automator);
    $automator->set('label', 'Agency AI lock update probe — updated');
    $automator->set('prompt', 'Prompt updated through the Drupal entity API.');
    $automator->save();

    $stored = $this->config($config_name);
    self::assertSame('en', $stored->get('langcode'));
    self::assertSame(
      'Agency AI lock update probe — updated',
      $stored->get('label'),
    );
    self::assertSame(
      'Prompt updated through the Drupal entity API.',
      $stored->get('prompt'),
    );
    self::assertSame('llm_simple_text_long', $stored->get('rule'));
    self::assertSame('field_widget_actions', $stored->get('worker_type'));
    self::assertSame(
      'default_json',
      $stored->get('plugin_config.automator_ai_provider'),
    );
    self::assertNull($stored->get('plugin_config.automator_ai_model'));
    self::assertSame(
      'fr',
      $this->config('system.site')->get('default_langcode'),
    );
  }

  /**
   * Returns provider-agnostic Agency Automator values.
   *
   * @return array<string, mixed>
   *   Configuration entity values.
   */
  private function automatorValues(
    string $id,
    string $label,
    string $prompt,
    string $langcode,
  ): array {
    return [
      'id' => $id,
      'label' => $label,
      'rule' => 'llm_simple_text_long',
      'input_mode' => 'base',
      'weight' => 100,
      'worker_type' => 'field_widget_actions',
      'entity_type' => 'node',
      'bundle' => 'ai_lock_probe',
      'field_name' => 'field_ai_lock_probe',
      'edit_mode' => FALSE,
      'base_field' => 'title',
      'prompt' => $prompt,
      'token' => '',
      'guardrail_set_id' => NULL,
      'plugin_config' => [
        'automator_enabled' => 1,
        'automator_rule' => 'llm_simple_text_long',
        'automator_mode' => 'base',
        'automator_base_field' => 'title',
        'automator_prompt' => $prompt,
        'automator_token' => '',
        'automator_edit_mode' => 0,
        'automator_label' => $label,
        'automator_weight' => '100',
        'automator_worker_type' => 'field_widget_actions',
        'automator_queue_allow_requeue' => 0,
        'automator_guardrail_set_id' => '',
        'automator_ai_provider' => 'default_json',
        'automator_code_block_type' => 'html',
        'automator_use_text_format' => '',
      ],
      'langcode' => $langcode,
    ];
  }

  /**
   * Enables the contributed module's EN lock without Agency rewrite logic.
   */
  private function enableEnglishConfigurationLock(): void {
    $this->config('config_language_lock.settings')
      ->set('locked_langcode', 'en')
      ->set('follow_site_default', FALSE)
      ->save();

    self::assertSame(
      'en',
      $this->config('config_language_lock.settings')->get('locked_langcode'),
    );
    self::assertFalse(
      $this->config('config_language_lock.settings')->get('follow_site_default'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

}

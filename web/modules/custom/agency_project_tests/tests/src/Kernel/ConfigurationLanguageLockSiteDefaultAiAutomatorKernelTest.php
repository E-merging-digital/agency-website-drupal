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
 * Characterizes the site-default configuration-language policy for native Drupal AI Automator config.
 *
 * @group agency_project_tests
 * @group agency_ai
 * @group configuration_language_governance
 */
#[Group('agency_ai')]
#[Group('configuration_language_governance')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockSiteDefaultAiAutomatorKernelTest extends KernelTestBase {

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
    $this->installEntitySchema('node');

    foreach (['fr', 'en'] as $langcode) {
      if (ConfigurableLanguage::load($langcode) === NULL) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    NodeType::create([
      'type' => 'candidate_ai_lock_probe',
      'name' => 'Candidate AI lock probe',
    ])->save();

    $storage = FieldStorageConfig::create([
      'field_name' => 'field_candidate_ai_lock_probe',
      'entity_type' => 'node',
      'type' => 'string_long',
    ]);
    $storage->save();

    FieldConfig::create([
      'field_storage' => $storage,
      'bundle' => 'candidate_ai_lock_probe',
      'label' => 'Candidate AI lock probe target',
    ])->save();
  }

  /**
   * Providerless Automator create normalizes explicit EN to FR.
   */
  public function testAiAutomatorCreationUsesFrenchSiteDefault(): void {
    $this->enableSiteDefaultConfigurationLock();

    $id = 'node.candidate_ai_lock_probe.field_candidate_ai_lock_probe.create';
    AiAutomator::create($this->automatorValues(
      $id,
      'Candidate AI creation probe',
      'Create a concise value without inventing facts.',
      'en',
    ))->save();

    $stored = $this->config('ai_automators.ai_automator.' . $id);
    self::assertSame('fr', $stored->get('langcode'));
    self::assertSame('llm_simple_text_long', $stored->get('rule'));
    self::assertSame('field_widget_actions', $stored->get('worker_type'));
  }

  /**
   * Automator update normalizes existing EN only on the later save.
   */
  public function testAiAutomatorUpdateUsesFrenchSiteDefault(): void {
    $id = 'node.candidate_ai_lock_probe.field_candidate_ai_lock_probe.update';
    AiAutomator::create($this->automatorValues(
      $id,
      'Candidate AI update probe',
      'Prompt before governed update.',
      'en',
    ))->save();

    $config_name = 'ai_automators.ai_automator.' . $id;
    self::assertSame('en', $this->config($config_name)->get('langcode'));

    $this->enableSiteDefaultConfigurationLock();
    self::assertSame('en', $this->config($config_name)->get('langcode'));

    $automator = AiAutomator::load($id);
    self::assertInstanceOf(AiAutomator::class, $automator);
    $automator->set('label', 'Candidate AI update probe — updated');
    $automator->set('prompt', 'Prompt updated through the Drupal entity API.');
    $automator->save();

    $stored = $this->config($config_name);
    self::assertSame('fr', $stored->get('langcode'));
    self::assertSame(
      'Candidate AI update probe — updated',
      $stored->get('label'),
    );
  }

  /**
   * Returns provider-agnostic the site-default configuration-language policy Automator values.
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
      'bundle' => 'candidate_ai_lock_probe',
      'field_name' => 'field_candidate_ai_lock_probe',
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
   * Enables the site-default configuration-language policy only inside this Kernel test.
   */
  private function enableSiteDefaultConfigurationLock(): void {
    $this->config('config_language_lock.settings')
      ->set('locked_langcode', 'fr')
      ->set('follow_site_default', TRUE)
      ->save();
  }

}

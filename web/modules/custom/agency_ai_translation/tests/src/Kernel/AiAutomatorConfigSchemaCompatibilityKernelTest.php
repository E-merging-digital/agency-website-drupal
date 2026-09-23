<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_ai_translation\Kernel;

use Drupal\config_language_lock\ConfigLanguageLockConfigManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the bounded AI Automator config-schema compatibility adapter.
 *
 * @group agency_ai_translation
 * @group configuration_language_1316
 */
#[Group('configuration_language_1316')]
#[RunTestsInSeparateProcesses]
final class AiAutomatorConfigSchemaCompatibilityKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'language',
    'file',
    'key',
    'token',
    'ai',
    'config_language_lock',
    'ai_automators',
    'agency_ai_translation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig([
      'system',
      'language',
      'config_language_lock',
    ]);

    foreach (['fr', 'en'] as $langcode) {
      if (ConfigurableLanguage::load($langcode) === NULL) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();
  }

  /**
   * Proves exact schema containment and manager translation preservation.
   */
  public function testAutomatorLabelCompatibility(): void {
    $name = 'ai_automators.ai_automator.node.article.field_schema_probe.default';
    $storage = $this->container->get('config.storage');

    $storage->write($name, [
      'uuid' => '67c85fb6-6138-4cd0-83fb-83fa526d79ef',
      'langcode' => 'en',
      'status' => TRUE,
      'dependencies' => [],
      'id' => 'node.article.field_schema_probe.default',
      'label' => 'Automator EN',
      'rule' => 'llm_simple_text_long',
      'input_mode' => 'base',
      'weight' => 100,
      'worker_type' => 'field_widget_actions',
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_name' => 'field_schema_probe',
      'edit_mode' => FALSE,
      'base_field' => 'body',
      'prompt' => 'Prompt EN',
      'token' => '',
      'guardrail_set_id' => NULL,
      'plugin_config' => [
        'automator_label' => 'Automator EN',
        'automator_prompt' => 'Prompt EN',
        'automator_ai_provider' => 'provider_probe',
      ],
    ]);

    $overrideFactory = $this->container->get('language.config_factory_override');
    $overrideFactory->getOverride('fr', $name)
      ->set('label', 'Automator FR')
      ->set('plugin_config.automator_label', 'Automator FR')
      ->save();

    $configFactory = $this->container->get('config.factory');
    $configFactory->reset($name);

    $labelSchema = $this->schemaDescriptor(
      $name,
      'plugin_config.automator_label',
    );
    self::assertSame(
      'agency_ai_translation.ai_automator_plugin_config.automator_label',
      $labelSchema['resolved_type'],
    );
    self::assertSame('label', $labelSchema['base_type']);
    self::assertTrue($labelSchema['translatable']);

    foreach ([
      'plugin_config.automator_prompt',
      'plugin_config.automator_ai_provider',
    ] as $path) {
      $descriptor = $this->schemaDescriptor($name, $path);
      self::assertSame('ignore', $descriptor['base_type']);
      self::assertNotTrue($descriptor['translatable']);
    }

    $canonicalBefore = $configFactory->getEditable($name);
    self::assertSame('en', $canonicalBefore->get('langcode'));
    self::assertSame('Automator EN', $canonicalBefore->get('label'));
    self::assertSame(
      'Automator EN',
      $canonicalBefore->get('plugin_config.automator_label'),
    );

    $frenchBefore = $overrideFactory->getOverride('fr', $name);
    self::assertFalse($frenchBefore->isNew());
    self::assertSame('Automator FR', $frenchBefore->get('label'));
    self::assertSame(
      'Automator FR',
      $frenchBefore->get('plugin_config.automator_label'),
    );

    $this->config('config_language_lock.settings')
      ->set('locked_langcode', 'fr')
      ->set('follow_site_default', TRUE)
      ->save();

    $manager = $this->container->get(ConfigLanguageLockConfigManager::class);
    self::assertInstanceOf(ConfigLanguageLockConfigManager::class, $manager);
    $manager->updateConfigForLockedLanguageSwitch([$name]);

    $configFactory->reset($name);
    $canonical = $configFactory->getEditable($name);
    self::assertSame('fr', $canonical->get('langcode'));
    self::assertSame('Automator FR', $canonical->get('label'));
    self::assertSame(
      'Automator FR',
      $canonical->get('plugin_config.automator_label'),
    );

    $french = $overrideFactory->getOverride('fr', $name);
    self::assertTrue($french->isNew());
    self::assertSame([], $french->get());

    $english = $overrideFactory->getOverride('en', $name);
    self::assertFalse($english->isNew());
    self::assertSame('Automator EN', $english->get('label'));
    self::assertSame(
      'Automator EN',
      $english->get('plugin_config.automator_label'),
    );

    $this->assertEffectiveValues(
      'fr',
      $name,
      'Automator FR',
      'Automator FR',
    );
    $this->assertEffectiveValues(
      'en',
      $name,
      'Automator EN',
      'Automator EN',
    );
  }

  /**
   * Returns the resolved dynamic schema type and its effective metadata.
   *
   * @return array{resolved_type: string, base_type: string, translatable: ?bool}
   *   Schema descriptor.
   */
  private function schemaDescriptor(string $name, string $path): array {
    $typedManager = $this->container->get('config.typed');
    $typedData = $typedManager->get($name);

    foreach (explode('.', $path) as $segment) {
      $typedData = $typedData->get($segment);
      self::assertNotNull($typedData);
    }

    $definition = $typedData->getDataDefinition();
    $resolvedType = $definition->getDataType();
    self::assertIsString($resolvedType);

    $typeDefinition = $typedManager->getDefinition($resolvedType, FALSE);
    self::assertIsArray($typeDefinition);
    $baseType = $typeDefinition['type'] ?? NULL;
    self::assertIsString($baseType);

    $translatable = $this->resolveTranslatability($resolvedType);

    return [
      'resolved_type' => $resolvedType,
      'base_type' => $baseType,
      'translatable' => $translatable,
    ];
  }

  /**
   * Resolves translatability through the typed-config inheritance chain.
   */
  private function resolveTranslatability(string $type): ?bool {
    $typedManager = $this->container->get('config.typed');
    $seen = [];

    while ($type !== '' && !isset($seen[$type])) {
      $seen[$type] = TRUE;
      $definition = $typedManager->getDefinition($type, FALSE);
      if (!is_array($definition)) {
        return NULL;
      }

      if (
        array_key_exists('translatable', $definition)
        && is_bool($definition['translatable'])
      ) {
        return $definition['translatable'];
      }

      $parent = $definition['type'] ?? NULL;
      if (!is_string($parent) || $parent === '' || $parent === $type) {
        return NULL;
      }

      $type = $parent;
    }

    return NULL;
  }

  /**
   * Asserts effective values under one negotiated configuration language.
   */
  private function assertEffectiveValues(
    string $langcode,
    string $name,
    string $label,
    string $automatorLabel,
  ): void {
    $language = ConfigurableLanguage::load($langcode);
    self::assertInstanceOf(ConfigurableLanguage::class, $language);

    $overrideFactory = $this->container->get('language.config_factory_override');
    $overrideFactory->setLanguage($language);
    $this->container->get('config.factory')->reset($name);

    $effective = $this->container->get('config.factory')->get($name);
    self::assertSame($label, $effective->get('label'));
    self::assertSame(
      $automatorLabel,
      $effective->get('plugin_config.automator_label'),
    );
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\config_language_lock\ConfigLanguageLockConfigManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\system\Entity\Menu;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Characterizes the site-default configuration-language policy translation and semantic-language preservation.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
#[Group('configuration_language_governance')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockSiteDefaultTranslationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'language',
    'config_language_lock',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'language', 'config_language_lock']);

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
   * The module's real switch logic promotes FR and preserves EN as override.
   */
  public function testFrenchPromotionPreservesEnglishTranslation(): void {
    $id = 'candidate_translation_probe';
    $name = $this->createEnglishMenuWithFrenchOverride($id);

    $config_factory = $this->container->get('config.factory');
    $override_factory = $this->container->get('language.config_factory_override');

    $source_before = $config_factory->getEditable($name);
    self::assertSame('en', $source_before->get('langcode'));
    self::assertSame('Main navigation EN', $source_before->get('label'));

    $french_before = $override_factory->getOverride('fr', $name);
    self::assertFalse($french_before->isNew());
    self::assertSame('Navigation principale FR', $french_before->get('label'));

    $this->enableSiteDefaultConfigurationLock();

    // Lock settings alone do not rewrite the existing base or overrides.
    $config_factory->reset($name);
    self::assertSame('en', $config_factory->getEditable($name)->get('langcode'));
    self::assertSame(
      'Navigation principale FR',
      $override_factory->getOverride('fr', $name)->get('label'),
    );

    $manager = $this->container->get(ConfigLanguageLockConfigManager::class);
    self::assertInstanceOf(ConfigLanguageLockConfigManager::class, $manager);
    $stats = $manager->updateConfigForLockedLanguageSwitch([$name]);

    self::assertSame(1, $stats['config_items_changed']);
    self::assertSame(1, $stats['translations_updated']);
    self::assertSame(1, $stats['translations_removed']);

    $config_factory->reset($name);
    $canonical = $config_factory->getEditable($name);
    self::assertSame('fr', $canonical->get('langcode'));
    self::assertSame('Navigation principale FR', $canonical->get('label'));
    self::assertSame('Liens de navigation FR', $canonical->get('description'));

    // The previous EN source becomes the EN override.
    $english = $override_factory->getOverride('en', $name);
    self::assertFalse($english->isNew());
    self::assertSame('Main navigation EN', $english->get('label'));
    self::assertSame('English navigation links', $english->get('description'));

    // FR is now the base language: there must be no duplicate FR override.
    $french = $override_factory->getOverride('fr', $name);
    self::assertTrue($french->isNew());
    self::assertSame([], $french->get());

    $this->assertEffectiveValues(
      'fr',
      $name,
      'Navigation principale FR',
      'Liens de navigation FR',
    );
    $this->assertEffectiveValues(
      'en',
      $name,
      'Main navigation EN',
      'English navigation links',
    );
  }

  /**
   * Und/zxx keep their semantic IDs and locked status after normalization.
   */
  public function testUndAndZxxSemanticIdentitySurvivesCandidateBatch(): void {
    $storage = $this->container->get('config.storage');

    foreach (['und', 'zxx'] as $id) {
      $before = $storage->read('language.entity.' . $id);
      self::assertIsArray($before);
      self::assertSame($id, $before['id'] ?? NULL);
      self::assertTrue((bool) ($before['locked'] ?? FALSE));
    }

    $this->enableSiteDefaultConfigurationLock();

    $manager = $this->container->get(ConfigLanguageLockConfigManager::class);
    $manager->updateConfigForLockedLanguageSwitch([
      'language.entity.und',
      'language.entity.zxx',
    ]);

    foreach (['und', 'zxx'] as $id) {
      $after = $storage->read('language.entity.' . $id);
      self::assertIsArray($after);
      self::assertSame($id, $after['id'] ?? NULL);
      self::assertTrue((bool) ($after['locked'] ?? FALSE));
      self::assertSame('fr', $after['langcode'] ?? NULL);
    }
  }

  /**
   * Creates an EN base config entity with a distinct FR override.
   */
  private function createEnglishMenuWithFrenchOverride(string $id): string {
    Menu::create([
      'id' => $id,
      'label' => 'Main navigation EN',
      'description' => 'English navigation links',
      'langcode' => 'en',
      'locked' => TRUE,
    ])->save();

    $name = 'system.menu.' . $id;
    $this->container
      ->get('language.config_factory_override')
      ->getOverride('fr', $name)
      ->set('label', 'Navigation principale FR')
      ->set('description', 'Liens de navigation FR')
      ->save();

    return $name;
  }

  /**
   * Asserts effective translated values for one negotiated language.
   */
  private function assertEffectiveValues(
    string $langcode,
    string $name,
    string $label,
    string $description,
  ): void {
    $language = ConfigurableLanguage::load($langcode);
    self::assertInstanceOf(ConfigurableLanguage::class, $language);

    $override_factory = $this->container->get('language.config_factory_override');
    $override_factory->setLanguage($language);
    $this->container->get('config.factory')->reset($name);

    $effective = $this->container->get('config.factory')->get($name);
    self::assertSame($label, $effective->get('label'));
    self::assertSame($description, $effective->get('description'));
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

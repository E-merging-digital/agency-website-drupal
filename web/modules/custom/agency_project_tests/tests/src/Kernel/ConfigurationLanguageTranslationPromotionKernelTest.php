<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\system\Entity\Menu;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves safe source-language promotion with configuration translations.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
#[Group('configuration_language_governance')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageTranslationPromotionKernelTest extends KernelTestBase {

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

    $this->installConfig(['system', 'config_language_lock']);

    foreach (['fr', 'en'] as $langcode) {
      if (ConfigurableLanguage::load($langcode) === NULL) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * Proves that the EN lock alone cannot promote translated source values.
   */
  public function testLockOnlyDoesNotPromoteEnglishTranslationValues(): void {
    $name = $this->createFrenchMenuWithEnglishOverride('translation_naive_probe');
    $this->enableEnglishConfigurationLock();

    $menu = Menu::load('translation_naive_probe');
    self::assertInstanceOf(Menu::class, $menu);

    // Preserve the original source values explicitly. The lock only owns the
    // configuration langcode; it must not be mistaken for a translation
    // migration mechanism.
    $menu
      ->set('label', 'Navigation principale FR')
      ->set('description', 'Liens de navigation FR')
      ->save();

    $this->container->get('config.factory')->reset($name);
    $raw = $this->container->get('config.factory')->getEditable($name);
    self::assertSame('en', $raw->get('langcode'));
    self::assertSame('Navigation principale FR', $raw->get('label'));
    self::assertSame('Liens de navigation FR', $raw->get('description'));
    self::assertSame('translation_naive_probe', $raw->get('id'));
    self::assertTrue((bool) $raw->get('locked'));

    $english = $this->container
      ->get('language.config_factory_override')
      ->getOverride('en', $name);
    self::assertFalse($english->isNew());
    self::assertSame('Main navigation EN', $english->get('label'));
    self::assertSame('English navigation links', $english->get('description'));

    // The metadata now says EN while the raw source strings remain FR. This is
    // deliberately the unsafe state that a migration must avoid.
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * Promotes EN values and preserves former FR source values as an override.
   */
  public function testEnglishPromotionPreservesFrenchTranslation(): void {
    $id = 'translation_promotion_probe';
    $name = $this->createFrenchMenuWithEnglishOverride($id);

    $config_factory = $this->container->get('config.factory');
    $override_factory = $this->container->get('language.config_factory_override');

    $source = $config_factory->getEditable($name);
    $french_values = [
      'label' => $source->get('label'),
      'description' => $source->get('description'),
    ];
    self::assertSame('fr', $source->get('langcode'));
    self::assertSame('Navigation principale FR', $french_values['label']);
    self::assertSame('Liens de navigation FR', $french_values['description']);

    $english_override = $override_factory->getOverride('en', $name);
    self::assertFalse($english_override->isNew());
    $english_values = [
      'label' => $english_override->get('label'),
      'description' => $english_override->get('description'),
    ];
    self::assertSame('Main navigation EN', $english_values['label']);
    self::assertSame('English navigation links', $english_values['description']);

    $this->enableEnglishConfigurationLock();

    // Promote the existing English translation into the canonical base through
    // the real config entity save path. Config Language Lock owns only the
    // resulting base langcode.
    $menu = Menu::load($id);
    self::assertInstanceOf(Menu::class, $menu);
    $menu
      ->set('label', $english_values['label'])
      ->set('description', $english_values['description'])
      ->save();

    // The former French source becomes a normal language override.
    $override_factory
      ->getOverride('fr', $name)
      ->setData($french_values)
      ->save();

    // English is now the source language, so keeping an EN override would be a
    // redundant same-language translation collection.
    $override_factory->getOverride('en', $name)->delete();

    $config_factory->reset($name);
    $canonical = $config_factory->getEditable($name);
    self::assertSame('en', $canonical->get('langcode'));
    self::assertSame('Main navigation EN', $canonical->get('label'));
    self::assertSame('English navigation links', $canonical->get('description'));
    self::assertSame($id, $canonical->get('id'));
    self::assertTrue((bool) $canonical->get('locked'));

    $french_override = $override_factory->getOverride('fr', $name);
    self::assertFalse($french_override->isNew());
    self::assertSame('Navigation principale FR', $french_override->get('label'));
    self::assertSame('Liens de navigation FR', $french_override->get('description'));

    $english_override = $override_factory->getOverride('en', $name);
    self::assertTrue($english_override->isNew());
    self::assertSame([], $english_override->get());

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

    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * Creates a FR source config entity plus a distinct EN language override.
   */
  private function createFrenchMenuWithEnglishOverride(string $id): string {
    Menu::create([
      'id' => $id,
      'label' => 'Navigation principale FR',
      'description' => 'Liens de navigation FR',
      'langcode' => 'fr',
      'locked' => TRUE,
    ])->save();

    $name = 'system.menu.' . $id;
    $this->container
      ->get('language.config_factory_override')
      ->getOverride('en', $name)
      ->set('label', 'Main navigation EN')
      ->set('description', 'English navigation links')
      ->save();

    $this->container->get('config.factory')->reset($name);
    $source = $this->container->get('config.factory')->getEditable($name);
    self::assertSame('fr', $source->get('langcode'));
    self::assertSame('Navigation principale FR', $source->get('label'));
    self::assertSame('Liens de navigation FR', $source->get('description'));
    self::assertSame($id, $source->get('id'));
    self::assertTrue((bool) $source->get('locked'));

    return $name;
  }

  /**
   * Asserts merged configuration values for one negotiated language.
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
   * Enables the contributed EN lock only inside the Kernel test.
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

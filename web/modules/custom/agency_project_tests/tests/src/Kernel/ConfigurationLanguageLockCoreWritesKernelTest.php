<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves Configuration Language Lock on native Drupal configuration writes.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
#[Group('configuration_language_governance')]
final class ConfigurationLanguageLockCoreWritesKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
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

    self::assertTrue(
      $this->container->get('module_handler')->moduleExists('config_language_lock'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * A config entity explicitly created as FR is persisted as EN when locked.
   */
  public function testDirectConfigEntitySaveUsesLockedEnglishLanguage(): void {
    $this->enableEnglishConfigurationLock();

    NodeType::create([
      'type' => 'lock_direct_probe',
      'name' => 'Lock direct probe',
      'description' => 'Created with an explicit French configuration language.',
      'langcode' => 'fr',
    ])->save();

    self::assertSame(
      'en',
      $this->config('node.type.lock_direct_probe')->get('langcode'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
    self::assertSame(
      FALSE,
      $this->config('config_language_lock.settings')->get('follow_site_default'),
    );
  }

  /**
   * A core Config Action save normalizes a pre-existing FR config entity to EN.
   */
  public function testConfigActionSaveUsesLockedEnglishLanguage(): void {
    NodeType::create([
      'type' => 'lock_action_probe',
      'name' => 'Lock action probe',
      'description' => 'Before Config Action',
      'langcode' => 'fr',
    ])->save();

    self::assertSame(
      'fr',
      $this->config('node.type.lock_action_probe')->get('langcode'),
    );

    $this->enableEnglishConfigurationLock();

    // Setting the lock must not silently rewrite existing configuration. The
    // normalization under test must be caused by the native Config Action save.
    self::assertSame(
      'fr',
      $this->config('node.type.lock_action_probe')->get('langcode'),
    );

    $this->container
      ->get('plugin.manager.config_action')
      ->applyAction(
        'setDescription',
        'node.type.lock_action_probe',
        'Updated by Drupal core Config Action',
      );

    $stored = $this->config('node.type.lock_action_probe');
    self::assertSame('Updated by Drupal core Config Action', $stored->get('description'));
    self::assertSame('en', $stored->get('langcode'));
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * Enables the contributed module's EN lock without any Agency rewrite logic.
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
    self::assertSame(
      FALSE,
      $this->config('config_language_lock.settings')->get('follow_site_default'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

}

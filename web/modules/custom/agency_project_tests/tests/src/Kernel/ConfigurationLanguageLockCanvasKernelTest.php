<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves Configuration Language Lock on native Drupal Canvas config writes.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 * @group governed_canvas
 */
#[Group('configuration_language_governance')]
#[Group('governed_canvas')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockCanvasKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'editor',
    'ckeditor5',
    'filter',
    'text',
    'datetime',
    'image',
    'link',
    'media_library',
    'options',
    'path',
    'file',
    'media',
    'path_alias',
    'views',
    'canvas',
    'language',
    'config_language_lock',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->container->get('theme_installer')->install(['stark']);
    $this->installConfig(['system', 'canvas', 'config_language_lock']);

    foreach (['fr', 'en'] as $langcode) {
      if (ConfigurableLanguage::load($langcode) === NULL) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    self::assertTrue(
      $this->container->get('module_handler')->moduleExists('canvas'),
    );
    self::assertTrue(
      $this->container->get('module_handler')->moduleExists('config_language_lock'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * A Canvas Folder explicitly created as FR is persisted as canonical EN.
   */
  public function testCanvasFolderCreationUsesLockedEnglishLanguage(): void {
    $this->enableEnglishConfigurationLock();

    $uuid = '69200000-0000-4000-8000-000000000001';
    Folder::create([
      'uuid' => $uuid,
      'name' => 'Agency Canvas lock creation probe',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
      'weight' => 6,
      'items' => [],
      'langcode' => 'fr',
    ])->save();

    $stored = $this->config('canvas.folder.' . $uuid);
    self::assertSame('en', $stored->get('langcode'));
    self::assertSame('Agency Canvas lock creation probe', $stored->get('name'));
    self::assertSame(6, $stored->get('weight'));
    self::assertSame([], $stored->get('items'));
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * A native Canvas update normalizes a pre-existing FR Folder to EN.
   */
  public function testCanvasFolderUpdateUsesLockedEnglishLanguage(): void {
    $uuid = '69200000-0000-4000-8000-000000000002';
    $folder = Folder::create([
      'uuid' => $uuid,
      'name' => 'Agency Canvas lock update probe',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
      'weight' => 2,
      'items' => [],
      'langcode' => 'fr',
    ]);
    $folder->save();

    self::assertSame('fr', $this->config('canvas.folder.' . $uuid)->get('langcode'));

    $this->enableEnglishConfigurationLock();

    // Enabling the lock alone must not rewrite existing Canvas configuration.
    self::assertSame('fr', $this->config('canvas.folder.' . $uuid)->get('langcode'));

    $folder = Folder::load($uuid);
    self::assertInstanceOf(Folder::class, $folder);
    $folder->updateFromClientSide([
      'id' => $uuid,
      'type' => Component::ENTITY_TYPE_ID,
      'name' => 'Agency Canvas lock update probe — updated',
      'weight' => 9,
      'items' => [],
    ]);
    $folder->save();

    $stored = $this->config('canvas.folder.' . $uuid);
    self::assertSame('en', $stored->get('langcode'));
    self::assertSame('Agency Canvas lock update probe — updated', $stored->get('name'));
    self::assertSame(9, $stored->get('weight'));
    self::assertSame([], $stored->get('items'));
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
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

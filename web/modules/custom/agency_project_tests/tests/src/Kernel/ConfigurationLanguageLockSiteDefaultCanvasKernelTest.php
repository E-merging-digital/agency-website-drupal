<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Folder;
use Drupal\config_language_lock\Hook\ConfigLanguageLockRequirementsHooks;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Characterizes Candidate A for Drupal Canvas.
 *
 * @group agency_project_tests
 * @group configuration_language_candidate_1314
 * @group governed_canvas
 */
#[Group('configuration_language_candidate_1314')]
#[Group('governed_canvas')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockSiteDefaultCanvasKernelTest extends KernelTestBase {

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
  }

  /**
   * Candidate A satisfies the exact installed Canvas requirement.
   */
  public function testInstalledCanvasRequirementHasNoMismatch(): void {
    $this->enableSiteDefaultConfigurationLock();

    $requirements = $this->container
      ->get(ConfigLanguageLockRequirementsHooks::class)
      ->runtimeRequirements();

    self::assertArrayNotHasKey(
      'config_language_lock_canvas_mismatch',
      $requirements,
    );
  }

  /**
   * Canvas Folder create normalizes explicit EN to site-default FR.
   */
  public function testCanvasFolderCreationUsesFrenchSiteDefault(): void {
    $this->enableSiteDefaultConfigurationLock();

    $uuid = '13140000-0000-4000-8000-000000000001';
    Folder::create([
      'uuid' => $uuid,
      'name' => 'Candidate A Canvas creation probe',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
      'weight' => 6,
      'items' => [],
      'langcode' => 'en',
    ])->save();

    $stored = $this->config('canvas.folder.' . $uuid);
    self::assertSame('fr', $stored->get('langcode'));
    self::assertSame('Candidate A Canvas creation probe', $stored->get('name'));
  }

  /**
   * Canvas update normalizes an existing EN Folder only when it is saved.
   */
  public function testCanvasFolderUpdateUsesFrenchSiteDefault(): void {
    $uuid = '13140000-0000-4000-8000-000000000002';
    Folder::create([
      'uuid' => $uuid,
      'name' => 'Candidate A Canvas update probe',
      'configEntityTypeId' => Component::ENTITY_TYPE_ID,
      'weight' => 2,
      'items' => [],
      'langcode' => 'en',
    ])->save();

    self::assertSame('en', $this->config('canvas.folder.' . $uuid)->get('langcode'));

    $this->enableSiteDefaultConfigurationLock();

    self::assertSame('en', $this->config('canvas.folder.' . $uuid)->get('langcode'));

    $folder = Folder::load($uuid);
    self::assertInstanceOf(Folder::class, $folder);
    $folder->updateFromClientSide([
      'id' => $uuid,
      'type' => Component::ENTITY_TYPE_ID,
      'name' => 'Candidate A Canvas update probe — updated',
      'weight' => 9,
      'items' => [],
    ]);
    $folder->save();

    $stored = $this->config('canvas.folder.' . $uuid);
    self::assertSame('fr', $stored->get('langcode'));
    self::assertSame(
      'Candidate A Canvas update probe — updated',
      $stored->get('name'),
    );
  }

  /**
   * Enables Candidate A only inside this Kernel test.
   */
  private function enableSiteDefaultConfigurationLock(): void {
    $this->config('config_language_lock.settings')
      ->set('locked_langcode', 'fr')
      ->set('follow_site_default', TRUE)
      ->save();

    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

}

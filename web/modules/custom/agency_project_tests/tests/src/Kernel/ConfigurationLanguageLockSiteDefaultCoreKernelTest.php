<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests site-default language policy for core config and Config Actions.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
#[Group('configuration_language_governance')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockSiteDefaultCoreKernelTest extends KernelTestBase {

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
  }

  /**
   * A direct config entity save is normalized to the site-default FR lock.
   */
  public function testDirectConfigEntitySaveUsesFrenchSiteDefault(): void {
    $this->enableSiteDefaultConfigurationLock();

    NodeType::create([
      'type' => 'candidate_direct_probe',
      'name' => 'Candidate direct probe',
      'description' => 'Explicit English config write.',
      'langcode' => 'en',
    ])->save();

    self::assertSame(
      'fr',
      $this->config('node.type.candidate_direct_probe')->get('langcode'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * Settings alone do not rewrite; a real Config Action save does normalize.
   */
  public function testConfigActionUsesFrenchAndSettingsDoNotAutorewrite(): void {
    NodeType::create([
      'type' => 'candidate_action_probe',
      'name' => 'Candidate action probe',
      'description' => 'Before Config Action',
      'langcode' => 'en',
    ])->save();

    self::assertSame(
      'en',
      $this->config('node.type.candidate_action_probe')->get('langcode'),
    );

    $this->enableSiteDefaultConfigurationLock();

    // Changing lock settings alone does not rewrite existing configuration.
    self::assertSame(
      'en',
      $this->config('node.type.candidate_action_probe')->get('langcode'),
    );

    $this->container
      ->get('plugin.manager.config_action')
      ->applyAction(
        'setMultiple',
        'node.type.candidate_action_probe',
        [
          [
            'description',
            'Updated by Drupal core Config Action under Candidate A',
          ],
        ],
      );

    $stored = $this->config('node.type.candidate_action_probe');
    self::assertSame(
      'Updated by Drupal core Config Action under Candidate A',
      $stored->get('description'),
    );
    self::assertSame('fr', $stored->get('langcode'));
  }

  /**
   * Enables the site-default language policy for this Kernel test.
   */
  private function enableSiteDefaultConfigurationLock(): void {
    $this->config('config_language_lock.settings')
      ->set('locked_langcode', 'fr')
      ->set('follow_site_default', TRUE)
      ->save();

    self::assertSame(
      'fr',
      $this->config('config_language_lock.settings')->get('locked_langcode'),
    );
    self::assertTrue(
      $this->config('config_language_lock.settings')->get('follow_site_default'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

}

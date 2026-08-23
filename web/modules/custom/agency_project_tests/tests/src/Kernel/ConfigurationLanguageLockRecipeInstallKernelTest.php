<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\Core\Recipe\RecipeRunner;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves Configuration Language Lock for recipes and extension installation.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
#[Group('configuration_language_governance')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockRecipeInstallKernelTest extends KernelTestBase {

  use RecipeTestTrait;

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
   * Installing a core module keeps its config entity canonically English.
   */
  public function testModuleInstallKeepsCanonicalEnglishConfiguration(): void {
    $this->enableEnglishConfigurationLock();

    self::assertFalse(
      $this->container->get('module_handler')->moduleExists('shortcut'),
    );

    self::assertTrue(
      $this->container->get('module_installer')->install(['shortcut']),
    );

    self::assertTrue(\Drupal::moduleHandler()->moduleExists('shortcut'));
    self::assertSame('en', \Drupal::config('shortcut.set.default')->get('langcode'));
    self::assertSame('fr', \Drupal::config('system.site')->get('default_langcode'));
  }

  /**
   * A real Recipe cannot persist a deliberately wrong French base langcode.
   */
  public function testRecipeNormalizesExplicitFrenchLangcodeAndInstallsModule(): void {
    NodeType::create([
      'type' => 'lock_recipe_probe',
      'name' => 'Lock recipe probe',
      'description' => 'Before Recipe',
      'langcode' => 'fr',
    ])->save();

    self::assertSame(
      'fr',
      $this->config('node.type.lock_recipe_probe')->get('langcode'),
    );

    $this->enableEnglishConfigurationLock();

    $recipe = $this->createRecipe([
      'name' => 'Configuration language lock Recipe probe',
      'install' => [
        'shortcut',
      ],
      'config' => [
        'actions' => [
          'node.type.lock_recipe_probe' => [
            'setMultiple' => [
              [
                'langcode',
                'fr',
              ],
              [
                'description',
                'Updated by Drupal Recipe',
              ],
            ],
          ],
        ],
      ],
    ]);

    RecipeRunner::processRecipe($recipe);

    self::assertSame(
      'Updated by Drupal Recipe',
      \Drupal::config('node.type.lock_recipe_probe')->get('description'),
    );
    self::assertSame(
      'en',
      \Drupal::config('node.type.lock_recipe_probe')->get('langcode'),
    );
    self::assertTrue(\Drupal::moduleHandler()->moduleExists('shortcut'));
    self::assertSame('en', \Drupal::config('shortcut.set.default')->get('langcode'));
    self::assertSame('fr', \Drupal::config('system.site')->get('default_langcode'));
  }

  /**
   * Enables the contributed module's EN lock only inside the Kernel test.
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

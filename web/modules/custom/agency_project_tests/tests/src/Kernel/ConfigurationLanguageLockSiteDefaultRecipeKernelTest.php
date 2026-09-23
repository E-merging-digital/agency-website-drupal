<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\config_language_lock\ConfigLanguageLockBatch;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Characterizes Candidate A for Recipes and extension installation.
 *
 * @group agency_project_tests
 * @group configuration_language_candidate_1314
 */
#[Group('configuration_language_candidate_1314')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockSiteDefaultRecipeKernelTest extends KernelTestBase {

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
   * A real Recipe Config Action cannot persist an explicit EN langcode.
   */
  public function testRecipeConfigActionUsesFrenchSiteDefault(): void {
    NodeType::create([
      'type' => 'candidate_recipe_probe',
      'name' => 'Candidate Recipe probe',
      'description' => 'Before Recipe',
      'langcode' => 'en',
    ])->save();

    $this->enableSiteDefaultConfigurationLock();

    $recipe = $this->createRecipe([
      'name' => 'Candidate A configuration language Recipe probe',
      'install' => ['shortcut'],
      'config' => [
        'actions' => [
          'node.type.candidate_recipe_probe' => [
            'setMultiple' => [
              ['langcode', 'en'],
              ['description', 'Updated by Drupal Recipe under Candidate A'],
            ],
          ],
        ],
      ],
    ]);

    RecipeRunner::processRecipe($recipe);

    $stored = $this->config('node.type.candidate_recipe_probe');
    self::assertSame('fr', $stored->get('langcode'));
    self::assertSame(
      'Updated by Drupal Recipe under Candidate A',
      $stored->get('description'),
    );
    self::assertTrue(\Drupal::moduleHandler()->moduleExists('shortcut'));
  }

  /**
   * Normal extension install queues the module's global normalization batch.
   */
  public function testModuleInstallBatchNormalizesExistingConfigToFrench(): void {
    NodeType::create([
      'type' => 'candidate_module_probe',
      'name' => 'Candidate module probe',
      'langcode' => 'en',
    ])->save();

    $this->enableSiteDefaultConfigurationLock();

    self::assertTrue(
      $this->container->get('module_installer')->install(['shortcut']),
    );

    $queued = batch_get();
    self::assertNotEmpty($queued['sets'] ?? []);
    self::assertSame(
      'en',
      $this->config('node.type.candidate_module_probe')->get('langcode'),
    );

    // Execute the exact Config Language Lock batch callback mechanism that the
    // extension-install hook queues in a normal request.
    $batch_service = $this->container->get(ConfigLanguageLockBatch::class);
    self::assertInstanceOf(ConfigLanguageLockBatch::class, $batch_service);
    $batch = $batch_service->buildBatch(FALSE);
    self::assertIsArray($batch);

    $context = [];
    foreach ($batch['operations'] as [$callback, $arguments]) {
      self::assertSame(
        ConfigLanguageLockBatch::class . ':batchUpdateConfigChunk',
        $callback,
      );
      $batch_service->batchUpdateConfigChunk($arguments[0], $context);
    }

    self::assertSame(
      'fr',
      $this->config('node.type.candidate_module_probe')->get('langcode'),
    );
    self::assertSame(
      'fr',
      $this->config('shortcut.set.default')->get('langcode'),
    );
    self::assertGreaterThan(
      0,
      $context['results']['stats']['config_items_changed'] ?? 0,
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

    self::assertSame(
      'fr',
      $this->config('config_language_lock.settings')->get('locked_langcode'),
    );
    self::assertTrue(
      $this->config('config_language_lock.settings')->get('follow_site_default'),
    );
  }

}

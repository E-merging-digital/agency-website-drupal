<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves EN configuration and FR content through native Drupal forms.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
#[Group('configuration_language_governance')]
#[RunTestsInSeparateProcesses]
final class ConfigurationLanguageLockAdminUiTest extends BrowserTestBase {

  /**
   * Content type used by the proof.
   */
  private const CONTENT_TYPE = 'config_lock_ui_probe';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'config_language_lock',
    'language',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Existing French content created before the configuration lock.
   */
  private int $existingNodeId;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    foreach (['fr', 'en'] as $langcode) {
      if (ConfigurableLanguage::load($langcode) === NULL) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    NodeType::create([
      'type' => self::CONTENT_TYPE,
      'name' => 'Config lock UI probe',
      'description' => 'Before native Drupal admin UI save.',
      'langcode' => 'fr',
    ])->save();

    self::assertSame(
      'fr',
      $this->config('node.type.' . self::CONTENT_TYPE)->get('langcode'),
    );

    $existing = $this->drupalCreateNode([
      'type' => self::CONTENT_TYPE,
      'title' => 'Existing French editorial content',
      'langcode' => 'fr',
      'status' => 1,
    ]);
    $this->existingNodeId = (int) $existing->id();
    self::assertSame('fr', $existing->language()->getId());

    $account = $this->drupalCreateUser([
      'access content',
      'administer content types',
      'create ' . self::CONTENT_TYPE . ' content',
    ]);
    self::assertNotFalse($account);
    $this->drupalLogin($account);
  }

  /**
   * Admin config saves are EN while editorial content remains FR.
   */
  public function testAdminConfigSaveDoesNotChangeEditorialLanguage(): void {
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));

    $this->enableEnglishConfigurationLock();

    // Merely enabling the lock must not retroactively rewrite existing config.
    $this->resetConfig('node.type.' . self::CONTENT_TYPE);
    self::assertSame(
      'fr',
      $this->config('node.type.' . self::CONTENT_TYPE)->get('langcode'),
    );

    $this->drupalGet('/admin/structure/types/manage/' . self::CONTENT_TYPE);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals(
      'description',
      'Before native Drupal admin UI save.',
    );

    $page = $this->getSession()->getPage();
    $page->fillField(
      'description',
      'Updated through the native Drupal admin UI.',
    );
    $page->pressButton('edit-submit');
    $this->assertSession()->statusCodeEquals(200);

    $this->resetConfig('node.type.' . self::CONTENT_TYPE);
    $stored_type = $this->config('node.type.' . self::CONTENT_TYPE);
    self::assertSame('en', $stored_type->get('langcode'));
    self::assertSame(
      'Updated through the native Drupal admin UI.',
      $stored_type->get('description'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));

    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $node_storage->resetCache([$this->existingNodeId]);
    $existing = $node_storage->load($this->existingNodeId);
    self::assertInstanceOf(NodeInterface::class, $existing);
    self::assertSame('fr', $existing->language()->getId());
    self::assertSame('Existing French editorial content', $existing->label());

    $this->drupalGet('/node/add/' . self::CONTENT_TYPE);
    $this->assertSession()->statusCodeEquals(200);
    $page = $this->getSession()->getPage();
    $page->fillField('title[0][value]', 'New French editorial content');
    $page->pressButton('edit-submit');
    $this->assertSession()->statusCodeEquals(200);

    $created = $node_storage->loadByProperties([
      'type' => self::CONTENT_TYPE,
      'title' => 'New French editorial content',
    ]);
    self::assertCount(1, $created);
    $new_node = reset($created);
    self::assertInstanceOf(NodeInterface::class, $new_node);
    self::assertSame('fr', $new_node->language()->getId());

    $this->resetConfig('node.type.' . self::CONTENT_TYPE);
    self::assertSame(
      'en',
      $this->config('node.type.' . self::CONTENT_TYPE)->get('langcode'),
    );
    self::assertSame('fr', $this->config('system.site')->get('default_langcode'));
  }

  /**
   * Enables the contributed EN lock only inside this test site.
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

  /**
   * Clears one config object from the test process config factory cache.
   */
  private function resetConfig(string $name): void {
    $this->container->get('config.factory')->reset($name);
  }

}

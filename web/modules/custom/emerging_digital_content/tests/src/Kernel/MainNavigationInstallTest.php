<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the final main-navigation bootstrap after module installation.
 *
 * @group emerging_digital_content
 */
#[RunTestsInSeparateProcesses]
final class MainNavigationInstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'link',
    'menu_link_content',
    'emerging_digital_content',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('menu_link_content');
  }

  /**
   * Tests the hook is scoped to the module install batch and is idempotent.
   */
  public function testModulesInstalledEnsuresMainNavigationIdempotently(): void {
    $module_handler = $this->container->get('module_handler');

    $module_handler->invoke(
      'emerging_digital_content',
      'modules_installed',
      [['system'], TRUE],
    );
    self::assertSame([], $this->loadMainNavigationLinks());

    $module_handler->invoke(
      'emerging_digital_content',
      'modules_installed',
      [['system', 'emerging_digital_content'], TRUE],
    );

    $expected = [
      'Accueil' => ['uri' => 'internal:/accueil', 'weight' => 0],
      'Services' => ['uri' => 'internal:/services', 'weight' => 1],
      'IA & Drupal' => ['uri' => 'internal:/ia-drupal', 'weight' => 2],
      'Cas clients' => ['uri' => 'internal:/cas-clients', 'weight' => 3],
      'Blog' => ['uri' => 'internal:/blog', 'weight' => 4],
      'Contact' => ['uri' => 'internal:/contact', 'weight' => 5],
    ];

    self::assertSame($expected, $this->loadMainNavigationLinks());

    $module_handler->invoke(
      'emerging_digital_content',
      'modules_installed',
      [['emerging_digital_content'], TRUE],
    );
    self::assertSame($expected, $this->loadMainNavigationLinks());
  }

  /**
   * Loads the expected values keyed by menu-link title.
   *
   * @return array<string, array{uri: string, weight: int}>
   *   Main navigation values.
   */
  private function loadMainNavigationLinks(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('menu_link_content');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('menu_name', 'main')
      ->sort('weight')
      ->execute();

    $actual = [];
    foreach ($storage->loadMultiple($ids) as $link) {
      self::assertTrue((bool) $link->get('enabled')->value);
      $link_item = $link->get('link')->first();
      self::assertNotNull($link_item);
      $actual[(string) $link->label()] = [
        'uri' => (string) ($link_item->getValue()['uri'] ?? ''),
        'weight' => (int) $link->get('weight')->value,
      ];
    }

    return $actual;
  }

}

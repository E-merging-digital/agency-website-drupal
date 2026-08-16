<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates the repository-owned Main navigation Default Content package.
 *
 * @group emerging_digital_content
 */
final class MainNavigationDefaultContentTest extends TestCase {

  /**
   * Ensures the six packaged links match the public navigation contract.
   */
  public function testMainNavigationDefaultContentContract(): void {
    $module_path = dirname(__DIR__, 3);
    $info = Yaml::parseFile($module_path . '/emerging_digital_content.info.yml');

    $expected = [
      '0160a2d4-d1fa-48e3-94e4-06159373cbe6' => ['Accueil', 'internal:/accueil', 0],
      'c0ad4c7b-a29c-4cc4-9788-1b70c9f23ec0' => ['Services', 'internal:/services', 1],
      '18daf97a-ac29-4a89-8b90-85eeb7f6739c' => ['IA & Drupal', 'internal:/ia-drupal', 2],
      'ff24fa4f-99d3-4e46-91ab-22f6aac0b42e' => ['Cas clients', 'internal:/cas-clients', 3],
      '4220d840-7314-4726-955a-2ca1c9f6cde5' => ['Blog', 'internal:/blog', 4],
      '6f2663ee-2902-4e9f-887e-3e14a53026c6' => ['Contact', 'internal:/contact', 5],
    ];

    self::assertSame(
      array_keys($expected),
      $info['default_content']['menu_link_content'] ?? [],
    );

    foreach ($expected as $uuid => [$title, $uri, $weight]) {
      $definition = Yaml::parseFile(
        $module_path . '/content/menu_link_content/' . $uuid . '.yml',
      );

      self::assertSame('1.0', $definition['_meta']['version'] ?? NULL);
      self::assertSame('menu_link_content', $definition['_meta']['entity_type'] ?? NULL);
      self::assertSame($uuid, $definition['_meta']['uuid'] ?? NULL);
      self::assertSame('menu_link_content', $definition['_meta']['bundle'] ?? NULL);
      self::assertSame('fr', $definition['_meta']['default_langcode'] ?? NULL);
      self::assertTrue($definition['default']['enabled'][0]['value'] ?? FALSE);
      self::assertSame($title, $definition['default']['title'][0]['value'] ?? NULL);
      self::assertSame('main', $definition['default']['menu_name'][0]['value'] ?? NULL);
      self::assertSame($uri, $definition['default']['link'][0]['uri'] ?? NULL);
      self::assertSame($weight, $definition['default']['weight'][0]['value'] ?? NULL);
      self::assertFalse($definition['default']['expanded'][0]['value'] ?? TRUE);
    }
  }

}

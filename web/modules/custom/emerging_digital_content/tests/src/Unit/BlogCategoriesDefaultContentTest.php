<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates the repository-owned initial Blog category package.
 *
 * @group emerging_digital_content
 */
final class BlogCategoriesDefaultContentTest extends TestCase {

  /**
   * Ensures the five packaged categories match issue #397.
   */
  public function testBlogCategoriesDefaultContentContract(): void {
    $module_path = dirname(__DIR__, 3);
    $info = Yaml::parseFile($module_path . '/emerging_digital_content.info.yml');

    $expected = [
      'd4390eaa-6097-4aee-89f8-eeed57401508' => [
        'Décisions web / agence senior',
        'Web decisions / senior agency',
        0,
      ],
      'e7610b1c-2723-491e-837f-b0eff435f507' => [
        'PHP / architecture / frameworks',
        'PHP / architecture / frameworks',
        1,
      ],
      '5d072809-6653-42a5-a404-bb6bb18a465e' => [
        'Drupal comme expertise forte',
        'Drupal expertise',
        2,
      ],
      'fc8248c8-015b-4215-9354-ef554a16416d' => [
        'IA encadrée',
        'Governed AI',
        3,
      ],
      'f2f77a08-e528-4ac7-9d78-24464bc99f21' => [
        'Qualité web / SEO / accessibilité',
        'Web quality / SEO / accessibility',
        4,
      ],
    ];

    self::assertContains('drupal:taxonomy', $info['dependencies'] ?? []);
    self::assertSame(
      array_keys($expected),
      $info['default_content']['taxonomy_term'] ?? [],
    );

    foreach ($expected as $uuid => [$fr, $en, $weight]) {
      $definition = Yaml::parseFile(
        $module_path . '/content/taxonomy_term/' . $uuid . '.yml',
      );

      self::assertSame('1.0', $definition['_meta']['version'] ?? NULL);
      self::assertSame('taxonomy_term', $definition['_meta']['entity_type'] ?? NULL);
      self::assertSame($uuid, $definition['_meta']['uuid'] ?? NULL);
      self::assertSame('blog_categories', $definition['_meta']['bundle'] ?? NULL);
      self::assertSame('fr', $definition['_meta']['default_langcode'] ?? NULL);
      self::assertTrue($definition['default']['status'][0]['value'] ?? FALSE);
      self::assertSame($fr, $definition['default']['name'][0]['value'] ?? NULL);
      self::assertSame($weight, $definition['default']['weight'][0]['value'] ?? NULL);
      self::assertSame($en, $definition['translations']['en']['name'][0]['value'] ?? NULL);
      self::assertTrue($definition['translations']['en']['status'][0]['value'] ?? FALSE);
    }
  }

}

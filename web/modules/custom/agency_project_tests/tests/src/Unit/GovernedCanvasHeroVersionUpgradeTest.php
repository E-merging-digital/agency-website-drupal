<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\emerging_digital_content\ContentSync\GovernedCoreDefaultContentImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Tests the fixed Canvas proof page Hero migration guard.
 *
 * @group agency_project_tests
 * @group governed_canvas
 */
final class GovernedCanvasHeroVersionUpgradeTest extends TestCase {

  private const OLD = 'f6ebbdd7632bea80';
  private const NEW = '8172164706c8dc5f';

  /**
   * New imported pages and repeat calls are idempotent.
   */
  public function testCurrentHeroRequiresNoSave(): void {
    self::assertNull($this->plan($this->tree(self::NEW)));
  }

  /**
   * The only allowed edit preserves every other component and input byte.
   */
  public function testKnownOldVersionChangesOnlyHeroMarker(): void {
    $before = $this->tree(self::OLD);
    $expected = $before;
    $expected[0]['component_version'] = self::NEW;
    self::assertSame($expected, $this->plan($before));
    self::assertNull($this->plan($expected));
  }

  /**
   * Unknown component versions, UUIDs, order and input/layout shape are denied.
   */
  public function testUnexpectedTreeFailsClosed(): void {
    $mutations = [
      static function (array &$t): void {
        $t[0]['uuid'] = 'wrong';
      },
      static function (array &$t): void {
        $t[0]['component_id'] = 'sdc.other';
      },
      static function (array &$t): void {
        $t[0]['component_version'] = 'other';
      },
      static function (array &$t): void {
        $t[1]['component_version'] = self::OLD;
      },
      static function (array &$t): void {
        $t[0]['parent_uuid'] = $t[1]['uuid'];
      },
      static function (array &$t): void {
        $t[0]['slot'] = 'nested';
      },
      static function (array &$t): void {
        $t[0]['unexpected'] = 'x';
      },
      static function (array &$t): void {
        $t[0]['inputs'] = '{broken';
      },
      static function (array &$t): void {
        $t[0]['inputs'] = '{"heading":"a","extra":"x"}';
      },
      static function (array &$t): void {
        unset($t[2]);
      },
      static function (array &$t): void {
        [$t[0], $t[1]] = [$t[1], $t[0]];
      },
    ];
    foreach ($mutations as $n => $mutate) {
      $tree = $this->tree(self::OLD);
      $mutate($tree);
      try {
        $this->plan($tree);
        self::fail("Unknown Canvas tree mutation $n was accepted.");
      }
      catch (\RuntimeException) {
        self::assertTrue(TRUE);
      }
    }
  }

  /**
   * Semantic equality admits reordered maps, never changed schema or history.
   */
  public function testEffectiveSchemaCompatibility(): void {
    $config = Yaml::parseFile(dirname(DRUPAL_ROOT)
      . '/config/sync/canvas.component.sdc.emerging_digital.hero.yml');
    $this->schema($config);
    $reordered = $config;
    $reordered['versioned_properties']['active'] = array_reverse(
      $reordered['versioned_properties']['active'], TRUE);
    $this->schema($reordered);
    $mutations = [
      static function (array &$c): void {
        $c['active_version'] = self::OLD;
      },
      static function (array &$c): void {
        unset($c['versioned_properties'][self::OLD]);
      },
      static function (array &$c): void {
        $c['versioned_properties']['unknown'] = [];
      },
      static function (array &$c): void {
        $c['versioned_properties'][self::OLD]['settings']['prop_field_definitions']['variant']['required'] = FALSE;
      },
      static function (array &$c): void {
        $c['versioned_properties'][self::OLD]['fallback_metadata']['slot_definitions']['media']['title'] = 'Changed';
      },
    ];
    foreach ($mutations as $n => $mutate) {
      $bad = $config;
      $mutate($bad);
      try {
        $this->schema($bad);
        self::fail("Schema mutation $n was accepted.");
      }
      catch (\RuntimeException) {
        self::assertTrue(TRUE);
      }
    }
  }

  /**
   * Returns the fixed native Canvas test tree with opaque stored input data.
   */
  private function tree(string $version): array {
    return [
      [
        'uuid' => '52600000-0000-4000-8000-000000000101',
        'component_id' => 'sdc.emerging_digital.hero',
        'component_version' => $version,
        'inputs' => '{"heading":"Keep this","heading_level":"h1","variant":"home"}',
      ],
      [
        'uuid' => '52600000-0000-4000-8000-000000000102',
        'component_id' => 'sdc.emerging_digital.trust-list',
        'component_version' => '61b25a47f367765e',
        'inputs' => '{"heading":"Keep list","items":["one","two"]}',
      ],
      [
        'uuid' => '52600000-0000-4000-8000-000000000103',
        'component_id' => 'sdc.emerging_digital.cta',
        'component_version' => 'b4f165563eb02357',
        'inputs' => '{"heading":"Keep CTA","heading_level":"h2"}',
      ],
    ];
  }

  /**
   * Invokes the source-only plan without writing an entity.
   */
  private function plan(array $tree): ?array {
    return (new \ReflectionMethod(GovernedCoreDefaultContentImporter::class, 'planHeroVersionUpdate'))
      ->invoke(NULL, $tree);
  }

  /**
   * Invokes the same guard used for effective Canvas configuration.
   */
  private function schema(array $config): void {
    (new \ReflectionMethod(GovernedCoreDefaultContentImporter::class, 'assertHeroSchemaCompatible'))
      ->invoke(NULL, $config);
  }

}

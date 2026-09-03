<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the path-only #995 Canvas runtime drift contract.
 *
 * @group agency_project_tests
 * @group canvas_runtime_diff_paths_995
 */
final class CanvasRuntimeDiffPaths995Test extends TestCase {

  private const PROBE = 'scripts/runner/canvas-runtime-diff-paths-995.php';

  /**
   * Loads the bounded comparator once for synthetic offline validation.
   */
  public static function setUpBeforeClass(): void {
    require_once dirname(DRUPAL_ROOT) . '/' . self::PROBE;
  }

  /**
   * The allowlist is exactly the Project Lead-authorized 15-name cohort.
   */
  public function testExactFifteenAllowlist(): void {
    self::assertSame([
      'canvas.component.block.announce_block',
      'canvas.component.block.emerging_digital_language_switcher',
      'canvas.component.block.help_block',
      'canvas.component.block.language_block.language_content',
      'canvas.component.block.language_block.language_interface',
      'canvas.component.block.local_actions_block',
      'canvas.component.block.local_tasks_block',
      'canvas.component.block.page_title_block',
      'canvas.component.block.shortcuts',
      'canvas.component.block.system_branding_block',
      'canvas.component.block.system_breadcrumb_block',
      'canvas.component.block.system_clear_cache_block',
      'canvas.component.block.system_powered_by_block',
      'canvas.component.block.user_login_block',
      'canvas.component.block.views_block.content_recent-block_1',
    ], agency_canvas_995_allowed_names());
  }

  /**
   * A sixteenth config fails closed rather than enlarging the cohort.
   */
  public function testSixteenthConfigFailsClosed(): void {
    $dataset = $this->knownDataset();
    $dataset['canvas.component.block.webform_block'] = $this->knownPair();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Exact #995 Canvas cohort mismatch.');
    agency_canvas_995_analyze_dataset('PREPROD', $dataset);
  }

  /**
   * Known historical Canvas drift emits paths only, never compared values.
   */
  public function testKnownHistoricalPatternIsPathOnly(): void {
    $result = agency_canvas_995_analyze_dataset('PREPROD', $this->knownDataset());

    self::assertSame(1, $result['schema_version'] ?? NULL);
    self::assertSame('PREPROD', $result['environment'] ?? NULL);
    self::assertSame(15, $result['cohort_size'] ?? NULL);
    self::assertFalse($result['config_values_exposed'] ?? TRUE);
    self::assertSame(
      'environment + config_name + differing_paths[] + classification',
      $result['public_schema'] ?? NULL,
    );
    self::assertSame(15, $result['summary']['known_canvas_deterministic_drift_pattern'] ?? NULL);
    self::assertSame(0, $result['summary']['unexpected_canvas_business_path_review_required'] ?? NULL);

    $first = $result['items'][0] ?? NULL;
    self::assertIsArray($first);
    self::assertSame([
      'active_version',
      'label',
      'versioned_properties.<version>',
      'versioned_properties.active.settings.default_settings.label',
    ], $first['differing_paths'] ?? NULL);
    self::assertSame(
      'KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN',
      $first['classification'] ?? NULL,
    );

    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    foreach ([
      'SYNC-SECRET-LABEL',
      'ACTIVE-SECRET-LABEL',
      'sync-provider-secret',
      'active-provider-secret',
      'aaaaaaaaaaaaaaaa',
      'bbbbbbbbbbbbbbbb',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $encoded, $forbidden);
    }
    foreach (['active_value', 'sync_value', 'before', 'after', 'raw_yaml'] as $field) {
      self::assertStringNotContainsString($field, $encoded, $field);
    }
  }

  /**
   * PREPROD and PROD use the same schema and paths except environment.
   */
  public function testPreprodAndProdSchemasAreIdenticalExceptEnvironment(): void {
    $preprod = agency_canvas_995_analyze_dataset('PREPROD', $this->knownDataset());
    $prod = agency_canvas_995_analyze_dataset('PROD', $this->knownDataset());

    self::assertSame('PREPROD', $preprod['environment']);
    self::assertSame('PROD', $prod['environment']);
    unset($preprod['environment'], $prod['environment']);
    foreach ($preprod['items'] as &$item) {
      unset($item['environment']);
    }
    unset($item);
    foreach ($prod['items'] as &$item) {
      unset($item['environment']);
    }
    unset($item);
    self::assertSame($preprod, $prod);
  }

  /**
   * Unknown structural shapes fail closed.
   */
  public function testUnknownStructureFailsClosed(): void {
    $pair = $this->knownPair();
    $pair['active']['versioned_properties'] = 'not-an-array';

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Unsupported Canvas component storage structure.');
    agency_canvas_995_analyze_config($pair['active'], $pair['sync']);
  }

  /**
   * An unknown scalar path is surfaced by path and requires review.
   */
  public function testUnknownPathRequiresReview(): void {
    $pair = $this->knownPair();
    $pair['active']['status'] = TRUE;
    $pair['sync']['status'] = FALSE;

    $result = agency_canvas_995_analyze_config($pair['active'], $pair['sync']);
    self::assertContains('status', $result['differing_paths']);
    self::assertSame(
      'UNEXPECTED_CANVAS_BUSINESS_PATH_REVIEW_REQUIRED',
      $result['classification'],
    );
  }

  /**
   * Mixing a historical technical path with a business path stays conservative.
   */
  public function testMixedKnownAndUnknownPathsRequireReview(): void {
    $pair = $this->knownPair();
    $pair['active']['langcode'] = 'fr';
    $pair['sync']['langcode'] = 'en';

    $result = agency_canvas_995_analyze_config($pair['active'], $pair['sync']);
    self::assertContains('active_version', $result['differing_paths']);
    self::assertContains('langcode', $result['differing_paths']);
    self::assertSame(
      'UNEXPECTED_CANVAS_BUSINESS_PATH_REVIEW_REQUIRED',
      $result['classification'],
    );
  }

  /**
   * Arbitrary dynamic map keys are never allowed into public paths.
   */
  public function testUnsafeDynamicKeyFailsClosed(): void {
    $pair = $this->knownPair();
    $pair['active']['third_party_settings'] = [
      'Business Customer Name' => ['enabled' => TRUE],
    ];
    $pair['sync']['third_party_settings'] = [];

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Unsafe dynamic Canvas map key.');
    agency_canvas_995_analyze_config($pair['active'], $pair['sync']);
  }

  /**
   * A historical version key is known only when it equals sync active_version.
   */
  public function testUnprovenHistoricalVersionRequiresReview(): void {
    $pair = $this->knownPair();
    unset($pair['active']['versioned_properties']['aaaaaaaaaaaaaaaa']);
    $pair['active']['versioned_properties']['cccccccccccccccc'] = [
      'settings' => ['default_settings' => ['label' => 'historic']],
    ];

    $result = agency_canvas_995_analyze_config($pair['active'], $pair['sync']);
    self::assertContains('versioned_properties.<version>', $result['differing_paths']);
    self::assertSame(
      'UNEXPECTED_CANVAS_BUSINESS_PATH_REVIEW_REQUIRED',
      $result['classification'],
    );
  }

  /**
   * Config names and paths remain deterministically sorted.
   */
  public function testDeterministicOrdering(): void {
    $dataset = array_reverse($this->knownDataset(), TRUE);
    $result = agency_canvas_995_analyze_dataset('PROD', $dataset);

    $names = array_column($result['items'], 'config_name');
    $expectedNames = agency_canvas_995_allowed_names();
    self::assertSame($expectedNames, $names);
    foreach ($result['items'] as $item) {
      $paths = $item['differing_paths'];
      $sorted = $paths;
      sort($sorted, SORT_STRING);
      self::assertSame($sorted, $paths);
    }
  }

  /**
   * Produces the exact 15-name synthetic dataset.
   *
   * @return array<string, array<string, array<string, mixed>>>
   *   Exact synthetic cohort.
   */
  private function knownDataset(): array {
    $dataset = [];
    foreach (agency_canvas_995_allowed_names() as $name) {
      $dataset[$name] = $this->knownPair();
    }
    return $dataset;
  }

  /**
   * Produces one representative #728/#733 historical Canvas drift pair.
   *
   * @return array{active: array<string, mixed>, sync: array<string, mixed>}
   *   Active and sync structures containing intentionally sensitive values.
   */
  private function knownPair(): array {
    $syncActive = [
      'settings' => [
        'default_settings' => [
          'id' => 'help_block',
          'label' => 'SYNC-SECRET-LABEL',
          'provider' => 'sync-provider-secret',
        ],
      ],
      'fallback_metadata' => ['slot_definitions' => NULL],
    ];
    $activeVersion = [
      'settings' => [
        'default_settings' => [
          'id' => 'help_block',
          'label' => 'ACTIVE-SECRET-LABEL',
          'provider' => 'sync-provider-secret',
        ],
      ],
      'fallback_metadata' => ['slot_definitions' => NULL],
    ];

    return [
      'active' => [
        'langcode' => 'en',
        'status' => FALSE,
        'active_version' => 'bbbbbbbbbbbbbbbb',
        'versioned_properties' => [
          'active' => $activeVersion,
          'aaaaaaaaaaaaaaaa' => $syncActive,
        ],
        'label' => 'ACTIVE-SECRET-LABEL',
        'provider' => 'active-provider-secret',
      ],
      'sync' => [
        'langcode' => 'en',
        'status' => FALSE,
        'active_version' => 'aaaaaaaaaaaaaaaa',
        'versioned_properties' => [
          'active' => $syncActive,
        ],
        'label' => 'SYNC-SECRET-LABEL',
        'provider' => 'active-provider-secret',
      ],
    ];
  }

}

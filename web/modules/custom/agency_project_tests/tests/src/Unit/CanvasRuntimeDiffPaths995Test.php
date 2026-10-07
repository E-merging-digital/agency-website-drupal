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
    ], $this->allowedNames());
  }

  /**
   * A sixteenth config fails closed rather than enlarging the cohort.
   */
  public function testSixteenthConfigFailsClosed(): void {
    $dataset = $this->knownDataset();
    $dataset['canvas.component.block.webform_block'] = $this->knownPair();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Exact #995 Canvas cohort mismatch.');
    $this->analyzeDataset('PREPROD', $dataset);
  }

  /**
   * Known historical Canvas drift emits paths only, never compared values.
   */
  public function testKnownHistoricalPatternIsPathOnly(): void {
    $result = $this->analyzeDataset('PREPROD', $this->knownDataset());

    self::assertSame(1, $result['schema_version']);
    self::assertSame('PREPROD', $result['environment']);
    self::assertSame(15, $result['cohort_size']);
    self::assertFalse($result['config_values_exposed']);
    self::assertSame(
      'environment + config_name + differing_paths[] + classification',
      $result['public_schema'],
    );
    $summary = $result['summary'];
    self::assertSame(
      15,
      $summary['known_canvas_deterministic_drift_pattern'],
    );
    self::assertSame(
      0,
      $summary['unexpected_canvas_business_path_review_required'],
    );

    $first = $result['items'][0] ?? NULL;
    self::assertIsArray($first);
    self::assertSame([
      'active_version',
      'label',
      'versioned_properties.<version>',
      'versioned_properties.active.settings.default_settings.label',
    ], $first['differing_paths']);
    self::assertSame(
      'KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN',
      $first['classification'],
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
    foreach (
      ['active_value', 'sync_value', 'before', 'after', 'raw_yaml'] as $field
    ) {
      self::assertStringNotContainsString($field, $encoded, $field);
    }
  }

  /**
   * PREPROD and PROD use the same schema and paths except environment.
   */
  public function testPreprodAndProdSchemasAreIdenticalExceptEnvironment(): void {
    $preprod = $this->analyzeDataset('PREPROD', $this->knownDataset());
    $prod = $this->analyzeDataset('PROD', $this->knownDataset());

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
    $this->expectExceptionMessage(
      'Unsupported Canvas component storage structure.',
    );
    $this->analyzeConfig($pair['active'], $pair['sync']);
  }

  /**
   * An unknown scalar path is surfaced by path and requires review.
   */
  public function testUnknownPathRequiresReview(): void {
    $pair = $this->knownPair();
    $pair['active']['status'] = TRUE;
    $pair['sync']['status'] = FALSE;

    $result = $this->analyzeConfig($pair['active'], $pair['sync']);
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

    $result = $this->analyzeConfig($pair['active'], $pair['sync']);
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
    $this->expectExceptionMessage(
      'Unknown Canvas array shape at path: third_party_settings',
    );
    $this->analyzeConfig($pair['active'], $pair['sync']);
  }

  /**
   * Non-string map keys fail closed with path and type, never raw key/value.
   */
  public function testNonStringMapKeyDiagnosticIsBounded(): void {
    $pair = $this->knownPair();
    $activeVersion =& $pair['active']['versioned_properties']['active'];
    $activeVersion['settings']['default_settings'][424242] =
      'DO-NOT-EXPOSE-ACTIVE-VALUE';

    try {
      $this->analyzeConfig($pair['active'], $pair['sync']);
      self::fail('Expected non-string Canvas map key to fail closed.');
    }
    catch (\RuntimeException $exception) {
      $message = $exception->getMessage();
      self::assertStringContainsString(
        'Unknown Canvas map key type at path '
        . 'versioned_properties.active.settings.default_settings',
        $message,
      );
      self::assertStringContainsString('key_type=int', $message);
      self::assertStringNotContainsString('424242', $message);
      self::assertStringNotContainsString(
        'DO-NOT-EXPOSE-ACTIVE-VALUE',
        $message,
      );
    }
  }

  /**
   * Numeric-only historical version keys normalize only on exact proof.
   */
  public function testNumericVersionExactMatchUsesExistingPublicPath(): void {
    $pair = $this->numericVersionPair();

    $result = $this->analyzeConfig($pair['active'], $pair['sync']);

    self::assertSame([
      'active_version',
      'label',
      'versioned_properties.<version>',
      'versioned_properties.active.settings.default_settings.label',
    ], $result['differing_paths']);
    self::assertSame(
      'KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN',
      $result['classification'],
    );

    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('1234567890123456', $encoded);
    self::assertStringNotContainsString('SYNC-SECRET-LABEL', $encoded);
    self::assertStringNotContainsString('ACTIVE-SECRET-LABEL', $encoded);
  }

  /**
   * A version-shaped integer outside versioned_properties still fails closed.
   */
  public function testNumericVersionOutsideExactParentFailsClosed(): void {
    $pair = $this->knownPair();
    $activeVersion =& $pair['active']['versioned_properties']['active'];
    $activeVersion['settings']['default_settings'][1234567890123456] =
      'DO-NOT-EXPOSE-NUMERIC-VALUE';

    $this->assertBoundedIntKeyFailure(
      $pair,
      'versioned_properties.active.settings.default_settings',
      '1234567890123456',
      'DO-NOT-EXPOSE-NUMERIC-VALUE',
    );
  }

  /**
   * A numeric version differing from sync active_version fails closed.
   */
  public function testDifferentNumericVersionFailsClosed(): void {
    $pair = $this->numericVersionPair();
    unset($pair['active']['versioned_properties'][1234567890123456]);
    $pair['active']['versioned_properties'][1234567890123457] = [
      'settings' => ['default_settings' => ['label' => 'DO-NOT-EXPOSE']],
    ];

    $this->assertBoundedIntKeyFailure(
      $pair,
      'versioned_properties',
      '1234567890123457',
      'DO-NOT-EXPOSE',
    );
  }

  /**
   * A numeric version present only in sync fails closed.
   */
  public function testNumericVersionPresentOnlyInSyncFailsClosed(): void {
    $pair = $this->numericVersionPair();
    unset($pair['active']['versioned_properties'][1234567890123456]);
    $pair['sync']['versioned_properties'][1234567890123456] = [
      'settings' => ['default_settings' => ['label' => 'DO-NOT-EXPOSE']],
    ];

    $this->assertBoundedIntKeyFailure(
      $pair,
      'versioned_properties',
      '1234567890123456',
      'DO-NOT-EXPOSE',
    );
  }

  /**
   * A numeric version present on both sides fails closed.
   */
  public function testNumericVersionPresentOnBothSidesFailsClosed(): void {
    $pair = $this->numericVersionPair();
    $pair['sync']['versioned_properties'][1234567890123456] = [
      'settings' => ['default_settings' => ['label' => 'DO-NOT-EXPOSE']],
    ];

    $this->assertBoundedIntKeyFailure(
      $pair,
      'versioned_properties',
      '1234567890123456',
      'DO-NOT-EXPOSE',
    );
  }

  /**
   * Short numeric keys under versioned_properties fail closed.
   */
  public function testShortNumericVersionFailsClosed(): void {
    $pair = $this->numericVersionPair();
    unset($pair['active']['versioned_properties'][1234567890123456]);
    $pair['active']['versioned_properties'][1234] = [
      'settings' => ['default_settings' => ['label' => 'DO-NOT-EXPOSE']],
    ];

    $this->assertBoundedIntKeyFailure(
      $pair,
      'versioned_properties',
      '1234',
      'DO-NOT-EXPOSE',
    );
  }

  /**
   * Negative numeric keys under versioned_properties fail closed.
   */
  public function testNegativeNumericVersionFailsClosed(): void {
    $pair = $this->numericVersionPair();
    unset($pair['active']['versioned_properties'][1234567890123456]);
    $pair['active']['versioned_properties'][-123456789012345] = [
      'settings' => ['default_settings' => ['label' => 'DO-NOT-EXPOSE']],
    ];

    $this->assertBoundedIntKeyFailure(
      $pair,
      'versioned_properties',
      '-123456789012345',
      'DO-NOT-EXPOSE',
    );
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

    $result = $this->analyzeConfig($pair['active'], $pair['sync']);
    self::assertContains(
      'versioned_properties.<version>',
      $result['differing_paths'],
    );
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
    $result = $this->analyzeDataset('PROD', $dataset);

    $names = array_column($result['items'], 'config_name');
    $expectedNames = $this->allowedNames();
    self::assertSame($expectedNames, $names);
    foreach ($result['items'] as $item) {
      $paths = $item['differing_paths'];
      $sorted = $paths;
      sort($sorted, SORT_STRING);
      self::assertSame($sorted, $paths);
    }
  }

  /**
   * Calls the dynamically loaded allowlist through a guarded callable.
   *
   * @return list<string>
   *   Exact #995 config-name allowlist.
   */
  private function allowedNames(): array {
    $name = 'agency_canvas_995_allowed_names';
    if (!function_exists($name)) {
      self::fail('The #995 Canvas allowlist function was not loaded.');
    }

    return \agency_canvas_995_allowed_names();
  }

  /**
   * Calls the dynamically loaded dataset analyzer through a guarded callable.
   *
   * @return array{
   *   schema_version: int,
   *   environment: string,
   *   public_schema: string,
   *   config_values_exposed: bool,
   *   cohort_size: int,
   *   items: list<array{
   *     environment: string,
   *     config_name: string,
   *     differing_paths: list<string>,
   *     classification: string
   *   }>,
   *   summary: array{
   *     total: int,
   *     known_canvas_deterministic_drift_pattern: int,
   *     unexpected_canvas_business_path_review_required: int
   *   }
   *   }
   */
  private function analyzeDataset(string $environment, array $dataset): array {
    $name = 'agency_canvas_995_analyze_dataset';
    if (!function_exists($name)) {
      self::fail('The #995 Canvas dataset analyzer was not loaded.');
    }

    return \agency_canvas_995_analyze_dataset($environment, $dataset);
  }

  /**
   * Calls the single-config analyzer through a guarded callable.
   *
   * @param array<string, mixed> $active
   *   Active config structure.
   * @param array<string, mixed> $sync
   *   Sync config structure.
   *
   * @return array{differing_paths: list<string>, classification: string}
   *   Path-only config analysis result.
   */
  private function analyzeConfig(array $active, array $sync): array {
    $name = 'agency_canvas_995_analyze_config';
    if (!function_exists($name)) {
      self::fail('The #995 Canvas config analyzer was not loaded.');
    }

    return \agency_canvas_995_analyze_config($active, $sync);
  }

  /**
   * Asserts one integer-key rejection remains bounded and path-only.
   *
   * @param array{active: array<string, mixed>, sync: array<string, mixed>} $pair
   *   Active and sync pair.
   */
  private function assertBoundedIntKeyFailure(
    array $pair,
    string $expectedPath,
    string $rawKey,
    string $rawValue,
  ): void {
    try {
      $this->analyzeConfig($pair['active'], $pair['sync']);
      self::fail('Expected numeric Canvas map key to fail closed.');
    }
    catch (\RuntimeException $exception) {
      $message = $exception->getMessage();
      self::assertStringContainsString(
        'Unknown Canvas map key type at path ' . $expectedPath,
        $message,
      );
      self::assertStringContainsString('key_type=int', $message);
      self::assertStringNotContainsString($rawKey, $message);
      self::assertStringNotContainsString($rawValue, $message);
    }
  }

  /**
   * Produces the proven numeric-only historical Canvas version representation.
   *
   * @return array{active: array<string, mixed>, sync: array<string, mixed>}
   *   Active and sync structures.
   */
  private function numericVersionPair(): array {
    $pair = $this->knownPair();
    $syncVersion = '1234567890123456';
    $historic = $pair['active']['versioned_properties']['aaaaaaaaaaaaaaaa'];

    $pair['sync']['active_version'] = $syncVersion;
    unset($pair['active']['versioned_properties']['aaaaaaaaaaaaaaaa']);
    $pair['active']['versioned_properties'][(int) $syncVersion] = $historic;

    return $pair;
  }

  /**
   * Produces the exact 15-name synthetic dataset.
   *
   * @return array<
   *   string,
   *   array{active: array<string, mixed>, sync: array<string, mixed>}
   *   >
   */
  private function knownDataset(): array {
    $dataset = [];
    foreach ($this->allowedNames() as $name) {
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

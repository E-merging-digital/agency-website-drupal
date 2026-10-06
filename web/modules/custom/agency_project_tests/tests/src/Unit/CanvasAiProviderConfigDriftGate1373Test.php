<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #1373 Canvas provider-proof config drift gate.
 *
 * @group agency_project_tests
 * @group canvas_ai_provider_config_drift_gate_1373
 */
final class CanvasAiProviderConfigDriftGate1373Test extends TestCase {

  private const GATE =
    'scripts/runner/canvas-ai-provider-config-drift-gate-1373.php';

  /**
   * Loads the gate and its existing #995 comparator dependency.
   */
  public static function setUpBeforeClass(): void {
    require_once dirname(DRUPAL_ROOT) . '/' . self::GATE;
  }

  /**
   * The runtime cohort is exactly the Project Lead-authorized 19 names.
   */
  public function testExactNineteenNameAllowlist(): void {
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
      'canvas.component.block.system_menu_block.account',
      'canvas.component.block.system_menu_block.footer',
      'canvas.component.block.system_menu_block.main',
      'canvas.component.block.system_menu_block.tools',
      'canvas.component.block.system_powered_by_block',
      'canvas.component.block.user_login_block',
      'canvas.component.block.views_block.content_recent-block_1',
    ], $this->allowedNames());
  }

  /**
   * A twentieth config fails closed.
   */
  public function testTwentiethConfigFailsClosed(): void {
    $metadata = $this->knownMetadata();
    $metadata['items'][] = [
      'config_name' => 'canvas.component.block.webform_block',
      'state' => 'Different',
    ];

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Exact #1373 Canvas config cohort mismatch.');
    $this->analyze($metadata, $this->knownDataset());
  }

  /**
   * A missing authorized config fails closed.
   */
  public function testMissingConfigFailsClosed(): void {
    $metadata = $this->knownMetadata();
    array_pop($metadata['items']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Exact #1373 Canvas config cohort mismatch.');
    $this->analyze($metadata, $this->knownDataset());
  }

  /**
   * Missing active or sync storage for an authorized config fails closed.
   */
  public function testMissingActiveConfigFailsClosed(): void {
    $dataset = $this->knownDataset();
    $name = $this->allowedNames()[0];
    unset($dataset[$name]['active']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Missing active or sync #1373 Canvas config.',
    );
    $this->analyze($this->knownMetadata(), $dataset);
  }

  /**
   * Every authorized config must be reported as Different.
   */
  public function testNonDifferentStateFailsClosed(): void {
    $metadata = $this->knownMetadata();
    $metadata['items'][0]['state'] = 'Only in DB';

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'Every #1373 Canvas config must be Different.',
    );
    $this->analyze($metadata, $this->knownDataset());
  }

  /**
   * Known technical paths pass with path-only public evidence.
   */
  public function testKnownTechnicalPathsPassWithoutValues(): void {
    $result = $this->analyze($this->knownMetadata(), $this->knownDataset());

    self::assertSame([
      'total' => 19,
      'known' => 19,
      'unexpected' => 0,
    ], $result['summary']);
    self::assertCount(19, $result['items']);

    foreach ($result['items'] as $item) {
      self::assertSame([
        'config_name',
        'differing_paths',
        'classification',
      ], array_keys($item));
      self::assertSame(
        'KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN',
        $item['classification'],
      );
      self::assertNotSame([], $item['differing_paths']);
    }

    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    foreach ([
      'SYNC-SECRET-LABEL',
      'ACTIVE-SECRET-LABEL',
      'sync-provider-secret',
      'active-provider-secret',
      'active_value',
      'sync_value',
      'raw_yaml',
      'before',
      'after',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $encoded);
    }
  }

  /**
   * Unexpected paths are collected for all 19 items without exposing values.
   */
  public function testUnexpectedPathDoesNotAbortBoundedCollection(): void {
    $dataset = $this->knownDataset();
    $name = $this->allowedNames()[0];
    $dataset[$name]['active']['langcode'] = 'fr';
    $dataset[$name]['sync']['langcode'] = 'en';

    $result = $this->analyze($this->knownMetadata(), $dataset);

    self::assertCount(19, $result['items']);
    self::assertSame([
      'total' => 19,
      'known' => 18,
      'unexpected' => 1,
    ], $result['summary']);

    $first = $result['items'][0] ?? NULL;
    self::assertIsArray($first);
    self::assertSame($name, $first['config_name']);
    self::assertContains('langcode', $first['differing_paths']);
    self::assertSame(
      'UNEXPECTED_CANVAS_BUSINESS_PATH_REVIEW_REQUIRED',
      $first['classification'],
    );

    $encoded = json_encode($result, JSON_THROW_ON_ERROR);
    self::assertStringNotContainsString('"fr"', $encoded);
    self::assertStringNotContainsString('"en"', $encoded);
    self::assertStringNotContainsString('active_value', $encoded);
    self::assertStringNotContainsString('sync_value', $encoded);
    self::assertStringNotContainsString('raw_yaml', $encoded);
  }

  /**
   * Final provider admission remains 19/19 KNOWN and follows evidence logging.
   */
  public function testFinalWorkflowAdmissionStillFailsOnUnexpected(): void {
    $source = (string) file_get_contents(
      dirname(DRUPAL_ROOT)
      . '/.github/workflows/trusted-canvas-ai-provider-proof.yml',
    );

    $evidence = strpos($source, 'CANVAS_CONFIG_DRIFT_EVIDENCE=');
    $finalGate = strpos($source, 'CANVAS_CONFIG_DRIFT_GATE=PASS');
    $provider = strpos(
      $source,
      'npx playwright test tests/browser/canvas-ai-provider-proof.spec.mjs',
    );

    self::assertNotFalse($evidence);
    self::assertNotFalse($finalGate);
    self::assertNotFalse($provider);
    self::assertLessThan($finalGate, $evidence);
    self::assertLessThan($provider, $finalGate);
    self::assertStringContainsString(
      '.summary == {"total": 19, "known": 19, "unexpected": 0}',
      $source,
    );
    self::assertStringContainsString(
      '.classification == "KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN"',
      $source,
    );
  }

  /**
   * Unsafe dynamic keys continue to fail closed through the #995 comparator.
   */
  public function testUnsafeDynamicKeyFailsClosed(): void {
    $dataset = $this->knownDataset();
    $name = $this->allowedNames()[0];
    $dataset[$name]['active']['third_party_settings'] = [
      'Business Customer Name' => ['enabled' => TRUE],
    ];
    $dataset[$name]['sync']['third_party_settings'] = [];

    $this->expectException(\RuntimeException::class);
    $this->analyze($this->knownMetadata(), $dataset);
  }

  /**
   * #1373 adds config name while preserving bounded #995 failure metadata.
   */
  public function testNonStringKeyFailureIncludesConfigNameOnly(): void {
    $dataset = $this->knownDataset();
    $name = $this->allowedNames()[0];
    $dataset[$name]['active']['versioned_properties']['active']['settings']
      ['default_settings'][424242] = 'DO-NOT-EXPOSE-CONFIG-VALUE';

    try {
      $this->analyze($this->knownMetadata(), $dataset);
      self::fail('Expected #1373 non-string Canvas key to fail closed.');
    }
    catch (\RuntimeException $exception) {
      $message = $exception->getMessage();
      self::assertStringContainsString('Canvas config ' . $name . ':', $message);
      self::assertStringContainsString(
        'path versioned_properties.active.settings.default_settings',
        $message,
      );
      self::assertStringContainsString('key_type=int', $message);
      self::assertStringNotContainsString('424242', $message);
      self::assertStringNotContainsString(
        'DO-NOT-EXPOSE-CONFIG-VALUE',
        $message,
      );
      self::assertInstanceOf(
        \RuntimeException::class,
        $exception->getPrevious(),
      );
    }
  }

  /**
   * The support gate reuses the existing #995 comparator.
   */
  public function testExistingComparatorIsReused(): void {
    $source = (string) file_get_contents(
      dirname(DRUPAL_ROOT) . '/' . self::GATE,
    );
    self::assertStringContainsString(
      'agency_canvas_995_analyze_config(',
      $source,
    );
    self::assertStringNotContainsString(
      'function agency_canvas_1373_diff_paths',
      $source,
    );
  }

  /**
   * Returns the exact #1373 config-name allowlist.
   *
   * @return list<string>
   *   Exact #1373 config-name allowlist.
   */
  private function allowedNames(): array {
    self::assertTrue(function_exists('agency_canvas_1373_allowed_names'));
    return \agency_canvas_1373_allowed_names();
  }

  /**
   * Builds the exact metadata-only status payload.
   *
   * @return array{items: list<array{config_name: string, state: string}>}
   *   Exact metadata-only status payload.
   */
  private function knownMetadata(): array {
    return [
      'items' => array_map(
        static fn(string $name): array => [
          'config_name' => $name,
          'state' => 'Different',
        ],
        $this->allowedNames(),
      ),
    ];
  }

  /**
   * Builds the exact synthetic 19-name active/sync dataset.
   *
   * @return array<string, mixed>
   *   Exact synthetic 19-name active/sync dataset.
   */
  private function knownDataset(): array {
    $dataset = [];
    foreach ($this->allowedNames() as $name) {
      $dataset[$name] = $this->knownPair();
    }
    return $dataset;
  }

  /**
   * Builds one representative known deterministic Canvas drift pair.
   *
   * @return array{active: array<string, mixed>, sync: array<string, mixed>}
   *   Representative known deterministic Canvas drift.
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

  /**
   * Analyzes one synthetic #1373 dataset.
   *
   * @param array<string, mixed> $metadata
   *   Metadata-only status payload.
   * @param array<string, mixed> $dataset
   *   Active/sync dataset.
   *
   * @return array<string, mixed>
   *   Path-only evidence.
   */
  private function analyze(array $metadata, array $dataset): array {
    self::assertTrue(function_exists('agency_canvas_1373_analyze_dataset'));
    return \agency_canvas_1373_analyze_dataset($metadata, $dataset);
  }

}

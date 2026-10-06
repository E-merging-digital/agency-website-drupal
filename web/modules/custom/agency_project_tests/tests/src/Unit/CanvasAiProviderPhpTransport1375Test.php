<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the shell-safe #1375 Canvas PHP transport.
 *
 * @group agency_project_tests
 * @group canvas_ai_provider_php_transport_1375
 */
final class CanvasAiProviderPhpTransport1375Test extends TestCase {

  /**
   * A literal PHP variable token survives the opaque base64 transport.
   */
  public function testPhpVariableLiteralSurvivesTransport(): void {
    $php = '$prefix = "literal"; $value = $prefix . "-ok";';
    $encoded = base64_encode($php);
    $decoded = base64_decode($encoded, TRUE);

    self::assertIsString($decoded);
    self::assertSame($php, $decoded);
    self::assertStringContainsString('$prefix', $decoded);
  }

  /**
   * The workflow transports only encoded PHP through ddev exec.
   */
  public function testWorkflowUsesOpaqueEncodedTransport(): void {
    $source = $this->workflowSource();

    foreach ([
      'gate_code_b64="$(printf \'%s\' "$gate_code" | base64 -w 0)"',
      'unset gate_code',
      'AGENCY_CANVAS_1373_EXECUTE=1',
      'AGENCY_CANVAS_1373_STATUS_B64="$config_status_b64"',
      'AGENCY_CANVAS_1375_CODE_B64="$gate_code_b64"',
      'bash /var/www/html/scripts/runner/run-canvas-ai-provider-config-drift-gate-1375.sh',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringNotContainsString(
      'vendor/bin/drush php:eval "$gate_code"',
      $source,
    );
    self::assertStringNotContainsString(
      'printf \'%s\\n\' "$gate_code"',
      $source,
    );
  }

  /**
   * The container helper decodes without shell eval or source logging.
   */
  public function testHelperDecodesDirectlyIntoQuotedDrushArgument(): void {
    $source = $this->helperSource();

    foreach ([
      'code="$(printf \'%s\' "$AGENCY_CANVAS_1375_CODE_B64" | base64 -d)"',
      'unset AGENCY_CANVAS_1375_CODE_B64',
      'exec vendor/bin/drush php:eval "$code"',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    foreach (preg_split('/\R/', $source) ?: [] as $line) {
      self::assertFalse(str_starts_with(trim($line), 'eval '));
    }
    self::assertStringNotContainsString('echo "$code"', $source);
    self::assertStringNotContainsString('printf \'%s\' "$code"', $source);
  }

  /**
   * Provider execution remains after the successful #1373 drift gate.
   */
  public function testProviderRemainsAfterDriftGate(): void {
    $source = $this->workflowSource();
    $gate = strpos($source, 'CANVAS_CONFIG_DRIFT_GATE=');
    $provider = strpos(
      $source,
      'npx playwright test tests/browser/canvas-ai-provider-proof.spec.mjs',
    );

    self::assertNotFalse($gate);
    self::assertNotFalse($provider);
    self::assertLessThan($provider, $gate);
  }

  /**
   * DDEV direct and router port contracts remain unchanged.
   */
  public function testDdevPortContractRemainsUnchanged(): void {
    $source = $this->workflowSource();

    foreach ([
      'host_mailpit_port: "19025"',
      'host_webserver_port: "19080"',
      'host_db_port: "19306"',
      'host_https_port: "19443"',
      'router_http_port: "19180"',
      'router_https_port: "19444"',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }
  }

  /**
   * Returns the trusted provider workflow source.
   */
  private function workflowSource(): string {
    return (string) file_get_contents(
      dirname(DRUPAL_ROOT) . '/.github/workflows/trusted-canvas-ai-provider-proof.yml',
    );
  }

  /**
   * Returns the repository-owned transport helper source.
   */
  private function helperSource(): string {
    return (string) file_get_contents(
      dirname(DRUPAL_ROOT)
      . '/scripts/runner/run-canvas-ai-provider-config-drift-gate-1375.sh',
    );
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the normalized Agency runner health receipt producer.
 *
 * @group agency_project_tests
 * @group agency_runner_health
 */
final class AgencyRunnerHealthProbeWorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/agency-runner-health-probe.yml';

  private const RUNNER_SERVICE =
    'actions.runner.E-merging-digital-agency-website-drupal.agency-browser-runner-01.service';

  /**
   * Proves the existing schedule and exact runner identity are preserved.
   */
  public function testExistingProbeAndExactRunnerIdentityArePreserved(): void {
    $workflow = $this->parsed();
    $source = $this->source();

    $on = $workflow['on'] ?? NULL;
    self::assertIsArray($on);
    self::assertSame([['cron' => '12,42 * * * *']], $on['schedule'] ?? NULL);
    self::assertArrayHasKey('workflow_dispatch', $on);
    self::assertSame([], $workflow['permissions'] ?? NULL);

    $probe = $workflow['jobs']['probe'] ?? NULL;
    self::assertIsArray($probe);
    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency', 'ddev', 'browser'],
      $probe['runs-on'] ?? NULL,
    );

    foreach ([
      'EXPECTED_RUNNER_NAME: agency-browser-runner-01',
      'EXPECTED_HOSTNAME: preflight-runner-01',
      'EXPECTED_USER: agency-runner',
      'EXPECTED_RUNNER_SERVICE: ' . self::RUNNER_SERVICE,
      '[[ "${RUNNER_NAME:-}" == "$EXPECTED_RUNNER_NAME" ]]',
      '[[ "$(hostname)" == "$EXPECTED_HOSTNAME" ]]',
      '[[ "$(id -un)" == "$EXPECTED_USER" ]]',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }
  }

  /**
   * Proves systemd observation is exact, read-only and fail-closed.
   */
  public function testExactRunnerServiceIsObservedReadOnly(): void {
    $source = $this->source();

    foreach ([
      'systemctl show "$EXPECTED_RUNNER_SERVICE" --property=Id --value',
      'systemctl show "$EXPECTED_RUNNER_SERVICE" --property=ActiveState --value',
      'systemctl show "$EXPECTED_RUNNER_SERVICE" --property=SubState --value',
      '[[ "$service_id" == "$EXPECTED_RUNNER_SERVICE" ]]',
      'runner service is unhealthy:',
      'ActiveState',
      'SubState',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    foreach ([
      'systemctl start ',
      'systemctl stop ',
      'systemctl restart ',
      'systemctl enable ',
      'systemctl disable ',
      'systemctl daemon-reload',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }
  }

  /**
   * Proves existing warning thresholds and critical fail-fast semantics remain.
   */
  public function testRunnerClassificationPreservesExistingThresholdSemantics(): void {
    $source = $this->source();
    $receiptStart = strpos($source, 'receipt_dir="$RUNNER_TEMP/agency-runner-health-state"');
    self::assertIsInt($receiptStart);

    foreach ([
      "DISK_WARNING_PCT: '85'",
      "DISK_CRITICAL_PCT: '92'",
      "MEMORY_WARNING_AVAILABLE_PCT: '10'",
      "MEMORY_CRITICAL_AVAILABLE_PCT: '5'",
      "runner_status='healthy'",
      "runner_status='warning'",
      'fail "root filesystem is critical:',
      'fail "available memory is critical:',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    $diskCritical = strpos($source, 'fail "root filesystem is critical:');
    $memoryCritical = strpos($source, 'fail "available memory is critical:');
    self::assertIsInt($diskCritical);
    self::assertIsInt($memoryCritical);
    self::assertTrue($diskCritical < $receiptStart);
    self::assertTrue($memoryCritical < $receiptStart);
    self::assertGreaterThanOrEqual(2, substr_count($source, "runner_status='warning'"));
  }

  /**
   * Proves receipt data comes directly from same-run observations.
   */
  public function testReceiptUsesSameRunEvidenceAndNoSecondProdAuthority(): void {
    $source = $this->source();
    $receipt = $this->receiptBlock();

    foreach ([
      'stamp="$(date -u',
      'receipt_id="agency-health-$stamp"',
      '--arg runner_status "$runner_status"',
      '--arg runner_name "$RUNNER_NAME"',
      '--arg runner_host "$(hostname)"',
      '--arg service_state "$service_state"',
      '--arg service_substate "$service_substate"',
      '--argjson disk_used_pct "$disk_used_pct"',
      '--argjson memory_available_pct "$memory_available_pct"',
      'source: "same-run-self-hosted-probe"',
    ] as $required) {
      self::assertStringContainsString($required, $receipt);
    }

    foreach ([
      'schema_version: 1',
      'project_id: "agency-website"',
      'source: "compatibility-placeholder-not-authoritative"',
      'status: "unavailable"',
      'http_status: 0',
      'body_status: "unavailable"',
      'duration_seconds: 0',
    ] as $required) {
      self::assertStringContainsString($required, $receipt);
    }

    self::assertStringNotContainsString('GITHUB_STEP_SUMMARY', $receipt);
    self::assertStringNotContainsString('docker_storage', $receipt);
    self::assertStringNotContainsString('docker_root', $receipt);
    self::assertStringNotContainsString('docker_driver', $receipt);
    self::assertStringNotContainsString('curl ', $source);
    self::assertStringNotContainsString('wget ', $source);
    self::assertStringNotContainsString('https://emergingdigital.be', $source);
  }

  /**
   * Proves validation and publication fail closed before artifact upload.
   */
  public function testReceiptValidationSecretScanAndUploadFailClosed(): void {
    $workflow = $this->parsed();
    $source = $this->source();

    foreach ([
      '[[ -f "$receipt_file" && ! -L "$receipt_file" && -s "$receipt_file" ]]',
      '(( receipt_bytes <= 8192 ))',
      'jq -e \\',
      'runner health receipt contract validation failed',
      'runner health receipt failed secret scan',
      'github_pat_',
      'PRIVATE KEY',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    $steps = $workflow['jobs']['probe']['steps'] ?? [];
    self::assertCount(2, $steps);
    $upload = $steps[1] ?? NULL;
    self::assertIsArray($upload);
    self::assertSame('Upload normalized runner health receipt', $upload['name'] ?? NULL);
    self::assertSame('actions/upload-artifact@v4', $upload['uses'] ?? NULL);
    self::assertSame('agency-runner-health-state', $upload['with']['name'] ?? NULL);
    self::assertSame(
      '${{ runner.temp }}/agency-runner-health-state/agency-runner-health.json',
      $upload['with']['path'] ?? NULL,
    );
    self::assertSame('error', $upload['with']['if-no-files-found'] ?? NULL);
  }

  /**
   * Proves the existing Docker/DDEV health probe remains additive and DB-free.
   */
  public function testExistingHealthProbeBehaviorRemainsAndNoDataAccessIsAdded(): void {
    $source = $this->source();

    foreach ([
      'command -v docker >/dev/null',
      'command -v ddev >/dev/null',
      "docker info --format '{{.DockerRootDir}}'",
      "docker info --format '{{.Driver}}'",
      'docker system df --format',
      'ddev version >/dev/null',
      'Docker storage summary:',
      'known Docker privilege transition:',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    foreach (['mysql ', 'mariadb ', 'drush sql:', 'ssh ', 'scp '] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }
  }

  /**
   * Returns only the normalized receipt-construction block.
   */
  private function receiptBlock(): string {
    $source = $this->source();
    $start = strpos($source, 'receipt_dir="$RUNNER_TEMP/agency-runner-health-state"');
    $end = strpos($source, '{' . PHP_EOL . '            echo "## Agency runner health"', $start);
    self::assertIsInt($start);
    self::assertIsInt($end);

    return substr($source, $start, $end - $start);
  }

  /**
   * Parses the governed workflow.
   *
   * @return array<string, mixed>
   *   Parsed workflow definition.
   */
  private function parsed(): array {
    $parsed = Yaml::parseFile(dirname(DRUPAL_ROOT) . '/' . self::WORKFLOW);
    self::assertIsArray($parsed);

    return $parsed;
  }

  /**
   * Reads the governed workflow source.
   */
  private function source(): string {
    return (string) file_get_contents(
      dirname(DRUPAL_ROOT) . '/' . self::WORKFLOW,
    );
  }

}

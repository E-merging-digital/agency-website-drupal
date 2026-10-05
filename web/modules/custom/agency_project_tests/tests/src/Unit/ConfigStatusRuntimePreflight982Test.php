<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Protects the metadata-only #982 config drift contract.
 *
 * @group agency_project_tests
 * @group config_status_runtime_preflight_982
 */
final class ConfigStatusRuntimePreflight982Test extends TestCase {

  private const FILTER = 'scripts/runner/filter-config-status-metadata.php';

  /**
   * Drush names/states are retained while config values are discarded.
   */
  public function testRowsAreReducedToBoundedMetadata(): void {
    $input = json_encode([
      [
        'name' => 'system.site',
        'state' => 'Different',
        'value' => 'SECRET-BUSINESS-VALUE',
      ],
      [
        'name' => 'core.extension',
        'state' => 'Only in sync dir',
      ],
      [
        'name' => 'runtime.example',
        'state' => 'Only in DB',
      ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->runFilter('PREPROD', $input);

    self::assertSame(1, $result['schema_version'] ?? NULL);
    self::assertSame('PREPROD', $result['environment'] ?? NULL);
    self::assertSame(
      'environment + config_name + operation/state',
      $result['metadata_schema'] ?? NULL,
    );
    self::assertFalse($result['config_values_exposed'] ?? TRUE);
    self::assertSame([
      [
        'environment' => 'PREPROD',
        'config_name' => 'core.extension',
        'state' => 'Only in sync dir',
        'operation' => 'CREATE',
        'classification' => 'EXPECTED_REPOSITORY_DEPLOY_DRIFT',
      ],
      [
        'environment' => 'PREPROD',
        'config_name' => 'runtime.example',
        'state' => 'Only in DB',
        'operation' => 'DELETE',
        'classification' => 'UNEXPECTED_REVIEW_REQUIRED',
      ],
      [
        'environment' => 'PREPROD',
        'config_name' => 'system.site',
        'state' => 'Different',
        'operation' => 'UPDATE',
        'classification' => 'UNEXPECTED_REVIEW_REQUIRED',
      ],
    ], $result['items'] ?? NULL);
    self::assertSame('REVIEW_REQUIRED', $result['summary']['persistent_language_lock_cim_safety'] ?? NULL);
    self::assertStringNotContainsString('SECRET-BUSINESS-VALUE', json_encode($result, JSON_THROW_ON_ERROR));
  }

  /**
   * The keyed RowsOfFields representation is also accepted.
   */
  public function testKeyedRowsAndCleanResultAreSupported(): void {
    $result = $this->runFilter('PROD', json_encode([
      'system.site' => ['state' => 'Different'],
    ], JSON_THROW_ON_ERROR));
    self::assertSame('system.site', $result['items'][0]['config_name'] ?? NULL);
    self::assertSame('UPDATE', $result['items'][0]['operation'] ?? NULL);

    $clean = $this->runFilter('PROD', '');
    self::assertSame([], $clean['items'] ?? NULL);
    self::assertSame(0, $clean['summary']['total'] ?? NULL);
    self::assertSame('YES', $clean['summary']['persistent_language_lock_cim_safety'] ?? NULL);
  }

  /**
   * Unknown states fail closed rather than being normalized heuristically.
   */
  public function testUnknownStateFailsClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $process = new Process([
      PHP_BINARY,
      $root . '/' . self::FILTER,
      'PROD',
    ], $root);
    $process->setInput(json_encode([
      ['name' => 'system.site', 'state' => 'Mystery'],
    ], JSON_THROW_ON_ERROR));
    $process->run();

    self::assertFalse($process->isSuccessful());
    self::assertStringContainsString('Unsupported Drush config state', $process->getErrorOutput());
  }

  /**
   * Runs the repository-owned filter and returns its decoded result.
   *
   * @return array<string, mixed>
   *   Parsed metadata-only evidence.
   */
  private function runFilter(string $environment, string $input): array {
    $root = dirname(DRUPAL_ROOT);
    $process = new Process([
      PHP_BINARY,
      $root . '/' . self::FILTER,
      $environment,
    ], $root);
    $process->setInput($input);
    $process->run();

    self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
    $decoded = json_decode($process->getOutput(), TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($decoded);
    return $decoded;
  }

}

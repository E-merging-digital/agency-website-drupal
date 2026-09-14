<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Unit;

use Drupal\agency_operations\Service\CapabilityRegistryReader;
use Drupal\agency_operations\Service\EnvironmentDataStateProvider;
use Drupal\agency_operations\Service\RuntimeMetadataReader;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Proves environment-data state is local, non-sensitive and fail-closed.
 *
 * @group agency_operations
 */
final class EnvironmentDataStateProviderTest extends UnitTestCase {

  /**
   * Temporary PREPROD project root.
   */
  private string $fixtureRoot;

  /**
   * Temporary deployed Drupal app root.
   */
  private string $appRoot;

  /**
   * Current immutable Development Seed metadata path.
   */
  private string $seedMetadataPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->fixtureRoot = sys_get_temp_dir()
      . '/agency-environment-data-' . bin2hex(random_bytes(6));
    $release = '20260914120000-' . str_repeat('a', 40);
    $releaseRoot = $this->fixtureRoot . '/releases/' . $release;
    $this->appRoot = $releaseRoot . '/web';

    mkdir($this->appRoot, 0777, TRUE);
    mkdir($releaseRoot . '/docs/operations', 0777, TRUE);
    mkdir($releaseRoot . '/scripts/preproduction-refresh', 0777, TRUE);
    mkdir($this->fixtureRoot . '/shared/refresh-jobs/apply-1163-current-r1', 0777, TRUE);

    file_put_contents(
      $releaseRoot . '/docs/operations/execution-capabilities.md',
      "# Registry\nLast materialized: 2026-09-14\n\n"
      . "## 3. Current operational capability index\n\n"
      . "| Capability | Owner | Status | Current execution surface | Mutation/data boundary |\n"
      . "| --- | --- | --- | --- | --- |\n"
      . "| PROD -> PREPROD sanitized DB refresh | #914 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | controlled | sanitized only |\n"
      . "| GitHub-hosted metadata-only PLAN | #927 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | hosted | metadata only |\n"
      . "| Development Seed DDEV consumer | #873 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | local DDEV | pull only |\n"
      . "| Development Seed publisher/distribution | #956 | `SOURCE_IMPLEMENTED` / `REAL_EXECUTION_PROVEN` | trusted | sanitized only |\n"
      . "\n## 4. Next section\n",
    );
    file_put_contents(
      $releaseRoot . '/scripts/preproduction-refresh/sanitization-policy.json',
      json_encode(['policy_version' => 'agency-preprod-refresh-v1'], JSON_THROW_ON_ERROR),
    );
    file_put_contents(
      $this->fixtureRoot . '/shared/refresh-jobs/apply-1163-current-r1/result.env',
      "schema_version=1\n"
      . "request_id=apply-1163-current-r1\n"
      . 'main_sha=' . str_repeat('b', 40) . "\n"
      . "outcome=COMMITTED\n"
      . "detail=SANITIZED_DATABASE_ACTIVE_AND_VALIDATED\n"
      . "finished_at=2026-09-14T12:30:00Z\n",
    );

    $seedId = 'agency-development-seed-v1-seed-1163-current-r1';
    $seedRoot = $this->fixtureRoot
      . '/shared/development-seeds/immutable/' . $seedId;
    mkdir($seedRoot, 0777, TRUE);
    file_put_contents($seedRoot . '/database-mariadb_11.8.zst', 'fixture');
    $this->seedMetadataPath = $seedRoot . '/seed.json';
    file_put_contents(
      $this->seedMetadataPath,
      json_encode([
        'schema_version' => 1,
        'seed_id' => $seedId,
        'created_at' => '2026-09-14T13:00:00Z',
        'source_preprod_refresh_identity' => 'apply-1163-current-r1',
        'source_preprod_application_release_sha' => str_repeat('c', 40),
        'sanitization_policy' => [
          'id' => 'agency-development-seed-v1',
          'version' => '1',
        ],
        'database_sha256' => str_repeat('d', 64),
        'compatibility' => [
          'ddev_minimum_version' => '1.25.4',
          'database' => 'mariadb:11.8',
          'snapshot_filename' => 'database-mariadb_11.8.zst',
        ],
      ], JSON_THROW_ON_ERROR),
    );
    symlink(
      'immutable/' . $seedId,
      $this->fixtureRoot . '/shared/development-seeds/current',
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeTree($this->fixtureRoot);
    parent::tearDown();
  }

  /**
   * Proves current PREPROD refresh and seed lineage are projected locally.
   */
  public function testPreprodCurrentStateUsesExistingLocalEvidence(): void {
    $provider = $this->providerForHost('preprod.emergingdigital.be');

    $state = $provider->read();

    self::assertSame(1, $state['schema_version']);
    self::assertSame('agency-website', $state['project_id']);
    self::assertSame('drupal', $state['application_type']);
    self::assertSame('PREPROD', $state['runtime']['environment']);
    self::assertTrue($state['preprod']['available']);
    self::assertSame('current', $state['preprod']['status']);
    self::assertSame('apply-1163-current-r1', $state['preprod']['refresh_id']);
    self::assertSame('2026-09-14T12:30:00Z', $state['preprod']['last_refresh_completed_at']);
    self::assertNull($state['preprod']['source_prod_release']);
    self::assertSame('agency-preprod-refresh-v1', $state['preprod']['sanitization']['configured_version']);
    self::assertFalse($state['preprod']['sanitization']['bound_to_current_refresh']);

    self::assertTrue($state['development']['available']);
    self::assertSame('current', $state['development']['status']);
    self::assertSame('2026-09-14T13:00:00Z', $state['development']['last_seed_published_at']);
    self::assertSame('apply-1163-current-r1', $state['development']['source_preprod_refresh']);
    self::assertSame(str_repeat('d', 64), $state['development']['database_sha256']);

    self::assertTrue($state['capabilities']['plan_refresh']['supported']);
    self::assertTrue($state['capabilities']['refresh_preprod']['supported']);
    self::assertTrue($state['capabilities']['publish_dev_seed']['supported']);
    self::assertTrue($state['capabilities']['consume_dev_seed']['supported']);
  }

  /**
   * Proves untrusted seed metadata is not projected as usable state.
   */
  public function testInvalidSeedMetadataFailsClosedWithoutAffectingRefresh(): void {
    file_put_contents(
      $this->seedMetadataPath,
      json_encode([
        'schema_version' => 1,
        'seed_id' => 'invalid',
        'created_at' => 'not-a-timestamp',
      ], JSON_THROW_ON_ERROR),
    );

    $state = $this->providerForHost('preprod.emergingdigital.be')->read();

    self::assertTrue($state['preprod']['available']);
    self::assertFalse($state['development']['available']);
    self::assertSame('unavailable', $state['development']['status']);
    self::assertNull($state['development']['seed_id']);
    self::assertNull($state['development']['database_sha256']);
  }

  /**
   * Creates the real readers against the isolated fixture filesystem.
   */
  private function providerForHost(string $host): EnvironmentDataStateProvider {
    $stack = new RequestStack();
    $stack->push(Request::create('https://' . $host . '/admin/agency/operations'));

    return new EnvironmentDataStateProvider(
      $this->appRoot,
      new RuntimeMetadataReader($this->appRoot, $stack),
      new CapabilityRegistryReader($this->appRoot),
    );
  }

  /**
   * Removes the isolated fixture tree without following symlinks.
   */
  private function removeTree(string $path): void {
    if (is_link($path) || is_file($path)) {
      @unlink($path);
      return;
    }
    if (!is_dir($path)) {
      return;
    }

    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
  }

}

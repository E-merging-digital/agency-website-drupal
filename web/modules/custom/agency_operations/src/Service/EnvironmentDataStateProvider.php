<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Service;

/**
 * Projects non-sensitive environment-data state from existing local evidence.
 */
final class EnvironmentDataStateProvider {

  private const ADAPTER_ID = 'agency-drupal-environment-data';

  private const ADAPTER_VERSION = '1';

  private const PREPROD_POLICY_ID = 'agency-preprod-refresh';

  private const CAPABILITIES = [
    'plan_refresh' => 'GitHub-hosted metadata-only PLAN',
    'refresh_preprod' => 'PROD -> PREPROD sanitized DB refresh',
    'publish_dev_seed' => 'Development Seed publisher/distribution',
    'consume_dev_seed' => 'Development Seed DDEV consumer',
  ];

  public function __construct(
    private readonly string $appRoot,
    private readonly RuntimeMetadataReader $runtimeMetadata,
    private readonly CapabilityRegistryReader $capabilityRegistry,
  ) {}

  /**
   * Returns the normalized state without reading any application database.
   *
   * @return array<string, mixed>
   *   Non-sensitive adapter state.
   */
  public function read(): array {
    $runtime = $this->runtimeMetadata->read();
    $registry = $this->capabilityRegistry->read();

    $state = [
      'schema_version' => 1,
      'project_id' => 'agency-website',
      'application_type' => 'drupal',
      'adapter' => [
        'id' => self::ADAPTER_ID,
        'version' => self::ADAPTER_VERSION,
      ],
      'runtime' => $runtime,
      'environments' => $this->environmentProjection($runtime),
      'preprod' => $this->unavailablePreprod(),
      'development' => $this->unavailableDevelopment(),
      'capabilities' => $this->capabilityProjection($registry),
    ];

    if ($runtime['environment'] !== 'PREPROD') {
      return $state;
    }

    $projectRoot = $this->preprodProjectRoot();
    if ($projectRoot === NULL) {
      return $state;
    }

    $refresh = $this->readCurrentRefresh($projectRoot);
    $state['preprod'] = $refresh;
    $state['development'] = $this->readCurrentSeed($projectRoot, $refresh);

    return $state;
  }

  /**
   * Projects the current runtime only; other environments are not inferred.
   */
  private function environmentProjection(array $runtime): array {
    $environments = [];
    foreach (['PROD', 'PREPROD', 'DEVELOPMENT'] as $environment) {
      $observed = $runtime['environment'] === $environment;
      $environments[$environment] = [
        'availability' => $observed ? 'observed' : 'not_observed_by_current_runtime',
        'release_identity' => $observed && !empty($runtime['release_available'])
          ? $runtime['release_identity']
          : NULL,
        'release_sha_prefix' => $observed && !empty($runtime['release_available'])
          ? $runtime['release_sha_prefix']
          : NULL,
        'deployed_at' => $observed && !empty($runtime['release_available'])
          ? $runtime['deployed_at']
          : NULL,
      ];
    }

    return $environments;
  }

  /**
   * Maps the existing capability registry to stable cockpit action IDs.
   */
  private function capabilityProjection(array $registry): array {
    $byName = [];
    foreach (($registry['groups'] ?? []) as $capabilities) {
      foreach ($capabilities as $capability) {
        if (is_array($capability) && is_string($capability['name'] ?? NULL)) {
          $byName[$capability['name']] = $capability;
        }
      }
    }

    $result = [];
    foreach (self::CAPABILITIES as $id => $name) {
      $capability = $byName[$name] ?? NULL;
      $humanStatus = is_array($capability)
        ? ($capability['human_status_key'] ?? CapabilityRegistryReader::STATUS_UNAVAILABLE)
        : CapabilityRegistryReader::STATUS_UNAVAILABLE;
      $result[$id] = [
        'supported' => is_array($capability),
        'status' => is_array($capability) ? ($capability['status'] ?? NULL) : NULL,
        'human_status_key' => $humanStatus,
        'owner' => is_array($capability) ? ($capability['owner'] ?? NULL) : NULL,
        'surface' => is_array($capability) ? ($capability['surface'] ?? NULL) : NULL,
      ];
    }

    return $result;
  }

  /**
   * Resolves the fixed PREPROD project root from the deployed release path.
   */
  private function preprodProjectRoot(): ?string {
    $realAppRoot = realpath($this->appRoot);
    if ($realAppRoot === FALSE) {
      return NULL;
    }

    $releaseRoot = dirname($realAppRoot);
    $releasesRoot = dirname($releaseRoot);
    if (basename($releasesRoot) !== 'releases') {
      return NULL;
    }

    $projectRoot = dirname($releasesRoot);
    $shared = $projectRoot . '/shared';
    if (!is_dir($shared) || is_link($shared)) {
      return NULL;
    }

    return $projectRoot;
  }

  /**
   * Resolves current sanitized PREPROD identity from durable terminal results.
   */
  private function readCurrentRefresh(string $projectRoot): array {
    $jobs = $projectRoot . '/shared/refresh-jobs';
    if (!is_dir($jobs) || is_link($jobs)) {
      return $this->unavailablePreprod();
    }

    $records = [];
    foreach (glob($jobs . '/*/result.env') ?: [] as $path) {
      if (!is_file($path) || is_link($path) || !is_readable($path)) {
        continue;
      }
      $record = $this->readRefreshResult($path);
      if ($record !== NULL) {
        $records[] = $record;
      }
    }

    usort(
      $records,
      static fn (array $a, array $b): int => strcmp($b['finished_at'], $a['finished_at']),
    );

    foreach ($records as $record) {
      if ($record['outcome'] === 'HUMAN_RECOVERY_REQUIRED') {
        return [
          'available' => FALSE,
          'status' => 'human_recovery_required',
          'refresh_id' => $record['request_id'],
          'last_refresh_completed_at' => $record['finished_at'],
          'source_prod_release' => NULL,
          'main_sha' => $record['main_sha'],
          'sanitization' => $this->configuredPreprodPolicy(FALSE),
          'latest_receipt_ref' => 'preprod-refresh:' . $record['request_id'],
        ];
      }

      if ($record['outcome'] === 'COMMITTED') {
        if ($record['detail'] !== 'SANITIZED_DATABASE_ACTIVE_AND_VALIDATED') {
          return $this->unavailablePreprod();
        }

        return [
          'available' => TRUE,
          'status' => 'current',
          'refresh_id' => $record['request_id'],
          'last_refresh_completed_at' => $record['finished_at'],
          'source_prod_release' => NULL,
          'main_sha' => $record['main_sha'],
          'sanitization' => $this->configuredPreprodPolicy(FALSE),
          'latest_receipt_ref' => 'preprod-refresh:' . $record['request_id'],
        ];
      }

      if ($record['outcome'] === 'ROLLED_BACK'
        && !str_starts_with($record['detail'], 'NO_PREPROD_RUNTIME_MUTATION')
        && $record['detail'] !== 'EXACT_BACKUP_OR_UNCHANGED_RUNTIME_PROVEN') {
        return $this->unavailablePreprod();
      }
    }

    return $this->unavailablePreprod();
  }

  /**
   * Parses one fixed-schema refresh terminal result.
   */
  private function readRefreshResult(string $path): ?array {
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
      if (preg_match('/^(?<key>[a-z_]+)=(?<value>[^\r\n]*)$/', $line, $matches) !== 1) {
        return NULL;
      }
      if (array_key_exists($matches['key'], $values)) {
        return NULL;
      }
      $values[$matches['key']] = $matches['value'];
    }

    $expected = ['schema_version', 'request_id', 'main_sha', 'outcome', 'detail', 'finished_at'];
    $keys = array_keys($values);
    sort($keys);
    sort($expected);
    if ($keys !== $expected
      || $values['schema_version'] !== '1'
      || preg_match('/^[A-Za-z0-9._-]{8,80}$/', $values['request_id']) !== 1
      || preg_match('/^[0-9a-f]{40}$/', $values['main_sha']) !== 1
      || !in_array($values['outcome'], ['COMMITTED', 'ROLLED_BACK', 'HUMAN_RECOVERY_REQUIRED'], TRUE)
      || preg_match('/^[A-Z0-9_]+$/', $values['detail']) !== 1
      || !$this->isUtcTimestamp($values['finished_at'])) {
      return NULL;
    }

    return $values;
  }

  /**
   * Reads the immutable current Development Seed metadata on PREPROD.
   */
  private function readCurrentSeed(string $projectRoot, array $refresh): array {
    $root = $projectRoot . '/shared/development-seeds';
    $immutable = realpath($root . '/immutable');
    $current = $root . '/current';
    if ($immutable === FALSE || !is_dir($immutable) || is_link($immutable)
      || !is_link($current)) {
      return $this->unavailableDevelopment();
    }

    $target = realpath($current);
    if ($target === FALSE
      || !str_starts_with($target, $immutable . DIRECTORY_SEPARATOR)
      || !is_dir($target)) {
      return $this->unavailableDevelopment();
    }

    $metadataPath = $target . '/seed.json';
    if (!is_file($metadataPath) || is_link($metadataPath) || !is_readable($metadataPath)) {
      return $this->unavailableDevelopment();
    }

    try {
      $metadata = json_decode(
        (string) file_get_contents($metadataPath),
        TRUE,
        32,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException) {
      return $this->unavailableDevelopment();
    }

    if (!is_array($metadata) || !$this->validSeedMetadata($metadata)) {
      return $this->unavailableDevelopment();
    }

    $sourceRefresh = $metadata['source_preprod_refresh_identity'];
    $freshness = !empty($refresh['available'])
      && ($refresh['refresh_id'] ?? NULL) === $sourceRefresh
        ? 'current'
        : 'stale';

    return [
      'available' => TRUE,
      'status' => $freshness,
      'last_seed_published_at' => $metadata['created_at'],
      'seed_id' => $metadata['seed_id'],
      'database_sha256' => $metadata['database_sha256'],
      'source_preprod_refresh' => $sourceRefresh,
      'source_preprod_release' => $metadata['source_preprod_application_release_sha'],
      'sanitization_policy' => $metadata['sanitization_policy'],
      'compatibility' => $metadata['compatibility'],
      'latest_receipt_ref' => 'development-seed:' . $metadata['seed_id'],
    ];
  }

  /**
   * Validates the non-sensitive immutable seed metadata contract.
   */
  private function validSeedMetadata(array $metadata): bool {
    return ($metadata['schema_version'] ?? NULL) === 1
      && is_string($metadata['seed_id'] ?? NULL)
      && preg_match('/^agency-development-seed-v1-[A-Za-z0-9._-]+$/', $metadata['seed_id']) === 1
      && $this->isUtcTimestamp($metadata['created_at'] ?? NULL)
      && is_string($metadata['source_preprod_refresh_identity'] ?? NULL)
      && preg_match('/^[A-Za-z0-9._:-]+$/', $metadata['source_preprod_refresh_identity']) === 1
      && is_string($metadata['source_preprod_application_release_sha'] ?? NULL)
      && preg_match('/^[0-9a-f]{40}$/', $metadata['source_preprod_application_release_sha']) === 1
      && is_array($metadata['sanitization_policy'] ?? NULL)
      && ($metadata['sanitization_policy']['id'] ?? NULL) === 'agency-development-seed-v1'
      && is_string($metadata['sanitization_policy']['version'] ?? NULL)
      && is_string($metadata['database_sha256'] ?? NULL)
      && preg_match('/^[0-9a-f]{64}$/', $metadata['database_sha256']) === 1
      && is_array($metadata['compatibility'] ?? NULL)
      && ($metadata['compatibility']['database'] ?? NULL) === 'mariadb:11.8'
      && ($metadata['compatibility']['snapshot_filename'] ?? NULL) === 'database-mariadb_11.8.zst';
  }

  /**
   * Reads current configured PREPROD sanitization policy identity only.
   */
  private function configuredPreprodPolicy(bool $boundToRefresh): array {
    $path = dirname($this->appRoot)
      . '/scripts/preproduction-refresh/sanitization-policy.json';
    $version = NULL;
    if (is_file($path) && !is_link($path) && is_readable($path)) {
      try {
        $decoded = json_decode(
          (string) file_get_contents($path),
          TRUE,
          32,
          JSON_THROW_ON_ERROR,
        );
        if (is_array($decoded) && is_string($decoded['policy_version'] ?? NULL)) {
          $version = $decoded['policy_version'];
        }
      }
      catch (\JsonException) {
        $version = NULL;
      }
    }

    return [
      'id' => self::PREPROD_POLICY_ID,
      'configured_version' => $version,
      'bound_to_current_refresh' => $boundToRefresh,
    ];
  }

  /**
   * Returns an explicit fail-closed PREPROD state.
   */
  private function unavailablePreprod(): array {
    return [
      'available' => FALSE,
      'status' => 'unavailable',
      'refresh_id' => NULL,
      'last_refresh_completed_at' => NULL,
      'source_prod_release' => NULL,
      'main_sha' => NULL,
      'sanitization' => $this->configuredPreprodPolicy(FALSE),
      'latest_receipt_ref' => NULL,
    ];
  }

  /**
   * Returns an explicit fail-closed Development Seed state.
   */
  private function unavailableDevelopment(): array {
    return [
      'available' => FALSE,
      'status' => 'unavailable',
      'last_seed_published_at' => NULL,
      'seed_id' => NULL,
      'database_sha256' => NULL,
      'source_preprod_refresh' => NULL,
      'source_preprod_release' => NULL,
      'sanitization_policy' => NULL,
      'compatibility' => NULL,
      'latest_receipt_ref' => NULL,
    ];
  }

  /**
   * Validates canonical UTC seconds timestamps.
   */
  private function isUtcTimestamp(mixed $value): bool {
    if (!is_string($value)
      || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
      return FALSE;
    }

    $date = \DateTimeImmutable::createFromFormat(
      '!Y-m-d\TH:i:s\Z',
      $value,
      new \DateTimeZone('UTC'),
    );

    return $date !== FALSE && $date->format('Y-m-d\TH:i:s\Z') === $value;
  }

}

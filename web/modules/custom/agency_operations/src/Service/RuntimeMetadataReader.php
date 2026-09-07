<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Service;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reads metadata exposed by the currently executing Drupal runtime only.
 */
final class RuntimeMetadataReader {

  public function __construct(
    private readonly string $appRoot,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Returns current-runtime metadata without querying another environment.
   *
   * @return array{
   *   environment: string,
   *   release_available: bool,
   *   release_identity: ?string,
   *   release_sha_prefix: ?string,
   *   deployed_at: ?string,
   *   source: string,
   *   authority: string,
   *   freshness: string
   * }
   *   Safe presentation metadata.
   */
  public function read(): array {
    $request = $this->requestStack->getCurrentRequest();
    $host = strtolower($request?->getHost() ?? '');
    $environment = $this->environmentForHost($host);
    $projectRoot = realpath(dirname($this->appRoot));

    $releaseIdentity = NULL;
    $releaseShaPrefix = NULL;
    $deployedAt = NULL;
    if ($projectRoot !== FALSE) {
      $candidate = basename($projectRoot);
      if (
        preg_match(
          '/^(?<timestamp>[0-9]{14})-(?<sha>[0-9a-f]{12,40})$/',
          $candidate,
          $matches,
        ) === 1
      ) {
        $releaseIdentity = $candidate;
        $releaseShaPrefix = $matches['sha'];
        $deployedAt = $matches['timestamp'];
      }
    }

    return [
      'environment' => $environment,
      'release_available' => $releaseIdentity !== NULL,
      'release_identity' => $releaseIdentity,
      'release_sha_prefix' => $releaseShaPrefix,
      'deployed_at' => $deployedAt,
      'source' => 'current Drupal runtime release path',
      'authority' => 'local server filesystem + current HTTP host',
      'freshness' => 'current request',
    ];
  }

  /**
   * Maps the current request host to the environment served by this runtime.
   */
  private function environmentForHost(string $host): string {
    if ($host === 'preprod.emergingdigital.be') {
      return 'PREPROD';
    }
    if ($host === 'emergingdigital.be' || $host === 'www.emergingdigital.be') {
      return 'PROD';
    }

    return 'DEVELOPMENT';
  }

}

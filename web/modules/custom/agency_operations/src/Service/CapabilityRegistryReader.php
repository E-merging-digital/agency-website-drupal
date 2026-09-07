<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Service;

/**
 * Read-only adapter over the existing authoritative capability Markdown.
 */
final class CapabilityRegistryReader {

  public const REGISTRY_RELATIVE_PATH = 'docs/operations/execution-capabilities.md';

  private const GROUPS = [
    'CODE_CONFIG',
    'DATA_REFRESH',
    'EDITORIAL',
    'DEVELOPMENT_DATA',
  ];

  public function __construct(
    private readonly string $appRoot,
  ) {}

  /**
   * Reads the existing registry without persisting a second source of truth.
   *
   * @return array{available: bool, path: string, last_materialized: ?string, groups: array<string, array<int, array<string, string>>>}
   *   Parsed presentation model.
   */
  public function read(): array {
    $groups = array_fill_keys(self::GROUPS, []);
    $path = dirname($this->appRoot) . DIRECTORY_SEPARATOR . self::REGISTRY_RELATIVE_PATH;

    if (!is_readable($path)) {
      return [
        'available' => FALSE,
        'path' => self::REGISTRY_RELATIVE_PATH,
        'last_materialized' => NULL,
        'groups' => $groups,
      ];
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      return [
        'available' => FALSE,
        'path' => self::REGISTRY_RELATIVE_PATH,
        'last_materialized' => NULL,
        'groups' => $groups,
      ];
    }

    $lastMaterialized = NULL;
    $inIndex = FALSE;
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
      $trimmed = trim($line);
      if (str_starts_with($trimmed, 'Last materialized:')) {
        $lastMaterialized = trim(substr($trimmed, strlen('Last materialized:')));
      }
      if ($trimmed === '## 3. Current operational capability index') {
        $inIndex = TRUE;
        continue;
      }
      if ($inIndex && str_starts_with($trimmed, '## ')) {
        break;
      }
      if (!$inIndex || !str_starts_with($trimmed, '|')) {
        continue;
      }

      $cells = array_map('trim', explode('|', trim($trimmed, '|')));
      if (
        count($cells) < 5
        || $cells[0] === 'Capability'
        || preg_match('/^-+$/', str_replace(' ', '', $cells[0]))
      ) {
        continue;
      }

      $capability = $this->cleanCell($cells[0]);
      $status = $this->cleanCell($cells[2]);
      $group = $this->groupForCapability($capability);
      $groups[$group][] = [
        'name' => $capability,
        'owner' => $this->cleanCell($cells[1]),
        'status' => $status,
        'human_status' => $this->humanStatus($status),
        'surface' => $this->cleanCell($cells[3]),
        'scope' => $this->cleanCell($cells[4]),
      ];
    }

    return [
      'available' => TRUE,
      'path' => self::REGISTRY_RELATIVE_PATH,
      'last_materialized' => $lastMaterialized,
      'groups' => $groups,
    ];
  }

  /**
   * Derives a deliberately small human vocabulary from technical truth.
   */
  private function humanStatus(string $status): string {
    $normalized = strtoupper($status);

    if (str_contains($normalized, 'HUMAN_RECOVERY_REQUIRED')) {
      return 'Action humaine requise';
    }
    if (str_contains($normalized, 'BLOCKED')) {
      return 'Bloqué';
    }
    if (str_contains($normalized, 'REAL_EXECUTION_PROVEN')) {
      return 'Opérationnel';
    }
    if (
      str_contains($normalized, 'EXECUTION_PENDING')
      || str_contains($normalized, 'DESIGN_ONLY')
    ) {
      return 'En préparation';
    }
    if (
      str_contains($normalized, 'EXECUTABLE')
      || str_contains($normalized, 'PROVISIONED')
      || str_contains($normalized, 'SOURCE_IMPLEMENTED')
    ) {
      return 'Prêt';
    }
    if (str_contains($normalized, 'SYNTHETICALLY_PROVEN')) {
      return 'En préparation';
    }

    return 'Indisponible';
  }

  /**
   * Removes Markdown code delimiters from one presentation cell.
   */
  private function cleanCell(string $value): string {
    return trim(str_replace('`', '', $value));
  }

  /**
   * Classifies one capability into the four cockpit presentation groups.
   */
  private function groupForCapability(string $capability): string {
    $normalized = strtolower($capability);

    if (str_contains($normalized, 'development seed')) {
      return 'DEVELOPMENT_DATA';
    }
    if (str_contains($normalized, 'editorial') || str_contains($normalized, 'article')) {
      return 'EDITORIAL';
    }
    if (
      str_contains($normalized, 'refresh')
      || str_contains($normalized, 'metadata-only plan')
      || str_contains($normalized, 'server-to-server apply')
    ) {
      return 'DATA_REFRESH';
    }

    return 'CODE_CONFIG';
  }

}

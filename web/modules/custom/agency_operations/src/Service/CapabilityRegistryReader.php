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
   * @return array{available: bool, path: string, groups: array<string, array<int, array<string, string>>>}
   *   Parsed presentation model.
   */
  public function read(): array {
    $groups = array_fill_keys(self::GROUPS, []);
    $path = dirname($this->appRoot) . DIRECTORY_SEPARATOR . self::REGISTRY_RELATIVE_PATH;

    if (!is_readable($path)) {
      return [
        'available' => FALSE,
        'path' => self::REGISTRY_RELATIVE_PATH,
        'groups' => $groups,
      ];
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      return [
        'available' => FALSE,
        'path' => self::REGISTRY_RELATIVE_PATH,
        'groups' => $groups,
      ];
    }

    $inIndex = FALSE;
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
      $trimmed = trim($line);
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
      $group = $this->groupForCapability($capability);
      $groups[$group][] = [
        'name' => $capability,
        'owner' => $this->cleanCell($cells[1]),
        'status' => $this->cleanCell($cells[2]),
        'surface' => $this->cleanCell($cells[3]),
        'scope' => $this->cleanCell($cells[4]),
      ];
    }

    return [
      'available' => TRUE,
      'path' => self::REGISTRY_RELATIVE_PATH,
      'groups' => $groups,
    ];
  }

  /**
   * Removes Markdown code delimiters from one presentation cell.
   */
  private function cleanCell(string $value): string {
    return trim(str_replace('`', '', $value));
  }

  /**
   * Classifies one existing capability into the four cockpit presentation groups.
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

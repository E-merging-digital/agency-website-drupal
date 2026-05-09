<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync\Catalog;

/**
 * Read-only representation of the versioned content catalog.
 */
final class ContentSyncCatalog {

  /**
   * Absolute path to the catalog directory.
   */
  private readonly string $basePath;

  /**
   * Catalog entries.
   *
   * @var \Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalogEntry[]
   */
  private readonly array $entries;

  /**
   * Constructs a Content Sync catalog.
   *
   * @param string $basePath
   *   Absolute path to the catalog directory.
   * @param \Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalogEntry[] $entries
   *   Catalog entries.
   */
  public function __construct(
    string $basePath,
    array $entries,
  ) {
    $this->basePath = $basePath;
    $this->entries = $entries;
  }

  /**
   * Returns the catalog directory absolute path.
   */
  public function basePath(): string {
    return $this->basePath;
  }

  /**
   * Returns all catalog entries.
   *
   * @return \Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalogEntry[]
   *   Catalog entries.
   */
  public function entries(): array {
    return $this->entries;
  }

  /**
   * Returns one catalog entry by business identifier.
   */
  public function get(string $id): ?ContentSyncCatalogEntry {
    foreach ($this->entries as $entry) {
      if ($entry->id() === $id) {
        return $entry;
      }
    }

    return NULL;
  }

  /**
   * Counts catalog entries.
   */
  public function count(): int {
    return count($this->entries);
  }

}

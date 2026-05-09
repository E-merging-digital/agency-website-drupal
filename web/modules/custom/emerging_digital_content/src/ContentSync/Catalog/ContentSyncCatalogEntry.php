<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync\Catalog;

/**
 * Read-only value object for one catalog entry.
 */
final class ContentSyncCatalogEntry {

  /**
   * Loaded content definition.
   *
   * @var array<string, mixed>
   */
  private readonly array $definition;

  /**
   * Zero-based entry position in the catalog.
   */
  private readonly int $index;

  /**
   * Definition file path declared by the catalog, if any.
   */
  private readonly ?string $definitionFile;

  /**
   * Constructs a Content Sync catalog entry.
   *
   * @param array<string, mixed> $definition
   *   Loaded content definition.
   * @param int $index
   *   Zero-based entry position in the catalog.
   * @param string|null $definitionFile
   *   Definition file path declared by the catalog, if any.
   */
  public function __construct(
    array $definition,
    int $index,
    ?string $definitionFile,
  ) {
    $this->definition = $definition;
    $this->index = $index;
    $this->definitionFile = $definitionFile;
  }

  /**
   * Returns the business identifier.
   */
  public function id(): string {
    return (string) ($this->definition['id'] ?? '');
  }

  /**
   * Returns the catalog position.
   */
  public function index(): int {
    return $this->index;
  }

  /**
   * Returns the optional definition file path declared by the catalog.
   */
  public function definitionFile(): ?string {
    return $this->definitionFile;
  }

  /**
   * Returns the merged definition.
   *
   * @return array<string, mixed>
   *   Loaded content definition.
   */
  public function toArray(): array {
    return $this->definition;
  }

}

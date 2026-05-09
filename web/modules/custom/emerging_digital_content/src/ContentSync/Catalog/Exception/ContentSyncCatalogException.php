<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync\Catalog\Exception;

/**
 * Exception thrown when the Content Sync catalog cannot be read.
 */
final class ContentSyncCatalogException extends \RuntimeException {

  /**
   * Creates an exception for a missing catalog file.
   */
  public static function missingCatalog(string $path): self {
    return new self(sprintf('Content Sync catalog file not found: %s.', $path));
  }

  /**
   * Creates an exception for an unreadable catalog file.
   */
  public static function unreadableCatalog(string $path): self {
    return new self(sprintf('Content Sync catalog file is not readable: %s.', $path));
  }

  /**
   * Creates an exception for invalid YAML content.
   */
  public static function invalidYaml(string $path, \Throwable $previous): self {
    return new self(
      sprintf('Content Sync YAML is invalid in %s: %s', $path, $previous->getMessage()),
      0,
      $previous,
    );
  }

  /**
   * Creates an exception for an invalid catalog structure.
   */
  public static function invalidStructure(string $path): self {
    return new self(sprintf('Content Sync catalog structure is invalid in %s.', $path));
  }

}

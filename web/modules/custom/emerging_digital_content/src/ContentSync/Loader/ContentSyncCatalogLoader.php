<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync\Loader;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalog;
use Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalogEntry;
use Drupal\emerging_digital_content\ContentSync\Catalog\Exception\ContentSyncCatalogException;

/**
 * Loads the versioned Content Sync catalog from YAML files.
 */
final class ContentSyncCatalogLoader {

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly string $appRoot,
  ) {
  }

  /**
   * Loads the catalog and any declared per-content definition files.
   */
  public function load(): ContentSyncCatalog {
    $base_path = $this->appRoot . '/'
      . $this->moduleExtensionList->getPath('emerging_digital_content')
      . '/content_sync';
    $catalog_path = $base_path . '/catalog.yml';
    $catalog_data = $this->decodeYamlFile($catalog_path, TRUE);

    if (!isset($catalog_data['contents']) || !is_array($catalog_data['contents'])) {
      throw ContentSyncCatalogException::invalidStructure($catalog_path);
    }

    $entries = [];
    foreach ($catalog_data['contents'] as $index => $entry_data) {
      if (!is_array($entry_data)) {
        $entry_data = [];
      }

      $definition_file = isset($entry_data['file']) && is_string($entry_data['file'])
        ? $entry_data['file']
        : NULL;
      $definition_data = [];

      if ($definition_file !== NULL && $this->isSafeDefinitionFile($definition_file)) {
        $definition_path = $base_path . '/' . $definition_file;
        if (is_file($definition_path) && is_readable($definition_path)) {
          $definition_data = $this->decodeYamlFile($definition_path, TRUE);
        }
      }

      $definition = NestedArray::mergeDeep($definition_data, $entry_data);
      $entries[] = new ContentSyncCatalogEntry($definition, (int) $index, $definition_file);
    }

    return new ContentSyncCatalog($base_path, $entries);
  }

  /**
   * Decodes a YAML file and returns an array.
   *
   * @return array<string, mixed>
   *   Decoded YAML data.
   */
  private function decodeYamlFile(string $path, bool $required): array {
    if (!is_file($path)) {
      if ($required) {
        throw ContentSyncCatalogException::missingCatalog($path);
      }
      return [];
    }

    if (!is_readable($path)) {
      throw ContentSyncCatalogException::unreadableCatalog($path);
    }

    try {
      $data = Yaml::decode((string) file_get_contents($path));
    }
    catch (InvalidDataTypeException $exception) {
      throw ContentSyncCatalogException::invalidYaml($path, $exception);
    }

    if (!is_array($data)) {
      throw ContentSyncCatalogException::invalidStructure($path);
    }

    return $data;
  }

  /**
   * Checks that a declared file remains below the catalog directory.
   */
  private function isSafeDefinitionFile(string $definition_file): bool {
    return !str_starts_with($definition_file, '/')
      && !str_contains($definition_file, '\\')
      && !str_contains($definition_file, '..');
  }

}

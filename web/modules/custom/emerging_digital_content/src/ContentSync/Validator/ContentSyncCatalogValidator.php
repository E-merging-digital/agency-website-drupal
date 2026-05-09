<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync\Validator;

use Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalog;
use Drupal\emerging_digital_content\ContentSync\Catalog\ContentSyncCatalogEntry;

/**
 * Performs minimal schema validation for the Content Sync catalog.
 */
final class ContentSyncCatalogValidator {

  /**
   * Required catalog keys.
   */
  private const REQUIRED_KEYS = [
    'id',
    'entity_type',
    'bundle',
    'translations',
  ];

  /**
   * Validates a loaded catalog.
   *
   * @return array<string, mixed>
   *   Structured validation report.
   */
  public function validate(ContentSyncCatalog $catalog): array {
    $report = [
      'contents_found' => $catalog->count(),
      'valid_contents' => [],
      'invalid_contents' => [],
      'warnings' => [],
      'errors' => [],
    ];

    $seen_ids = [];
    foreach ($catalog->entries() as $entry) {
      $definition = $entry->toArray();
      $id = $entry->id();
      $entry_errors = $this->validateEntry($entry, $catalog->basePath());

      if ($id === '') {
        $entry_errors[] = sprintf('Entry #%d has no readable id.', $entry->index());
      }
      elseif (isset($seen_ids[$id])) {
        $entry_errors[] = sprintf('Duplicate content id "%s".', $id);
      }
      else {
        $seen_ids[$id] = TRUE;
      }

      if ($entry_errors !== []) {
        $invalid_id = $id !== ''
          ? sprintf('%s#%d', $id, $entry->index())
          : sprintf('__entry_%d', $entry->index());
        $report['invalid_contents'][$invalid_id] = $entry_errors;
        foreach ($entry_errors as $entry_error) {
          $report['errors'][] = $entry_error;
        }
        continue;
      }

      $report['valid_contents'][] = [
        'id' => $id,
        'entity_type' => $definition['entity_type'],
        'bundle' => $definition['bundle'],
        'translations' => array_keys($definition['translations']),
        'file' => $entry->definitionFile(),
      ];
    }

    if ($catalog->count() === 0) {
      $report['warnings'][] = 'The Content Sync catalog is empty.';
    }

    return $report;
  }

  /**
   * Validates one catalog entry.
   *
   * @return string[]
   *   Validation errors.
   */
  private function validateEntry(ContentSyncCatalogEntry $entry, string $base_path): array {
    $definition = $entry->toArray();
    $errors = [];

    foreach (self::REQUIRED_KEYS as $key) {
      if (!array_key_exists($key, $definition)) {
        $errors[] = sprintf('Content "%s" is missing required key "%s".', $entry->id(), $key);
      }
    }

    foreach (['id', 'entity_type', 'bundle'] as $string_key) {
      if (
        array_key_exists($string_key, $definition)
        && (!is_string($definition[$string_key]) || $definition[$string_key] === '')
      ) {
        $errors[] = sprintf(
          'Content "%s" key "%s" must be a non-empty string.',
          $entry->id(),
          $string_key,
        );
      }
    }

    if (isset($definition['translations']) && !is_array($definition['translations'])) {
      $errors[] = sprintf('Content "%s" key "translations" must be a map.', $entry->id());
    }
    elseif (isset($definition['translations'])) {
      $errors = array_merge($errors, $this->validateTranslations($entry));
    }

    $definition_file = $entry->definitionFile();
    if ($definition_file !== NULL) {
      if (!$this->isSafeDefinitionFile($definition_file)) {
        $errors[] = sprintf('Content "%s" declares an unsafe file path.', $entry->id());
      }
      elseif (!is_file($base_path . '/' . $definition_file)) {
        $errors[] = sprintf(
          'Content "%s" declares a missing file: %s.',
          $entry->id(),
          $definition_file,
        );
      }
    }

    return $errors;
  }

  /**
   * Checks that a declared file remains below the catalog directory.
   */
  private function isSafeDefinitionFile(string $definition_file): bool {
    return !str_starts_with($definition_file, '/')
      && !str_contains($definition_file, '\\')
      && !str_contains($definition_file, '..');
  }

  /**
   * Validates translation aliases.
   *
   * @return string[]
   *   Validation errors.
   */
  private function validateTranslations(ContentSyncCatalogEntry $entry): array {
    $errors = [];
    $definition = $entry->toArray();
    $translations = $definition['translations'];

    if ($translations === []) {
      return [sprintf('Content "%s" must declare at least one translation.', $entry->id())];
    }

    foreach ($translations as $langcode => $translation) {
      if (!is_array($translation)) {
        $errors[] = sprintf(
          'Content "%s" translation "%s" must be a map.',
          $entry->id(),
          (string) $langcode,
        );
        continue;
      }

      if (!isset($translation['alias']) || !is_string($translation['alias']) || $translation['alias'] === '') {
        $errors[] = sprintf(
          'Content "%s" translation "%s" must define an alias.',
          $entry->id(),
          (string) $langcode,
        );
      }
    }

    return $errors;
  }

}

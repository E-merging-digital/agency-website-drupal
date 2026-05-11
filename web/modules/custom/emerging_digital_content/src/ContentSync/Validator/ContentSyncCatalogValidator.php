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
   * UUID pattern used for historical default_content lookups.
   */
  private const UUID_PATTERN =
    '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

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
    $seen_legacy_uuids = [];
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

      foreach ($this->validLegacyUuidReferences($entry) as $legacy_uuid_reference) {
        $legacy_uuid = $legacy_uuid_reference['uuid'];
        $label = $legacy_uuid_reference['label'];
        if (isset($seen_legacy_uuids[$legacy_uuid])) {
          $entry_errors[] = sprintf(
            'Legacy UUID "%s" is declared by both %s and %s.',
            $legacy_uuid,
            $seen_legacy_uuids[$legacy_uuid],
            $label,
          );
          continue;
        }

        $seen_legacy_uuids[$legacy_uuid] = $label;
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

    if (array_key_exists('legacy_uuid', $definition)) {
      $errors = array_merge($errors, $this->validateLegacyUuid(
        $definition['legacy_uuid'],
        sprintf('Content "%s"', $entry->id()),
      ));
    }

    if (isset($definition['translations']) && !is_array($definition['translations'])) {
      $errors[] = sprintf('Content "%s" key "translations" must be a map.', $entry->id());
    }
    elseif (isset($definition['translations'])) {
      $errors = array_merge($errors, $this->validateTranslations($entry));
    }

    if (($definition['bundle'] ?? NULL) === 'page') {
      $errors = array_merge($errors, $this->validatePageComponents($entry));
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
   * Validates page component definitions.
   *
   * @return string[]
   *   Validation errors.
   */
  private function validatePageComponents(ContentSyncCatalogEntry $entry): array {
    $definition = $entry->toArray();
    $components = $definition['components'] ?? NULL;
    if (!is_array($components) || $components === []) {
      return [sprintf('Content "%s" page entries must declare at least one component.', $entry->id())];
    }

    $errors = [];
    $seen_ids = [];
    foreach ($components as $index => $component) {
      if (!is_array($component)) {
        $errors[] = sprintf('Content "%s" component #%d must be a map.', $entry->id(), (int) $index);
        continue;
      }

      $component_id = $component['id'] ?? NULL;
      if (!is_string($component_id) || $component_id === '') {
        $errors[] = sprintf('Content "%s" component #%d must define a stable id.', $entry->id(), (int) $index);
      }
      elseif (isset($seen_ids[$component_id])) {
        $errors[] = sprintf('Content "%s" component id "%s" is duplicated.', $entry->id(), $component_id);
      }
      else {
        $seen_ids[$component_id] = TRUE;
      }

      if (array_key_exists('legacy_uuid', $component)) {
        $errors = array_merge($errors, $this->validateLegacyUuid(
          $component['legacy_uuid'],
          sprintf(
            'Content "%s" component "%s"',
            $entry->id(),
            (string) ($component_id ?? '#' . $index),
          ),
        ));
      }

      if (!isset($component['bundle']) || !is_string($component['bundle']) || $component['bundle'] === '') {
        $errors[] = sprintf(
          'Content "%s" component "%s" must define a paragraph bundle.',
          $entry->id(),
          (string) ($component_id ?? '#' . $index),
        );
      }

      if (!isset($component['translations']) || !is_array($component['translations']) || $component['translations'] === []) {
        $errors[] = sprintf(
          'Content "%s" component "%s" must declare translations.',
          $entry->id(),
          (string) ($component_id ?? '#' . $index),
        );
      }
    }

    return $errors;
  }

  /**
   * Validates one optional legacy UUID.
   *
   * @return string[]
   *   Validation errors.
   */
  private function validateLegacyUuid(mixed $legacy_uuid, string $label): array {
    if (!is_string($legacy_uuid) || $legacy_uuid === '') {
      return [
        sprintf('%s legacy_uuid must be a non-empty UUID string.', $label),
      ];
    }

    if (!preg_match(self::UUID_PATTERN, $legacy_uuid)) {
      return [
        sprintf('%s legacy_uuid "%s" is not a valid UUID.', $label, $legacy_uuid),
      ];
    }

    return [];
  }

  /**
   * Returns valid legacy UUID declarations for duplicate detection.
   *
   * @return array<int, array{uuid: string, label: string}>
   *   Legacy UUID references.
   */
  private function validLegacyUuidReferences(ContentSyncCatalogEntry $entry): array {
    $definition = $entry->toArray();
    $references = [];

    $this->addLegacyUuidReference(
      $references,
      $definition['legacy_uuid'] ?? NULL,
      sprintf('content "%s"', $entry->id()),
    );

    $components = $definition['components'] ?? [];
    if (!is_array($components)) {
      return $references;
    }

    foreach ($components as $index => $component) {
      if (!is_array($component)) {
        continue;
      }

      $component_id = $component['id'] ?? '#' . (string) $index;
      $this->addLegacyUuidReference(
        $references,
        $component['legacy_uuid'] ?? NULL,
        sprintf(
          'content "%s" component "%s"',
          $entry->id(),
          (string) $component_id,
        ),
      );
    }

    return $references;
  }

  /**
   * Adds a valid legacy UUID reference to the duplicate detection map.
   *
   * @param array<int, array{uuid: string, label: string}> $references
   *   Legacy UUID references.
   * @param mixed $legacy_uuid
   *   Candidate legacy UUID.
   * @param string $label
   *   Human-readable catalog location.
   */
  private function addLegacyUuidReference(
    array &$references,
    mixed $legacy_uuid,
    string $label,
  ): void {
    if (!is_string($legacy_uuid) || !preg_match(self::UUID_PATTERN, $legacy_uuid)) {
      return;
    }

    $references[] = [
      'uuid' => strtolower($legacy_uuid),
      'label' => $label,
    ];
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

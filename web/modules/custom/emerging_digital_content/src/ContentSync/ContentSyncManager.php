<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync;

use Drupal\emerging_digital_content\ContentSync\Catalog\Exception\ContentSyncCatalogException;
use Drupal\emerging_digital_content\ContentSync\Loader\ContentSyncCatalogLoader;
use Drupal\emerging_digital_content\ContentSync\Validator\ContentSyncCatalogValidator;

/**
 * Builds read-only Content Sync catalog reports.
 */
final class ContentSyncManager {

  public function __construct(
    private readonly ContentSyncCatalogLoader $catalogLoader,
    private readonly ContentSyncCatalogValidator $catalogValidator,
  ) {
  }

  /**
   * Keeps the existing command entry point as a read-only dry-run.
   *
   * @return array<string, mixed>
   *   A structured catalog dry-run report.
   */
  public function sync(string $content_id = '', bool $dry_run = TRUE): array {
    if (!$dry_run) {
      throw new \InvalidArgumentException('Content Sync apply mode is not implemented for this catalog skeleton.');
    }

    return $this->dryRun($content_id !== '' ? $content_id : NULL);
  }

  /**
   * Validates the full catalog.
   *
   * @return array<string, mixed>
   *   A structured validation report.
   */
  public function validateCatalog(): array {
    try {
      $catalog = $this->catalogLoader->load();
    }
    catch (ContentSyncCatalogException $exception) {
      return [
        'contents_found' => 0,
        'valid_contents' => [],
        'invalid_contents' => [],
        'warnings' => [],
        'errors' => [$exception->getMessage()],
      ];
    }

    return $this->catalogValidator->validate($catalog);
  }

  /**
   * Builds a read-only dry-run report for the catalog or one content item.
   *
   * @return array<string, mixed>
   *   A structured dry-run report.
   */
  public function dryRun(?string $content_id = NULL): array {
    try {
      $catalog = $this->catalogLoader->load();
      $report = $this->catalogValidator->validate($catalog);
    }
    catch (ContentSyncCatalogException $exception) {
      return [
        'dry_run' => TRUE,
        'contents_found' => 0,
        'valid_contents' => [],
        'invalid_contents' => [],
        'warnings' => [],
        'errors' => [$exception->getMessage()],
        'actions' => [],
        'menus_touched' => FALSE,
      ];
    }

    $entries = [];
    if ($content_id !== NULL) {
      $entry = $catalog->get($content_id);
      if ($entry === NULL) {
        if (!isset($report['errors']) || !is_array($report['errors'])) {
          $report['errors'] = [];
        }
        $report['errors'][] = sprintf('Unknown content id "%s".', $content_id);
      }
      else {
        $entries[] = $entry;
      }
    }
    else {
      $entries = $catalog->entries();
    }

    $report['dry_run'] = TRUE;
    $report['actions'] = [];
    $report['menus_touched'] = FALSE;

    foreach ($entries as $entry) {
      $definition = $entry->toArray();
      $translations = is_array($definition['translations'] ?? NULL)
        ? $definition['translations']
        : [];

      $report['actions'][] = sprintf(
        'read %s "%s" from catalog: %s',
        (string) ($definition['entity_type'] ?? 'unknown'),
        (string) ($definition['bundle'] ?? 'unknown'),
        $entry->id(),
      );
      $report['actions'][] = sprintf(
        'validate translations for %s: %s',
        $entry->id(),
        implode(', ', array_keys($translations)),
      );

      foreach ($translations as $langcode => $translation) {
        if (is_array($translation) && isset($translation['alias'])) {
          $report['actions'][] = sprintf(
            'dry-run alias %s: %s',
            (string) $langcode,
            (string) $translation['alias'],
          );
        }
      }

      if (isset($definition['promotions']) && is_array($definition['promotions'])) {
        $report['actions'][] = sprintf(
          'read promotion definitions for %s: %d',
          $entry->id(),
          count($definition['promotions']),
        );
      }

      $report['actions'][] = sprintf(
        'read-only ticket 61 check for %s: no Drupal entity writes are executed',
        $entry->id(),
      );
    }

    $report['actions'][] = 'skip menu_link_content: menus are intentionally out of scope';

    return $report;
  }

}

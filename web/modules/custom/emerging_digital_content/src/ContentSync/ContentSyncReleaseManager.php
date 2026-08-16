<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync;

use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Drupal\emerging_digital_content\ContentSync\Loader\ContentSyncCatalogLoader;
use Drupal\emerging_digital_content\ContentSync\Policy\GovernedContentPolicy;
use Drupal\emerging_digital_content\ContentSync\Repository\ContentSyncMappingRepository;

/**
 * Releases legacy Content Sync mappings without mutating Drupal entities.
 */
final class ContentSyncReleaseManager {

  public function __construct(
    private readonly ContentSyncCatalogLoader $catalogLoader,
    private readonly ContentSyncMappingRepository $mappingRepository,
  ) {
  }

  /**
   * Releases one grandfathered mapping from Git governance.
   *
   * @return array<string, mixed>
   *   Structured lifecycle report.
   */
  public function release(string $content_id, bool $dry_run = TRUE): array {
    if (!GovernedContentPolicy::isReleasePending($content_id)) {
      return $this->report(
        $content_id,
        $dry_run,
        'release',
        NULL,
        [],
        [sprintf(
          'Content "%s" is not in the explicit LEGACY_RELEASE_PENDING allowlist and cannot be released.',
          $content_id,
        )],
      );
    }

    $mapping = $this->mappingRepository->findByContentId($content_id);
    if ($mapping === NULL) {
      return $this->report(
        $content_id,
        $dry_run,
        'release',
        NULL,
        [],
        [sprintf('Content "%s" has no persistent mapping to release.', $content_id)],
      );
    }

    if ($mapping->entityId() === NULL || $mapping->entityUuid() === NULL || $mapping->entityUuid() === '') {
      return $this->report(
        $content_id,
        $dry_run,
        'release',
        $mapping,
        [],
        [sprintf(
          'Content "%s" mapping lacks a complete target identity and cannot be released safely.',
          $content_id,
        )],
      );
    }

    if ($mapping->status() === ContentSyncMappingRecord::STATUS_RELEASED) {
      return $this->report(
        $content_id,
        $dry_run,
        'release',
        $mapping,
        [sprintf(
          'Content "%s" is already released; mapped Drupal entity %s:%d remains untouched.',
          $content_id,
          $mapping->entityType(),
          $mapping->entityId(),
        )],
        [],
      );
    }

    if ($mapping->status() !== ContentSyncMappingRecord::STATUS_ACTIVE) {
      return $this->report(
        $content_id,
        $dry_run,
        'release',
        $mapping,
        [],
        [sprintf(
          'Content "%s" mapping status "%s" cannot transition to released.',
          $content_id,
          $mapping->status(),
        )],
      );
    }

    if ($dry_run) {
      return $this->report(
        $content_id,
        TRUE,
        'release',
        $mapping,
        [sprintf(
          'would release mapping for "%s" while preserving Drupal entity %s:%d (%s)',
          $content_id,
          $mapping->entityType(),
          $mapping->entityId(),
          $mapping->entityUuid(),
        )],
        [],
      );
    }

    $released = $this->mappingRepository->markReleased($content_id);

    return $this->report(
      $content_id,
      FALSE,
      'release',
      $released,
      [sprintf(
        'released mapping for "%s"; Drupal entity %s:%d (%s) was not deleted, unpublished or rewritten',
        $content_id,
        $released->entityType(),
        $released->entityId(),
        $released->entityUuid(),
      )],
      [],
    );
  }

  /**
   * Explicitly readmits one released mapping after catalog reintroduction.
   *
   * @return array<string, mixed>
   *   Structured lifecycle report.
   */
  public function readmit(string $content_id, bool $dry_run = TRUE): array {
    $mapping = $this->mappingRepository->findByContentId($content_id);
    if ($mapping === NULL) {
      return $this->report(
        $content_id,
        $dry_run,
        'readmit',
        NULL,
        [],
        [sprintf('Content "%s" has no persistent mapping to readmit.', $content_id)],
      );
    }

    if ($mapping->status() !== ContentSyncMappingRecord::STATUS_RELEASED) {
      return $this->report(
        $content_id,
        $dry_run,
        'readmit',
        $mapping,
        [],
        [sprintf(
          'Content "%s" mapping status is "%s"; only released mappings can be readmitted.',
          $content_id,
          $mapping->status(),
        )],
      );
    }

    try {
      $catalog = $this->catalogLoader->load();
    }
    catch (\Throwable $exception) {
      return $this->report(
        $content_id,
        $dry_run,
        'readmit',
        $mapping,
        [],
        ['Content Sync catalog could not be loaded before readmission: ' . $exception->getMessage()],
      );
    }

    if ($catalog->get($content_id) === NULL) {
      return $this->report(
        $content_id,
        $dry_run,
        'readmit',
        $mapping,
        [],
        [sprintf(
          'Content "%s" must be explicitly restored to the catalog before its mapping can be readmitted.',
          $content_id,
        )],
      );
    }

    if ($dry_run) {
      return $this->report(
        $content_id,
        TRUE,
        'readmit',
        $mapping,
        [sprintf(
          'would readmit mapping for "%s" because the content is present in the catalog again',
          $content_id,
        )],
        [],
      );
    }

    $active = $this->mappingRepository->markActive($content_id);

    return $this->report(
      $content_id,
      FALSE,
      'readmit',
      $active,
      [sprintf(
        'readmitted mapping for "%s"; normal Content Sync writes may resume explicitly',
        $content_id,
      )],
      [],
    );
  }

  /**
   * Builds a compact structured lifecycle report.
   *
   * @param list<string> $actions
   *   Actions performed or planned.
   * @param list<string> $errors
   *   Fail-closed validation errors.
   *
   * @return array<string, mixed>
   *   Report suitable for Drush output and tests.
   */
  private function report(
    string $content_id,
    bool $dry_run,
    string $operation,
    ?ContentSyncMappingRecord $mapping,
    array $actions,
    array $errors,
  ): array {
    return [
      'operation' => $operation,
      'dry_run' => $dry_run,
      'content_id' => $content_id,
      'mapping_status' => $mapping?->status() ?? 'unmapped',
      'mapped_entity' => $mapping === NULL
        ? ''
        : sprintf('%s:%s', $mapping->entityType(), (string) ($mapping->entityId() ?? 'unknown')),
      'mapped_uuid' => $mapping?->entityUuid() ?? '',
      'catalog_hash' => $mapping?->catalogHash() ?? '',
      'last_synced' => $mapping?->lastSynced(),
      'last_action' => $mapping?->lastAction() ?? '',
      'actions' => $actions,
      'warnings' => [],
      'errors' => $errors,
      'menus_touched' => FALSE,
    ];
  }

}

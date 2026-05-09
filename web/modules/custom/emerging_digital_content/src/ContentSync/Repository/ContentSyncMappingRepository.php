<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes persistent Content Sync mapping rows.
 */
final class ContentSyncMappingRepository {

  private const TABLE = 'emerging_digital_content_sync_mapping';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Finds one mapping by catalog business identifier.
   */
  public function findByContentId(string $content_id): ?ContentSyncMappingRecord {
    if (!$this->tableExists()) {
      return NULL;
    }

    $row = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('content_id', $content_id)
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    if (!is_array($row)) {
      return NULL;
    }

    return ContentSyncMappingRecord::fromArray($row);
  }

  /**
   * Checks whether a mapping exists for a catalog business identifier.
   */
  public function exists(string $content_id): bool {
    if (!$this->tableExists()) {
      return FALSE;
    }

    $id = $this->database->select(self::TABLE, 'm')
      ->fields('m', ['id'])
      ->condition('content_id', $content_id)
      ->range(0, 1)
      ->execute()
      ->fetchField();

    return $id !== FALSE;
  }

  /**
   * Finds active mappings whose business identifiers are absent from catalog.
   *
   * @param list<string> $catalog_content_ids
   *   Business identifiers currently declared in the catalog.
   *
   * @return list<\Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord>
   *   Active mappings absent from the catalog.
   */
  public function findActiveMissingFromCatalog(array $catalog_content_ids): array {
    if (!$this->tableExists()) {
      return [];
    }

    $query = $this->database->select(self::TABLE, 'm')
      ->fields('m')
      ->condition('status', 'active')
      ->orderBy('content_id');

    if ($catalog_content_ids !== []) {
      $query->condition('content_id', $catalog_content_ids, 'NOT IN');
    }

    $records = [];
    foreach ($query->execute()->fetchAllAssoc('content_id') as $row) {
      $records[] = ContentSyncMappingRecord::fromArray((array) $row);
    }

    return $records;
  }

  /**
   * Creates or updates one mapping row.
   */
  public function createOrUpdate(ContentSyncMappingRecord $record): ContentSyncMappingRecord {
    if (!$this->tableExists()) {
      throw new \RuntimeException('Content Sync mapping table is not installed. Run database updates first.');
    }

    $now = $this->time->getRequestTime();
    $existing = $this->findByContentId($record->contentId());
    $row = $record->toDatabaseRow();
    $row['changed'] = $now;

    if ($existing === NULL) {
      $row['created'] = $record->created() > 0 ? $record->created() : $now;
      $id = (int) $this->database->insert(self::TABLE)
        ->fields($row)
        ->execute();

      $this->logger->notice(
        'Content Sync mapping created for {content_id} ({entity_type}:{entity_id}).',
        [
          'content_id' => $record->contentId(),
          'entity_type' => $record->entityType(),
          'entity_id' => (string) ($record->entityId() ?? ''),
        ],
      );

      $row['id'] = $id;
      return ContentSyncMappingRecord::fromArray($row);
    }

    $row['created'] = $existing->created();
    $this->database->update(self::TABLE)
      ->fields($row)
      ->condition('content_id', $record->contentId())
      ->execute();

    $this->logger->notice(
      'Content Sync mapping updated for {content_id} ({entity_type}:{entity_id}).',
      [
        'content_id' => $record->contentId(),
        'entity_type' => $record->entityType(),
        'entity_id' => (string) ($record->entityId() ?? ''),
      ],
    );

    $row['id'] = $existing->id();
    return ContentSyncMappingRecord::fromArray($row);
  }

  /**
   * Removes a mapping row by catalog business identifier.
   *
   * This only deletes the mapping row. Drupal content entities are never
   * deleted here.
   */
  public function remove(string $content_id): int {
    if (!$this->tableExists()) {
      return 0;
    }

    $deleted = (int) $this->database->delete(self::TABLE)
      ->condition('content_id', $content_id)
      ->execute();

    if ($deleted > 0) {
      $this->logger->notice(
        'Content Sync mapping removed for {content_id}; no Drupal content entity was deleted.',
        ['content_id' => $content_id],
      );
    }

    return $deleted;
  }

  /**
   * Checks whether the mapping table has been installed.
   */
  private function tableExists(): bool {
    return $this->database->schema()->tableExists(self::TABLE);
  }

}

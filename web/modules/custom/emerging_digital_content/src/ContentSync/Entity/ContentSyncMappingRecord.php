<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync\Entity;

/**
 * Lightweight value object for one Content Sync mapping row.
 */
final class ContentSyncMappingRecord {

  public function __construct(
    private readonly string $contentId,
    private readonly string $entityType,
    private readonly ?int $entityId,
    private readonly ?string $entityUuid,
    private readonly string $langcode,
    private readonly string $catalogHash,
    private readonly ?int $lastSynced,
    private readonly string $lastAction,
    private readonly string $status,
    private readonly int $created = 0,
    private readonly int $changed = 0,
    private readonly ?int $id = NULL,
  ) {
  }

  /**
   * Builds a mapping record from a database row.
   *
   * @param array<string, mixed> $row
   *   Database row keyed by column name.
   */
  public static function fromArray(array $row): self {
    return new self(
      (string) ($row['content_id'] ?? ''),
      (string) ($row['entity_type'] ?? ''),
      isset($row['entity_id']) ? (int) $row['entity_id'] : NULL,
      isset($row['entity_uuid']) ? (string) $row['entity_uuid'] : NULL,
      (string) ($row['langcode'] ?? ''),
      (string) ($row['catalog_hash'] ?? ''),
      isset($row['last_synced']) ? (int) $row['last_synced'] : NULL,
      (string) ($row['last_action'] ?? ''),
      (string) ($row['status'] ?? 'active'),
      (int) ($row['created'] ?? 0),
      (int) ($row['changed'] ?? 0),
      isset($row['id']) ? (int) $row['id'] : NULL,
    );
  }

  /**
   * Returns the internal mapping row identifier.
   */
  public function id(): ?int {
    return $this->id;
  }

  /**
   * Returns the stable catalog business identifier.
   */
  public function contentId(): string {
    return $this->contentId;
  }

  /**
   * Returns the mapped Drupal entity type.
   */
  public function entityType(): string {
    return $this->entityType;
  }

  /**
   * Returns the mapped Drupal entity numeric identifier.
   */
  public function entityId(): ?int {
    return $this->entityId;
  }

  /**
   * Returns the mapped Drupal entity UUID.
   */
  public function entityUuid(): ?string {
    return $this->entityUuid;
  }

  /**
   * Returns the primary mapping language code.
   */
  public function langcode(): string {
    return $this->langcode;
  }

  /**
   * Returns the latest catalog definition hash recorded for this mapping.
   */
  public function catalogHash(): string {
    return $this->catalogHash;
  }

  /**
   * Returns the latest successful sync timestamp.
   */
  public function lastSynced(): ?int {
    return $this->lastSynced;
  }

  /**
   * Returns the latest recorded sync action.
   */
  public function lastAction(): string {
    return $this->lastAction;
  }

  /**
   * Returns the mapping status.
   */
  public function status(): string {
    return $this->status;
  }

  /**
   * Returns the mapping creation timestamp.
   */
  public function created(): int {
    return $this->created;
  }

  /**
   * Returns the latest mapping change timestamp.
   */
  public function changed(): int {
    return $this->changed;
  }

  /**
   * Converts the value object to a database row.
   *
   * @return array<string, int|string|null>
   *   Row values keyed by column name.
   */
  public function toDatabaseRow(): array {
    return [
      'content_id' => $this->contentId,
      'entity_type' => $this->entityType,
      'entity_id' => $this->entityId,
      'entity_uuid' => $this->entityUuid,
      'langcode' => $this->langcode,
      'catalog_hash' => $this->catalogHash,
      'last_synced' => $this->lastSynced,
      'last_action' => $this->lastAction,
      'status' => $this->status,
      'created' => $this->created,
      'changed' => $this->changed,
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Kernel;

use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Drupal\emerging_digital_content\ContentSync\Repository\ContentSyncMappingRepository;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the persistent Content Sync mapping repository.
 *
 * @group emerging_digital_content
 */
#[RunTestsInSeparateProcesses]
final class ContentSyncMappingRepositoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'emerging_digital_content',
    'path_alias',
    'system',
  ];

  /**
   * Tests CRUD and explicit lifecycle transitions.
   */
  public function testRepositoryPersistsMappingByUniqueContentId(): void {
    $this->installSchema('emerging_digital_content', ['emerging_digital_content_sync_mapping']);

    $repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');
    self::assertInstanceOf(ContentSyncMappingRepository::class, $repository);

    self::assertFalse($repository->exists('agence-drupal-belgique'));

    $created = $repository->createOrUpdate(new ContentSyncMappingRecord(
      'agence-drupal-belgique',
      'node',
      123,
      '11111111-1111-1111-1111-111111111111',
      'fr',
      str_repeat('a', 64),
      1_700_000_000,
      'created',
      ContentSyncMappingRecord::STATUS_ACTIVE,
    ));

    self::assertNotNull($created->id());
    self::assertTrue($repository->exists('agence-drupal-belgique'));

    $loaded = $repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($loaded);
    self::assertSame(123, $loaded->entityId());
    self::assertSame('created', $loaded->lastAction());

    $updated = $repository->createOrUpdate(new ContentSyncMappingRecord(
      'agence-drupal-belgique',
      'node',
      456,
      '22222222-2222-2222-2222-222222222222',
      'fr',
      str_repeat('b', 64),
      1_700_000_100,
      'updated',
      ContentSyncMappingRecord::STATUS_ACTIVE,
    ));

    self::assertSame($created->id(), $updated->id());
    self::assertSame(456, $updated->entityId());
    self::assertSame('updated', $updated->lastAction());

    $released = $repository->markReleased('agence-drupal-belgique');
    self::assertSame(ContentSyncMappingRecord::STATUS_RELEASED, $released->status());
    self::assertSame('released', $released->lastAction());
    self::assertSame($updated->id(), $released->id());
    self::assertSame($updated->entityId(), $released->entityId());
    self::assertSame($updated->entityUuid(), $released->entityUuid());
    self::assertSame($updated->catalogHash(), $released->catalogHash());
    self::assertSame($updated->lastSynced(), $released->lastSynced());
    self::assertSame([], $repository->findActiveMissingFromCatalog([]));

    try {
      $repository->createOrUpdate(new ContentSyncMappingRecord(
        'agence-drupal-belgique',
        'node',
        456,
        '22222222-2222-2222-2222-222222222222',
        'fr',
        str_repeat('c', 64),
        1_700_000_200,
        'updated',
        ContentSyncMappingRecord::STATUS_ACTIVE,
      ));
      self::fail('A normal sync must not implicitly reactivate a released mapping.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('explicitly first', $exception->getMessage());
    }

    $still_released = $repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($still_released);
    self::assertSame(ContentSyncMappingRecord::STATUS_RELEASED, $still_released->status());
    self::assertSame(str_repeat('b', 64), $still_released->catalogHash());

    $readmitted = $repository->markActive('agence-drupal-belgique');
    self::assertSame(ContentSyncMappingRecord::STATUS_ACTIVE, $readmitted->status());
    self::assertSame('readmitted', $readmitted->lastAction());
    self::assertCount(1, $repository->findActiveMissingFromCatalog([]));

    $count = (int) $this->container->get('database')
      ->select('emerging_digital_content_sync_mapping', 'm')
      ->countQuery()
      ->execute()
      ->fetchField();
    self::assertSame(1, $count);

    self::assertSame(1, $repository->remove('agence-drupal-belgique'));
    self::assertFalse($repository->exists('agence-drupal-belgique'));
  }

}

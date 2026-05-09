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
    'system',
  ];

  /**
   * Tests create, read, update and remove operations.
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
      'active',
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
      'active',
    ));

    self::assertSame($created->id(), $updated->id());
    self::assertSame(456, $updated->entityId());
    self::assertSame('updated', $updated->lastAction());

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

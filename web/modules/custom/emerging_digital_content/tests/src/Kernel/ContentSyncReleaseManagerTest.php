<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Kernel;

use Drupal\emerging_digital_content\ContentSync\ContentSyncManager;
use Drupal\emerging_digital_content\ContentSync\ContentSyncReleaseManager;
use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Drupal\emerging_digital_content\ContentSync\Repository\ContentSyncMappingRepository;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests controlled release and explicit readmission of Content Sync mappings.
 *
 * @group emerging_digital_content
 */
#[RunTestsInSeparateProcesses]
final class ContentSyncReleaseManagerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'emerging_digital_content',
    'node',
    'path_alias',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('emerging_digital_content', ['emerging_digital_content_sync_mapping']);
    $this->installConfig(['node', 'system']);

    NodeType::create([
      'type' => 'service',
      'name' => 'Service',
    ])->save();
  }

  /**
   * Tests release safety, idempotence, sync guards and readmission.
   */
  public function testReleasePreservesMappedNodeAndRequiresExplicitReadmission(): void {
    $repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');
    self::assertInstanceOf(ContentSyncMappingRepository::class, $repository);

    $release_manager = $this->container->get('emerging_digital_content.content_sync_release_manager');
    self::assertInstanceOf(ContentSyncReleaseManager::class, $release_manager);

    $content_sync_manager = $this->container->get('emerging_digital_content.content_sync_manager');
    self::assertInstanceOf(ContentSyncManager::class, $content_sync_manager);

    $node = Node::create([
      'type' => 'service',
      'title' => 'Editorial service retained after release',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 0,
    ]);
    $node->save();

    $original_uuid = $node->uuid();
    $original_id = (int) $node->id();
    $original_title = $node->label();
    $original_hash = str_repeat('a', 64);
    $original_synced = 1_700_000_000;

    $repository->createOrUpdate(new ContentSyncMappingRecord(
      'services/drupal',
      'node',
      $original_id,
      $original_uuid,
      'fr',
      $original_hash,
      $original_synced,
      'updated',
      ContentSyncMappingRecord::STATUS_ACTIVE,
    ));

    $dry_run = $release_manager->release('services/drupal', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertTrue($dry_run['dry_run']);
    self::assertSame(ContentSyncMappingRecord::STATUS_ACTIVE, $dry_run['mapping_status']);

    $after_dry_run = $repository->findByContentId('services/drupal');
    self::assertNotNull($after_dry_run);
    self::assertSame(ContentSyncMappingRecord::STATUS_ACTIVE, $after_dry_run->status());

    $released_report = $release_manager->release('services/drupal', FALSE);
    self::assertSame([], $released_report['errors']);
    self::assertFalse($released_report['dry_run']);
    self::assertSame(ContentSyncMappingRecord::STATUS_RELEASED, $released_report['mapping_status']);
    self::assertSame('node:' . $original_id, $released_report['mapped_entity']);
    self::assertSame($original_uuid, $released_report['mapped_uuid']);

    $released = $repository->findByContentId('services/drupal');
    self::assertNotNull($released);
    self::assertSame(ContentSyncMappingRecord::STATUS_RELEASED, $released->status());
    self::assertSame('released', $released->lastAction());
    self::assertSame($original_id, $released->entityId());
    self::assertSame($original_uuid, $released->entityUuid());
    self::assertSame($original_hash, $released->catalogHash());
    self::assertSame($original_synced, $released->lastSynced());
    self::assertSame([], $repository->findActiveMissingFromCatalog([]));

    $blocked_dry_run = $content_sync_manager->sync('services/drupal', TRUE);
    self::assertNotSame([], $blocked_dry_run['errors']);
    self::assertStringContainsString('released mapping', (string) $blocked_dry_run['errors'][0]);
    self::assertSame('released', $blocked_dry_run['content_reports'][0]['mapping_status']);
    self::assertSame(
      'blocked: released mapping requires explicit readmission',
      $blocked_dry_run['content_reports'][0]['planned_operation'],
    );

    $blocked_apply = $content_sync_manager->sync('services/drupal', FALSE);
    self::assertNotSame([], $blocked_apply['errors']);
    self::assertStringContainsString('released mapping', (string) $blocked_apply['errors'][0]);
    self::assertContains(
      'released mapping guard: no Drupal entity or mapping writes were executed',
      $blocked_apply['actions'],
    );

    $node_storage = $this->container->get('entity_type.manager')->getStorage('node');
    $node_storage->resetCache([$original_id]);
    $unchanged_node = $node_storage->load($original_id);
    self::assertInstanceOf(NodeInterface::class, $unchanged_node);
    self::assertTrue($unchanged_node->isPublished());
    self::assertSame($original_uuid, $unchanged_node->uuid());
    self::assertSame($original_title, $unchanged_node->label());

    $still_released_after_sync = $repository->findByContentId('services/drupal');
    self::assertNotNull($still_released_after_sync);
    self::assertSame(ContentSyncMappingRecord::STATUS_RELEASED, $still_released_after_sync->status());
    self::assertSame($original_hash, $still_released_after_sync->catalogHash());

    $idempotent = $release_manager->release('services/drupal', FALSE);
    self::assertSame([], $idempotent['errors']);
    self::assertSame(ContentSyncMappingRecord::STATUS_RELEASED, $idempotent['mapping_status']);

    $legal_block = $release_manager->release('mentions-legales', TRUE);
    self::assertNotSame([], $legal_block['errors']);
    self::assertStringContainsString('not in the explicit LEGACY_RELEASE_PENDING allowlist', (string) $legal_block['errors'][0]);

    $readmit_dry_run = $release_manager->readmit('services/drupal', TRUE);
    self::assertSame([], $readmit_dry_run['errors']);
    self::assertTrue($readmit_dry_run['dry_run']);
    self::assertSame(ContentSyncMappingRecord::STATUS_RELEASED, $readmit_dry_run['mapping_status']);

    $still_released = $repository->findByContentId('services/drupal');
    self::assertNotNull($still_released);
    self::assertSame(ContentSyncMappingRecord::STATUS_RELEASED, $still_released->status());

    $readmitted_report = $release_manager->readmit('services/drupal', FALSE);
    self::assertSame([], $readmitted_report['errors']);
    self::assertSame(ContentSyncMappingRecord::STATUS_ACTIVE, $readmitted_report['mapping_status']);

    $readmitted = $repository->findByContentId('services/drupal');
    self::assertNotNull($readmitted);
    self::assertSame(ContentSyncMappingRecord::STATUS_ACTIVE, $readmitted->status());
    self::assertSame('readmitted', $readmitted->lastAction());
    self::assertSame($original_id, $readmitted->entityId());
    self::assertSame($original_uuid, $readmitted->entityUuid());
    self::assertSame($original_hash, $readmitted->catalogHash());
    self::assertSame($original_synced, $readmitted->lastSynced());

    $node_storage->resetCache([$original_id]);
    $after_readmission = $node_storage->load($original_id);
    self::assertInstanceOf(NodeInterface::class, $after_readmission);
    self::assertTrue($after_readmission->isPublished());
    self::assertSame($original_title, $after_readmission->label());
  }

}

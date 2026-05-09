<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Kernel;

use Drupal\emerging_digital_content\ContentSync\Entity\ContentSyncMappingRecord;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests targeted Content Sync writes from the YAML catalog.
 *
 * @group emerging_digital_content
 */
#[RunTestsInSeparateProcesses]
final class ContentSyncManagerTargetedWriteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
    'default_content',
    'emerging_digital_content',
    'entity_reference_revisions',
    'field',
    'file',
    'filter',
    'hal',
    'language',
    'node',
    'paragraphs',
    'path',
    'path_alias',
    'serialization',
    'system',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('emerging_digital_content', ['emerging_digital_content_sync_mapping']);
    $this->installConfig(['filter', 'node', 'system']);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    ConfigurableLanguage::createFromLangcode('en')->save();
    $this->config('system.site')
      ->set('langcode', 'fr')
      ->set('default_langcode', 'fr')
      ->save();

    NodeType::create([
      'type' => 'service',
      'name' => 'Service',
    ])->save();

    $this->createTextLongField('field_short_description', TRUE);
    $this->createTextLongField('field_detailed_description', FALSE);
  }

  /**
   * Tests dry-run safety, targeted create/update and mapping idempotence.
   */
  public function testTargetedSyncCreatesTranslationsAliasesAndMapping(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('agence-drupal-belgique', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('agence-drupal-belgique'));
    self::assertSame(0, $this->countServiceNodes());

    $first_apply = $manager->sync('agence-drupal-belgique', FALSE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countServiceNodes());

    $node = $this->loadOnlyServiceNode();
    self::assertSame('fr', $node->language()->getId());
    self::assertSame('Agence Drupal Belgique', $node->label());
    self::assertTrue($node->hasTranslation('en'));
    self::assertStringContainsString(
      'Emerging Digital accompagne',
      (string) $node->get('field_detailed_description')->value,
    );

    $english = $node->getTranslation('en');
    self::assertSame('Drupal Agency Belgium', $english->label());
    self::assertStringContainsString(
      'Emerging Digital supports Belgian organisations',
      (string) $english->get('field_detailed_description')->value,
    );

    $alias_manager = $this->container->get('path_alias.manager');
    $alias_manager->cacheClear('/node/' . $node->id());
    self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias('/agence-drupal-belgique', 'fr'));
    self::assertSame('/node/' . $node->id(), $alias_manager->getPathByAlias('/drupal-agency-belgium', 'en'));

    $mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($mapping);
    self::assertSame((int) $node->id(), $mapping->entityId());
    self::assertSame($node->uuid(), $mapping->entityUuid());
    self::assertSame('created', $mapping->lastAction());

    $second_apply = $manager->sync('agence-drupal-belgique', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countServiceNodes());

    $updated_mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($updated_mapping);
    self::assertSame($mapping->id(), $updated_mapping->id());
    self::assertSame('updated', $updated_mapping->lastAction());
  }

  /**
   * Tests full catalog dry-run safety, apply and idempotence.
   */
  public function testAllSyncCreatesCatalogContentsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('', TRUE, TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('agence-drupal-belgique'));
    self::assertSame(0, $this->countServiceNodes());

    $first_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countServiceNodes());
    self::assertArrayHasKey('content_reports', $first_apply);
    self::assertCount(1, $first_apply['content_reports']);
    self::assertSame('agence-drupal-belgique', $first_apply['content_reports'][0]['id']);

    $mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($mapping);
    self::assertSame('created', $mapping->lastAction());

    $second_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countServiceNodes());

    $updated_mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($updated_mapping);
    self::assertSame($mapping->id(), $updated_mapping->id());
    self::assertSame('updated', $updated_mapping->lastAction());
  }

  /**
   * Tests prune only touches active managed nodes absent from catalog.
   */
  public function testPruneUnpublishDryRunAndApplyAreScopedToManagedNodes(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $catalog_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $catalog_apply['errors']);

    $obsolete_node = $this->createPublishedServiceNode('Obsolete managed service');
    $manual_node = $this->createPublishedServiceNode('Manual unmanaged service');
    $mapping_repository->createOrUpdate(new ContentSyncMappingRecord(
      'obsolete-service',
      'node',
      (int) $obsolete_node->id(),
      $obsolete_node->uuid(),
      'fr',
      str_repeat('c', 64),
      1_700_000_000,
      'updated',
      'active',
    ));

    $dry_run = $manager->sync('', TRUE, TRUE, 'unpublish');
    self::assertSame([], $dry_run['errors']);
    self::assertStringContainsString(
      sprintf('would unpublish managed node:%d', (int) $obsolete_node->id()),
      implode("\n", $dry_run['actions']),
    );
    self::assertTrue($this->reloadNode($obsolete_node)->isPublished());
    self::assertTrue($this->reloadNode($manual_node)->isPublished());
    self::assertSame('updated', $mapping_repository->findByContentId('obsolete-service')?->lastAction());

    $apply = $manager->sync('', FALSE, TRUE, 'unpublish');
    self::assertSame([], $apply['errors']);
    self::assertStringContainsString(
      sprintf('unpublished managed node:%d', (int) $obsolete_node->id()),
      implode("\n", $apply['actions']),
    );
    self::assertFalse($this->reloadNode($obsolete_node)->isPublished());
    self::assertTrue($this->reloadNode($manual_node)->isPublished());

    $obsolete_mapping = $mapping_repository->findByContentId('obsolete-service');
    self::assertNotNull($obsolete_mapping);
    self::assertSame('unpublished', $obsolete_mapping->lastAction());
    self::assertSame('unpublished', $obsolete_mapping->status());
  }

  /**
   * Tests prune cannot be used outside the explicit safe --all mode.
   */
  public function testPruneRejectsTargetedAndUnsupportedModes(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Content Sync prune mode requires --all.');
    $manager->sync('agence-drupal-belgique', TRUE, FALSE, 'unpublish');
  }

  /**
   * Tests delete prune mode is not implemented.
   */
  public function testPruneDeleteIsRejected(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Only "unpublish" is available.');
    $manager->sync('', TRUE, TRUE, 'delete');
  }

  /**
   * Creates one translatable text_long field on service nodes.
   */
  private function createTextLongField(string $field_name, bool $required): void {
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'type' => 'text_long',
      'translatable' => TRUE,
    ])->save();

    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'node',
      'bundle' => 'service',
      'label' => $field_name,
      'required' => $required,
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * Counts service nodes without applying access checks.
   */
  private function countServiceNodes(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service')
      ->count()
      ->execute();
  }

  /**
   * Loads the only service node created by the targeted sync.
   */
  private function loadOnlyServiceNode(): NodeInterface {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service')
      ->execute();

    $node = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->load((int) reset($ids));
    self::assertInstanceOf(NodeInterface::class, $node);

    return $node;
  }

  /**
   * Creates a published service node for prune scope tests.
   */
  private function createPublishedServiceNode(string $title): NodeInterface {
    $node = Node::create([
      'type' => 'service',
      'title' => $title,
      'langcode' => 'fr',
      'status' => NodeInterface::PUBLISHED,
      'uid' => 1,
    ]);
    $node->save();
    self::assertInstanceOf(NodeInterface::class, $node);

    return $node;
  }

  /**
   * Reloads a node after a Content Sync operation.
   */
  private function reloadNode(NodeInterface $node): NodeInterface {
    $reloaded = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->load((int) $node->id());
    self::assertInstanceOf(NodeInterface::class, $reloaded);

    return $reloaded;
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
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

}

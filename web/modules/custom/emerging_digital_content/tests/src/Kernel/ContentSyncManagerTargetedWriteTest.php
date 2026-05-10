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
use Drupal\paragraphs\Entity\ParagraphsType;
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
    'link',
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
    $this->installEntitySchema('paragraph');
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

    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ])->save();

    foreach (['hero', 'text_block', 'services', 'ai_features', 'trust_list', 'cta'] as $paragraph_type) {
      ParagraphsType::create([
        'id' => $paragraph_type,
        'label' => $paragraph_type,
      ])->save();
    }

    $this->createTextLongField('field_short_description', TRUE);
    $this->createTextLongField('field_detailed_description', FALSE);
    $this->createHomeComponentsField();
    $this->createParagraphField('field_heading', 'string', [
      'hero',
      'text_block',
      'services',
      'ai_features',
      'trust_list',
    ]);
    $this->createParagraphField('field_text', 'text_long', ['hero', 'text_block', 'ai_features', 'cta']);
    $this->createParagraphField('field_items', 'text_long', ['services', 'ai_features', 'trust_list']);
    $this->createParagraphField('field_link', 'link', ['cta']);
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
    self::assertSame('dry_run', $dry_run['summary']['mode']);
    self::assertTrue($dry_run['summary']['all']);
    self::assertTrue($dry_run['summary']['dry_run']);
    self::assertFalse($dry_run['summary']['blocking_errors']);
    self::assertCount(3, $dry_run['content_reports']);
    self::assertSame('agence-drupal-belgique', $dry_run['content_reports'][0]['id']);
    self::assertSame('would create managed entity', $dry_run['content_reports'][0]['planned_operation']);
    self::assertSame('unmapped', $dry_run['content_reports'][0]['mapping_status']);
    self::assertFalse($mapping_repository->exists('agence-drupal-belgique'));
    self::assertSame(0, $this->countServiceNodes());
    self::assertSame(0, $this->countPageNodes());

    $first_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countServiceNodes());
    self::assertSame(2, $this->countPageNodes());
    self::assertArrayHasKey('content_reports', $first_apply);
    self::assertCount(3, $first_apply['content_reports']);
    self::assertSame('agence-drupal-belgique', $first_apply['content_reports'][0]['id']);
    self::assertSame('services', $first_apply['content_reports'][1]['id']);
    self::assertSame('ia-drupal', $first_apply['content_reports'][2]['id']);

    $mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($mapping);
    self::assertSame('created', $mapping->lastAction());

    $second_apply = $manager->sync('', FALSE, TRUE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countServiceNodes());
    self::assertSame(2, $this->countPageNodes());

    $updated_mapping = $mapping_repository->findByContentId('agence-drupal-belgique');
    self::assertNotNull($updated_mapping);
    self::assertSame($mapping->id(), $updated_mapping->id());
    self::assertSame('updated', $updated_mapping->lastAction());
  }

  /**
   * Tests Services page sync creates translated paragraphs in stable order.
   */
  public function testServicesPageSyncCreatesTranslatedParagraphsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('services', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('services'));
    self::assertSame(0, $this->countPageNodes());
    self::assertSame(0, $this->countParagraphs());

    $first_apply = $manager->sync('services', FALSE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(5, $this->countParagraphs());

    $page = $this->loadOnlyPageNode();
    self::assertSame('fr', $page->language()->getId());
    self::assertSame('Services', $page->label());
    self::assertTrue($page->hasTranslation('en'));
    self::assertSame('Services', $page->getTranslation('en')->label());

    $alias_manager = $this->container->get('path_alias.manager');
    $alias_manager->cacheClear('/node/' . $page->id());
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/services', 'fr'));
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/services', 'en'));

    $paragraphs = $page->get('field_home_components')->referencedEntities();
    self::assertCount(5, $paragraphs);
    self::assertSame(
      ['hero', 'text_block', 'services', 'text_block', 'cta'],
      array_map(static fn ($paragraph): string => $paragraph->bundle(), $paragraphs),
    );
    self::assertSame(
      'Services Drupal pour projets structurés et institutionnels',
      $paragraphs[0]->get('field_heading')->value,
    );
    self::assertSame('Nos services', $paragraphs[2]->get('field_heading')->value);
    self::assertCount(6, $paragraphs[2]->get('field_items'));
    self::assertSame(
      'Pourquoi Drupal pour des projets exigeants',
      $paragraphs[3]->get('field_heading')->value,
    );
    self::assertSame('Prendre contact', $paragraphs[4]->get('field_link')->title);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(5, $english_paragraphs);
    self::assertSame(
      'Drupal services for structured and institutional projects',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Our services',
      $english_paragraphs[2]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Get in touch',
      $english_paragraphs[4]->getTranslation('en')->get('field_link')->title,
    );

    $mapping = $mapping_repository->findByContentId('services');
    self::assertNotNull($mapping);
    self::assertSame((int) $page->id(), $mapping->entityId());
    self::assertSame('created', $mapping->lastAction());
    self::assertNotNull($mapping_repository->findByContentId('services.grid'));

    $component_ids = array_map(static fn ($paragraph): int => (int) $paragraph->id(), $paragraphs);
    $second_apply = $manager->sync('services', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(5, $this->countParagraphs());
    self::assertSame($component_ids, array_map(
      static fn ($paragraph): int => (int) $paragraph->id(),
      $this->loadOnlyPageNode()->get('field_home_components')->referencedEntities(),
    ));
    self::assertSame('updated', $mapping_repository->findByContentId('services')?->lastAction());
  }

  /**
   * Tests IA & Drupal page sync creates translated paragraphs in stable order.
   */
  public function testIaDrupalPageSyncCreatesTranslatedParagraphsWithoutDuplication(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');
    $mapping_repository = $this->container->get('emerging_digital_content.content_sync_mapping_repository');

    $dry_run = $manager->sync('ia-drupal', TRUE);
    self::assertSame([], $dry_run['errors']);
    self::assertFalse($mapping_repository->exists('ia-drupal'));
    self::assertSame(0, $this->countPageNodes());
    self::assertSame(0, $this->countParagraphs());

    $first_apply = $manager->sync('ia-drupal', FALSE);
    self::assertSame([], $first_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(6, $this->countParagraphs());

    $page = $this->loadOnlyPageNode();
    self::assertSame('fr', $page->language()->getId());
    self::assertSame('IA & Drupal', $page->label());
    self::assertTrue($page->hasTranslation('en'));
    self::assertSame('AI & Drupal', $page->getTranslation('en')->label());

    $alias_manager = $this->container->get('path_alias.manager');
    $alias_manager->cacheClear('/node/' . $page->id());
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/ia-drupal', 'fr'));
    self::assertSame('/node/' . $page->id(), $alias_manager->getPathByAlias('/ai-drupal', 'en'));

    $paragraphs = $page->get('field_home_components')->referencedEntities();
    self::assertCount(6, $paragraphs);
    self::assertSame(
      ['hero', 'text_block', 'ai_features', 'trust_list', 'text_block', 'cta'],
      array_map(static fn ($paragraph): string => $paragraph->bundle(), $paragraphs),
    );
    self::assertSame('IA utile dans Drupal pour les équipes éditoriales', $paragraphs[0]->get('field_heading')->value);
    self::assertSame('Cas d’usage IA dans Drupal', $paragraphs[2]->get('field_heading')->value);
    self::assertCount(5, $paragraphs[2]->get('field_items'));
    self::assertSame('Bénéfices', $paragraphs[3]->get('field_heading')->value);
    self::assertCount(4, $paragraphs[3]->get('field_items'));
    self::assertSame('Intégration dans vos processus CMS', $paragraphs[4]->get('field_heading')->value);
    self::assertSame('Prendre contact', $paragraphs[5]->get('field_link')->title);

    $english_paragraphs = $page->getTranslation('en')->get('field_home_components')->referencedEntities();
    self::assertCount(6, $english_paragraphs);
    self::assertSame(
      'Useful AI in Drupal for editorial teams',
      $english_paragraphs[0]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'AI use cases in Drupal',
      $english_paragraphs[2]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Benefits',
      $english_paragraphs[3]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Integration into your CMS processes',
      $english_paragraphs[4]->getTranslation('en')->get('field_heading')->value,
    );
    self::assertSame(
      'Get in touch',
      $english_paragraphs[5]->getTranslation('en')->get('field_link')->title,
    );

    $mapping = $mapping_repository->findByContentId('ia-drupal');
    self::assertNotNull($mapping);
    self::assertSame((int) $page->id(), $mapping->entityId());
    self::assertSame('created', $mapping->lastAction());
    self::assertNotNull($mapping_repository->findByContentId('ia-drupal.features'));
    self::assertNotNull($mapping_repository->findByContentId('ia-drupal.benefits'));

    $component_ids = array_map(static fn ($paragraph): int => (int) $paragraph->id(), $paragraphs);
    $second_apply = $manager->sync('ia-drupal', FALSE);
    self::assertSame([], $second_apply['errors']);
    self::assertSame(1, $this->countPageNodes());
    self::assertSame(6, $this->countParagraphs());
    self::assertSame($component_ids, array_map(
      static fn ($paragraph): int => (int) $paragraph->id(),
      $this->loadOnlyPageNode()->get('field_home_components')->referencedEntities(),
    ));
    self::assertSame('updated', $mapping_repository->findByContentId('ia-drupal')?->lastAction());
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
   * Tests production prune apply requires an explicit environment flag.
   */
  public function testProductionPruneUnpublishApplyRequiresEnvironmentFlag(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');

    $previous_app_env = getenv('APP_ENV');
    $previous_allow_prune = getenv('CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH');

    putenv('APP_ENV=production');
    putenv('CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH');

    try {
      $dry_run = $manager->sync('', TRUE, TRUE, 'unpublish');
      self::assertSame([], $dry_run['errors']);
      self::assertSame('unpublish', $dry_run['summary']['prune']);

      try {
        $manager->sync('', FALSE, TRUE, 'unpublish');
        self::fail('Production prune apply should require CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH=1.');
      }
      catch (\InvalidArgumentException $exception) {
        self::assertSame(
          'Content Sync --prune=unpublish is blocked in production unless CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH=1 is set.',
          $exception->getMessage(),
        );
      }

      putenv('CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH=1');
      $apply = $manager->sync('', FALSE, TRUE, 'unpublish');
      self::assertSame([], $apply['errors']);
      self::assertSame('unpublish', $apply['summary']['prune']);
    }
    finally {
      $this->restoreEnvironmentVariable('APP_ENV', $previous_app_env);
      $this->restoreEnvironmentVariable('CONTENT_SYNC_ALLOW_PRUNE_UNPUBLISH', $previous_allow_prune);
    }
  }

  /**
   * Tests blocking errors are exposed in the final structured summary.
   */
  public function testBlockingErrorsAreSummarized(): void {
    $manager = $this->container->get('emerging_digital_content.content_sync_manager');

    $report = $manager->sync('unknown-content-id', TRUE);

    self::assertNotSame([], $report['errors']);
    self::assertTrue($report['summary']['blocking_errors']);
    self::assertSame(1, $report['summary']['errors']);
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
   * Creates the translatable page components reference field.
   */
  private function createHomeComponentsField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_home_components',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'translatable' => TRUE,
      'settings' => [
        'target_type' => 'paragraph',
      ],
    ])->save();

    FieldConfig::create([
      'field_name' => 'field_home_components',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Composants de page',
      'required' => FALSE,
      'translatable' => TRUE,
      'settings' => [
        'handler' => 'default:paragraph',
      ],
    ])->save();
  }

  /**
   * Creates a translatable paragraph field on the requested paragraph bundles.
   *
   * @param string $field_name
   *   Field machine name.
   * @param string $type
   *   Field storage type.
   * @param list<string> $bundles
   *   Paragraph bundle IDs.
   */
  private function createParagraphField(string $field_name, string $type, array $bundles): void {
    $storage = [
      'field_name' => $field_name,
      'entity_type' => 'paragraph',
      'type' => $type,
      'cardinality' => $field_name === 'field_heading' ? 1 : FieldStorageConfig::CARDINALITY_UNLIMITED,
      'translatable' => TRUE,
    ];
    if ($type === 'string') {
      $storage['settings'] = [
        'max_length' => 255,
      ];
    }

    FieldStorageConfig::create($storage)->save();

    foreach ($bundles as $bundle) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'paragraph',
        'bundle' => $bundle,
        'label' => $field_name,
        'required' => FALSE,
        'translatable' => TRUE,
      ])->save();
    }
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
   * Counts page nodes without applying access checks.
   */
  private function countPageNodes(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
      ->count()
      ->execute();
  }

  /**
   * Counts paragraphs without applying access checks.
   */
  private function countParagraphs(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('paragraph')
      ->getQuery()
      ->accessCheck(FALSE)
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
   * Loads the only page node created by the targeted sync.
   */
  private function loadOnlyPageNode(): NodeInterface {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'page')
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

  /**
   * Restores an environment variable after a guarded test.
   */
  private function restoreEnvironmentVariable(string $name, string|false $value): void {
    if ($value === FALSE) {
      putenv($name);
      return;
    }

    putenv($name . '=' . $value);
  }

}

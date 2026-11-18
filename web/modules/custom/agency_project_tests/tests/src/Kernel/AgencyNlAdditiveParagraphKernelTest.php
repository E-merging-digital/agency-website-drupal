<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Proves the #1417 NL-only Paragraph path with actual Drupal entity saves.
 *
 * @group agency_project_tests
 */
final class AgencyNlAdditiveParagraphKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'node',
    'language',
    'content_translation',
    'entity_reference_revisions',
    'paragraphs',
  ];

  /**
   * Builds a minimal translatable Page and Paragraph fixture.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system', 'node']);

    foreach (['fr', 'en', 'nl'] as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    ParagraphsType::create([
      'id' => 'text_block',
      'label' => 'Text block',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_home_components',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'translatable' => TRUE,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_home_components',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Components',
      'translatable' => TRUE,
      'settings' => ['handler' => 'default:paragraph'],
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_heading',
      'entity_type' => 'paragraph',
      'type' => 'string',
      'translatable' => TRUE,
      'settings' => ['max_length' => 255],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_heading',
      'entity_type' => 'paragraph',
      'bundle' => 'text_block',
      'label' => 'Heading',
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * Saves an NL-only Paragraph while preserving FR/EN revision pointers.
   */
  public function testNlOnlyParagraphKeepsFrEn(): void {
    $original = Paragraph::create([
      'type' => 'text_block',
      'langcode' => 'fr',
      'field_heading' => 'Titre français',
    ]);
    $original->addTranslation('en', ['field_heading' => 'English heading']);
    $original->save();

    $reference = [
      'target_id' => (int) $original->id(),
      'target_revision_id' => (int) $original->getRevisionId(),
    ];
    $node = Node::create([
      'type' => 'page',
      'langcode' => 'fr',
      'title' => 'Page française',
      'status' => 1,
      'field_home_components' => [$reference],
    ]);
    $node->save();
    $node->addTranslation('en', [
      'title' => 'English page',
      'status' => 1,
      'field_home_components' => [$reference],
    ]);
    $node->save();

    // Compare persisted references, not unsaved PHP integer IDs.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([(int) $node->id()]);
    $node = $storage->load((int) $node->id());
    self::assertNotNull($node);
    $fr_before = $node->getTranslation('fr')
      ->get('field_home_components')->getValue();
    $en_before = $node->getTranslation('en')
      ->get('field_home_components')->getValue();

    $uuid = 'fa387319-bf70-41fc-b26f-281ee6a2421b';
    $nl_paragraph = Paragraph::create([
      'type' => 'text_block',
      'langcode' => 'nl',
      'uuid' => $uuid,
      'field_heading' => 'Nieuwe Nederlandse sectie',
    ]);
    self::assertTrue($nl_paragraph->isNew());
    $nl = $node->addTranslation('nl', [
      'title' => 'Nederlandse pagina',
      'field_home_components' => [['entity' => $nl_paragraph]],
    ]);
    $nl->setUnpublished();
    $node->setNewRevision(TRUE);
    $node->save();

    $storage->resetCache([(int) $node->id()]);
    $reloaded = $storage->load((int) $node->id());
    self::assertNotNull($reloaded);
    self::assertTrue($reloaded->hasTranslation('nl'));
    self::assertFalse($reloaded->getTranslation('nl')->isPublished());
    self::assertSame($fr_before, $reloaded->getTranslation('fr')
      ->get('field_home_components')->getValue());
    self::assertSame($en_before, $reloaded->getTranslation('en')
      ->get('field_home_components')->getValue());
    self::assertSame('Page française', $reloaded->getTranslation('fr')->label());
    self::assertSame('English page', $reloaded->getTranslation('en')->label());
    self::assertTrue($reloaded->getTranslation('fr')->isPublished());
    self::assertTrue($reloaded->getTranslation('en')->isPublished());

    $nl_references = $reloaded->getTranslation('nl')
      ->get('field_home_components')->referencedEntities();
    self::assertCount(1, $nl_references);
    self::assertFalse($nl_references[0]->isNew());
    self::assertSame($uuid, $nl_references[0]->uuid());
    self::assertSame('nl', $nl_references[0]->language()->getId());
    self::assertSame('Nieuwe Nederlandse sectie',
      $nl_references[0]->get('field_heading')->value);
    $fr_paragraph = $reloaded->getTranslation('fr')
      ->get('field_home_components')->referencedEntities()[0];
    self::assertSame($original->uuid(), $fr_paragraph->uuid());
    self::assertSame('Titre français', $fr_paragraph->get('field_heading')->value);
    self::assertSame('English heading',
      $fr_paragraph->getTranslation('en')->get('field_heading')->value);
  }

}

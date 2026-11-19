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

  /**
   * An error after a real NL draft save rolls back its owned Paragraph too.
   */
  public function testNlDraftRollbackRemovesSavedTranslationAndOwnedParagraph(): void {
    $original = Paragraph::create([
      'type' => 'text_block',
      'langcode' => 'fr',
      'field_heading' => 'Référence originale',
    ]);
    $original->addTranslation('en', ['field_heading' => 'Original reference']);
    $original->save();
    $reference = [
      'target_id' => (int) $original->id(),
      'target_revision_id' => (int) $original->getRevisionId(),
    ];
    $node = Node::create([
      'type' => 'page',
      'langcode' => 'fr',
      'title' => 'Page source conservée',
      'status' => 1,
      'field_home_components' => [$reference],
    ]);
    $node->save();
    $node->addTranslation('en', [
      'title' => 'Preserved English page',
      'status' => 1,
      'field_home_components' => [$reference],
    ]);
    $node->save();

    $id = (int) $node->id();
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$id]);
    $node = $storage->load($id);
    self::assertNotNull($node);
    $before_fr = $node->getTranslation('fr')
      ->get('field_home_components')->getValue();
    $before_en = $node->getTranslation('en')
      ->get('field_home_components')->getValue();
    $uuid = 'df56cb38-ad87-4f84-ace1-bfe6f3d602aa';

    $transaction = $this->container->get('database')->startTransaction();
    try {
      $nl_paragraph = Paragraph::create([
        'type' => 'text_block',
        'langcode' => 'nl',
        'uuid' => $uuid,
        'field_heading' => 'Tijdelijke Nederlandse sectie',
      ]);
      $translation = $node->addTranslation('nl', [
        'title' => 'Tijdelijke Nederlandse pagina',
        'field_home_components' => [['entity' => $nl_paragraph]],
      ]);
      $translation->setUnpublished();
      $node->setNewRevision(TRUE);
      $node->save();

      $storage->resetCache([$id]);
      $during = $storage->load($id);
      self::assertNotNull($during);
      self::assertTrue($during->hasTranslation('nl'));
      self::assertFalse($during->getTranslation('nl')->isPublished());
      $during_paragraphs = $during->getTranslation('nl')
        ->get('field_home_components')->referencedEntities();
      self::assertCount(1, $during_paragraphs);
      self::assertSame($uuid, $during_paragraphs[0]->uuid());

      // Simulate a later batch item failing after the first item was saved.
      throw new \RuntimeException('synthetic post-save failure');
    }
    catch (\RuntimeException $exception) {
      self::assertSame('synthetic post-save failure', $exception->getMessage());
      $transaction->rollBack();
    }

    $storage->resetCache([$id]);
    $reloaded = $storage->load($id);
    self::assertNotNull($reloaded);
    self::assertFalse($reloaded->hasTranslation('nl'));
    self::assertSame($before_fr, $reloaded->getTranslation('fr')
      ->get('field_home_components')->getValue());
    self::assertSame($before_en, $reloaded->getTranslation('en')
      ->get('field_home_components')->getValue());
    self::assertSame('Page source conservée',
      $reloaded->getTranslation('fr')->label());
    self::assertSame('Preserved English page',
      $reloaded->getTranslation('en')->label());
    self::assertTrue($reloaded->getTranslation('fr')->isPublished());
    self::assertTrue($reloaded->getTranslation('en')->isPublished());
    $paragraphs = $this->container->get('entity_type.manager')
      ->getStorage('paragraph')->loadByProperties(['uuid' => $uuid]);
    self::assertCount(0, $paragraphs);
  }

}

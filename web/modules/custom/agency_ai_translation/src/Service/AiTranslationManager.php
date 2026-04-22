<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestre la traduction éditoriale FR -> EN pour noeuds et paragraphs.
 */
final class AiTranslationManager {

  private const TRANSLATABLE_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
    'link',
  ];

  public function __construct(
    private readonly AiTranslationClient $client,
    private readonly EntityFieldManagerInterface $fieldManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Traduit une entité source FR vers sa traduction EN.
   *
   * @return int
   *   Nombre de champs traduits.
   */
  public function translateEntityToEnglish(ContentEntityInterface $entity): int {
    if (!$entity->isTranslatable()) {
      throw new \InvalidArgumentException('Entité non traduisible.');
    }

    $sourceLangcode = $entity->language()->getId();
    if ($sourceLangcode !== 'fr') {
      throw new \InvalidArgumentException('La source doit être en français (fr).');
    }

    $translatedEntity = $entity->hasTranslation('en')
      ? $entity->getTranslation('en')
      : $entity->addTranslation('en');

    $translatedCount = $this->translateFields($entity, $translatedEntity);

    $translatedEntity->save();

    return $translatedCount;
  }

  /**
   * Traduit les champs éditoriaux translatables d'une entité.
   */
  private function translateFields(ContentEntityInterface $source, ContentEntityInterface $target): int {
    $count = 0;
    $definitions = $this->fieldManager->getFieldDefinitions($source->getEntityTypeId(), $source->bundle());

    foreach ($definitions as $fieldName => $definition) {
      if (!$definition->isTranslatable()) {
        continue;
      }
      if (!$source->hasField($fieldName) || $source->get($fieldName)->isEmpty()) {
        continue;
      }

      $fieldType = $definition->getType();
      if (in_array($fieldType, self::TRANSLATABLE_FIELD_TYPES, TRUE)) {
        $count += $this->translateSimpleField($source, $target, $fieldName, $fieldType);
        continue;
      }

      if ($fieldType === 'entity_reference_revisions') {
        $count += $this->translateParagraphReferences($source, $target, $fieldName);
      }
    }

    return $count;
  }

  /**
   * Traduit un champ éditorial simple (texte, summary, titre de lien).
   */
  private function translateSimpleField(ContentEntityInterface $source, ContentEntityInterface $target, string $fieldName, string $fieldType): int {
    $items = $source->get($fieldName)->getValue();

    foreach ($items as $delta => $item) {
      if ($fieldType === 'link') {
        $title = trim((string) ($item['title'] ?? ''));
        if ($title !== '') {
          $items[$delta]['title'] = $this->client->translateFrToEn($title);
        }
        continue;
      }

      foreach (['value', 'summary'] as $key) {
        if (!isset($item[$key])) {
          continue;
        }
        $value = trim((string) $item[$key]);
        if ($value === '') {
          continue;
        }
        $items[$delta][$key] = $this->client->translateFrToEn($value);
      }
    }

    $target->set($fieldName, $items);
    return count($items);
  }

  /**
   * Traduit les Paragraphs référencés et rattache les révisions EN.
   */
  private function translateParagraphReferences(ContentEntityInterface $source, ContentEntityInterface $target, string $fieldName): int {
    $translatedReferences = [];
    $count = 0;

    foreach ($source->get($fieldName)->referencedEntities() as $paragraph) {
      if (!$paragraph instanceof ContentEntityInterface || !$paragraph->isTranslatable()) {
        $translatedReferences[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
        continue;
      }

      if ($paragraph->language()->getId() !== 'fr') {
        $translatedReferences[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
        continue;
      }

      $paragraphTranslation = $paragraph->hasTranslation('en')
        ? $paragraph->getTranslation('en')
        : $paragraph->addTranslation('en');

      $count += $this->translateFields($paragraph, $paragraphTranslation);
      $paragraphTranslation->save();

      $translatedReferences[] = [
        'target_id' => $paragraphTranslation->id(),
        'target_revision_id' => $paragraphTranslation->getRevisionId(),
      ];
    }

    if ($translatedReferences !== []) {
      $target->set($fieldName, $translatedReferences);
    }

    return $count;
  }

}

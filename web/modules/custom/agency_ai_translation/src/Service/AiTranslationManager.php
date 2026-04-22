<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Service;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

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
  ) {}

  /**
   * Traduit une entité source FR vers sa traduction EN.
   *
   * @return int
   *   Nombre de champs traduits.
   */
  public function translateEntityToEnglish(ContentEntityInterface $entity): int {
    return $this->translateEntityToLanguage($entity, 'en', 'fr');
  }

  /**
   * Traduit une entité source vers une langue cible.
   *
   * @return int
   *   Nombre de champs traduits.
   */
  public function translateEntityToLanguage(ContentEntityInterface $entity, string $targetLangcode, string $sourceLangcode = 'fr'): int {
    if (!$entity->isTranslatable()) {
      throw new \InvalidArgumentException('Entité non traduisible.');
    }
    if ($targetLangcode === $sourceLangcode) {
      throw new \InvalidArgumentException('La langue cible doit être différente de la langue source.');
    }

    $sourceEntity = $entity->hasTranslation($sourceLangcode) ? $entity->getTranslation($sourceLangcode) : $entity;
    if ($sourceEntity->language()->getId() !== $sourceLangcode) {
      throw new \InvalidArgumentException(sprintf('La source doit être en %s.', $sourceLangcode));
    }

    $translatedEntity = $entity->hasTranslation($targetLangcode)
      ? $entity->getTranslation($targetLangcode)
      : $entity->addTranslation($targetLangcode);

    $translatedCount = $this->translateFields($sourceEntity, $translatedEntity, $sourceLangcode, $targetLangcode);

    $translatedEntity->save();

    return $translatedCount;
  }

  /**
   * Traduit les champs éditoriaux translatables d'une entité.
   */
  private function translateFields(ContentEntityInterface $source, ContentEntityInterface $target, string $sourceLangcode, string $targetLangcode): int {
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
        $count += $this->translateSimpleField($source, $target, $fieldName, $fieldType, $sourceLangcode, $targetLangcode);
        continue;
      }

      if ($fieldType === 'entity_reference_revisions') {
        $count += $this->translateParagraphReferences($source, $target, $fieldName, $sourceLangcode, $targetLangcode);
      }
    }

    return $count;
  }

  /**
   * Traduit un champ éditorial simple (texte, summary, titre de lien).
   */
  private function translateSimpleField(ContentEntityInterface $source, ContentEntityInterface $target, string $fieldName, string $fieldType, string $sourceLangcode, string $targetLangcode): int {
    $items = $source->get($fieldName)->getValue();

    foreach ($items as $delta => $item) {
      if ($fieldType === 'link') {
        $title = trim((string) ($item['title'] ?? ''));
        if ($title !== '') {
          $items[$delta]['title'] = $this->client->translate($title, $sourceLangcode, $targetLangcode);
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
        $items[$delta][$key] = $this->client->translate($value, $sourceLangcode, $targetLangcode);
      }
    }

    $target->set($fieldName, $items);
    return count($items);
  }

  /**
   * Traduit les Paragraphs référencés et rattache les révisions EN.
   */
  private function translateParagraphReferences(ContentEntityInterface $source, ContentEntityInterface $target, string $fieldName, string $sourceLangcode, string $targetLangcode): int {
    $translatedReferences = [];
    $count = 0;

    foreach ($source->get($fieldName)->referencedEntities() as $paragraph) {
      if (!$paragraph instanceof ContentEntityInterface || !$paragraph->isTranslatable()) {
        $translatedReferences[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
        continue;
      }

      if ($paragraph->language()->getId() !== $sourceLangcode) {
        $translatedReferences[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
        continue;
      }

      $paragraphTranslation = $paragraph->hasTranslation($targetLangcode)
        ? $paragraph->getTranslation($targetLangcode)
        : $paragraph->addTranslation($targetLangcode);

      $count += $this->translateFields($paragraph, $paragraphTranslation, $sourceLangcode, $targetLangcode);
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

<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\path_alias\AliasRepositoryInterface;

/**
 * Synchronizes a small allow-list of versioned content by business identifier.
 */
final class ContentSyncManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasRepositoryInterface $aliasRepository,
  ) {
  }

  /**
   * Synchronizes one content item.
   *
   * @return array<string, mixed>
   *   A compact execution report.
   */
  public function sync(string $content_id, bool $dry_run = FALSE): array {
    $definition = $this->getDefinition($content_id);
    $node = $this->loadNodeByBusinessKey($definition);

    $report = [
      'content_id' => $content_id,
      'dry_run' => $dry_run,
      'node_id' => $node?->id(),
      'node_uuid' => $node?->uuid(),
      'actions' => [],
      'warnings' => [],
      'menus_touched' => FALSE,
    ];

    if (!$node instanceof NodeInterface) {
      $report['actions'][] = sprintf('create node: page "%s"', $definition['translations']['fr']['title']);
      $report['actions'][] = 'create translation: en';

      if ($dry_run) {
        foreach ($definition['components'] as $component) {
          $report['actions'][] = sprintf('create paragraph: %s', $component['type']);
        }
        $report['actions'][] = 'set aliases: /agence-drupal-belgique (fr), /drupal-agency-belgium (en)';
        $report['actions'][] = 'skip menu_link_content: menus are intentionally out of scope';
        return $report;
      }

      $node = Node::create([
        'type' => 'page',
        'langcode' => 'fr',
        'uid' => 1,
        'status' => TRUE,
      ]);
    }
    else {
      $report['actions'][] = sprintf('update existing node: nid %s, uuid %s', $node->id(), $node->uuid());
    }

    $component_values = $this->syncParagraphs($node, $definition, $dry_run, $report);

    if (!$dry_run) {
      foreach ($definition['translations'] as $langcode => $translation_values) {
        $translation = $this->getOrCreateTranslation($node, $langcode);
        if ($translation instanceof NodeInterface) {
          $translation->setTitle($translation_values['title']);
          $translation->setPublished();
        }
        $translation->set('path', [
          'alias' => $translation_values['alias'],
          'pathauto' => FALSE,
        ]);

        if ($translation->hasField('field_home_components')) {
          $translation->set('field_home_components', $component_values);
        }
      }

      $node->save();
      $report['node_id'] = $node->id();
      $report['node_uuid'] = $node->uuid();
    }
    else {
      foreach ($definition['translations'] as $langcode => $translation_values) {
        $report['actions'][] = sprintf('update %s translation title/path: %s', $langcode, $translation_values['alias']);
      }
    }

    $report['actions'][] = 'skip menu_link_content: menus are intentionally out of scope';

    return $report;
  }

  /**
   * Returns the supported prototype definitions.
   *
   * @return array<string, mixed>
   *   The content definition.
   */
  private function getDefinition(string $content_id): array {
    $definitions = [
      'agence-drupal-belgique' => [
        'type' => 'page',
        'business_aliases' => [
          'fr' => '/agence-drupal-belgique',
          'en' => '/drupal-agency-belgium',
        ],
        'translations' => [
          'fr' => [
            'title' => 'Agence Drupal Belgique',
            'alias' => '/agence-drupal-belgique',
          ],
          'en' => [
            'title' => 'Drupal Agency Belgium',
            'alias' => '/drupal-agency-belgium',
          ],
        ],
        'components' => [
          [
            'type' => 'hero',
            'translations' => [
              'fr' => [
                'field_heading' => 'Agence Drupal en Belgique',
                'field_text' => [
                  'value' => 'Nous concevons des sites Drupal robustes, accessibles et pensés pour les équipes éditoriales multilingues.',
                  'format' => 'basic_html',
                ],
                'field_link' => [
                  'uri' => 'internal:/contact',
                  'title' => 'Parler de votre projet',
                ],
              ],
              'en' => [
                'field_heading' => 'Drupal agency in Belgium',
                'field_text' => [
                  'value' => 'We build robust, accessible Drupal websites designed for multilingual editorial teams.',
                  'format' => 'basic_html',
                ],
                'field_link' => [
                  'uri' => 'internal:/contact',
                  'title' => 'Discuss your project',
                ],
              ],
            ],
          ],
          [
            'type' => 'text_block',
            'translations' => [
              'fr' => [
                'field_heading' => 'Une synchronisation de contenu sans réimport global',
                'field_text' => [
                  'value' => 'Cette page prototype est gérée par un identifiant métier stable. La commande met à jour le contenu ciblé sans toucher aux menus ni aux autres pages.',
                  'format' => 'basic_html',
                ],
              ],
              'en' => [
                'field_heading' => 'Content synchronization without global re-imports',
                'field_text' => [
                  'value' => 'This prototype page is managed through a stable business identifier. The command updates only the targeted content and leaves menus and other pages untouched.',
                  'format' => 'basic_html',
                ],
              ],
            ],
          ],
          [
            'type' => 'cta',
            'translations' => [
              'fr' => [
                'field_heading' => 'Besoin d’un socle Drupal fiable ?',
                'field_text' => [
                  'value' => 'Nous pouvons auditer votre contenu, vos traductions et votre architecture Drupal avant toute automatisation.',
                  'format' => 'basic_html',
                ],
                'field_link' => [
                  'uri' => 'internal:/contact',
                  'title' => 'Demander un audit',
                ],
              ],
              'en' => [
                'field_heading' => 'Need a reliable Drupal foundation?',
                'field_text' => [
                  'value' => 'We can audit your content, translations and Drupal architecture before automating synchronization.',
                  'format' => 'basic_html',
                ],
                'field_link' => [
                  'uri' => 'internal:/contact',
                  'title' => 'Request an audit',
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    if (!isset($definitions[$content_id])) {
      throw new \InvalidArgumentException(sprintf('Unknown content id "%s".', $content_id));
    }

    return $definitions[$content_id];
  }

  /**
   * Loads the target node through stable aliases instead of packaged UUIDs.
   */
  private function loadNodeByBusinessKey(array $definition): ?NodeInterface {
    foreach ($definition['business_aliases'] as $langcode => $alias) {
      $lookup = $this->aliasRepository->lookupByAlias($alias, $langcode);
      $path = $lookup['path'] ?? NULL;
      if (!is_string($path) || !preg_match('@^/node/(\d+)$@', $path, $matches)) {
        continue;
      }

      $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);
      if ($node instanceof NodeInterface && $node->bundle() === $definition['type']) {
        return $node;
      }
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => $definition['type'],
      'title' => $definition['translations']['fr']['title'],
    ]);
    $node = reset($nodes);

    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Creates or updates the managed paragraphs and returns reference values.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The target page node.
   * @param array<string, mixed> $definition
   *   The content definition.
   * @param bool $dry_run
   *   Whether changes should only be previewed.
   * @param array<string, mixed> $report
   *   The report, updated in place.
   *
   * @return array<int, array<string, int|string>>
   *   field_home_components values.
   */
  private function syncParagraphs(NodeInterface $node, array $definition, bool $dry_run, array &$report): array {
    $component_values = $node->hasField('field_home_components')
      ? $node->get('field_home_components')->getValue()
      : [];
    $existing_components = $node->hasField('field_home_components')
      ? $node->get('field_home_components')->referencedEntities()
      : [];
    $used = [];

    foreach ($definition['components'] as $component) {
      $paragraph = $this->findReusableParagraph($existing_components, $component['type'], $used);

      if ($paragraph instanceof ParagraphInterface) {
        $report['actions'][] = sprintf('update paragraph: %s, uuid %s', $component['type'], $paragraph->uuid());
        if (!$dry_run) {
          $this->applyParagraphTranslations($paragraph, $component['translations']);
          $paragraph->save();
        }
        continue;
      }

      $report['actions'][] = sprintf('append missing paragraph: %s', $component['type']);
      if ($dry_run) {
        continue;
      }

      $paragraph = Paragraph::create([
        'type' => $component['type'],
        'langcode' => 'fr',
        'status' => TRUE,
      ]);
      $this->applyParagraphTranslations($paragraph, $component['translations']);
      $paragraph->save();

      $component_values[] = [
        'target_id' => (int) $paragraph->id(),
        'target_revision_id' => (int) $paragraph->getRevisionId(),
      ];
    }

    return $component_values;
  }

  /**
   * Finds the first unused paragraph of the expected bundle.
   */
  private function findReusableParagraph(array $paragraphs, string $bundle, array &$used): ?ParagraphInterface {
    foreach ($paragraphs as $delta => $paragraph) {
      if (!$paragraph instanceof ParagraphInterface || isset($used[$delta])) {
        continue;
      }
      if ($paragraph->bundle() !== $bundle) {
        continue;
      }

      $used[$delta] = TRUE;
      return $paragraph;
    }

    return NULL;
  }

  /**
   * Applies translated field values to one paragraph entity.
   */
  private function applyParagraphTranslations(ParagraphInterface $paragraph, array $translations): void {
    foreach ($translations as $langcode => $values) {
      $translation = $this->getOrCreateTranslation($paragraph, $langcode);
      foreach ($values as $field_name => $value) {
        if ($translation->hasField($field_name)) {
          $translation->set($field_name, $value);
        }
      }
      if ($translation->hasField('status')) {
        $translation->set('status', TRUE);
      }
    }
  }

  /**
   * Gets an existing translation or creates it on the entity.
   */
  private function getOrCreateTranslation(ContentEntityInterface $entity, string $langcode): ContentEntityInterface {
    if ($entity->language()->getId() === $langcode) {
      return $entity;
    }

    if ($entity->hasTranslation($langcode)) {
      return $entity->getTranslation($langcode);
    }

    return $entity->addTranslation($langcode);
  }

}

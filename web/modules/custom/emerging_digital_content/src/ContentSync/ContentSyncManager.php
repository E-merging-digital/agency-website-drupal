<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\ContentSync;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\path_alias\AliasRepositoryInterface;

/**
 * Synchronizes a small allow-list of versioned content by business identifier.
 */
final class ContentSyncManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AliasRepositoryInterface $aliasRepository,
    private readonly ConfigFactoryInterface $configFactory,
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
      $report['actions'][] = sprintf('create node: %s "%s"', $definition['type'], $definition['translations']['fr']['title']);
      $report['actions'][] = 'create translation: en';

      if (!$dry_run) {
        $node = Node::create([
          'type' => $definition['type'],
          'langcode' => 'fr',
          'uid' => 1,
          'status' => TRUE,
        ]);
      }
    }
    else {
      $report['actions'][] = sprintf('update existing node: nid %s, uuid %s', $node->id(), $node->uuid());

      if ($node->bundle() !== $definition['type']) {
        $report['actions'][] = sprintf('change node bundle: %s -> %s', $node->bundle(), $definition['type']);
        if (!$dry_run) {
          $node->set('type', $definition['type']);
          $node->save();
          $node = $this->reloadNode($node);
        }
      }
    }

    if ($node instanceof NodeInterface && !$dry_run) {
      $this->applyNodeTranslations($node, $definition, $report);
      $node->save();
      $report['node_id'] = $node->id();
      $report['node_uuid'] = $node->uuid();
    }
    else {
      foreach ($definition['translations'] as $langcode => $translation_values) {
        $report['actions'][] = sprintf('update %s translation title/path/service fields: %s', $langcode, $translation_values['alias']);
      }
    }

    $this->syncPromotions($definition, $dry_run, $report);
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
        'type' => 'service',
        'business_aliases' => [
          'fr' => '/agence-drupal-belgique',
          'en' => '/drupal-agency-belgium',
        ],
        'translations' => [
          'fr' => [
            'title' => 'Agence Drupal Belgique',
            'alias' => '/agence-drupal-belgique',
            'fields' => [
              'field_short_description' => [
                'value' => 'Agence Drupal en Belgique pour PME, ASBL et institutions : audit, création, refonte, migration et maintenance de sites Drupal accessibles, performants et durables.',
                'format' => 'basic_html',
              ],
              'field_detailed_description' => [
                'value' => '<p>Emerging Digital accompagne les organisations belges qui veulent un site Drupal fiable, clair pour les équipes éditoriales et prêt pour les enjeux de performance, d’accessibilité et de référencement naturel.</p><h2>Ce que nous prenons en charge</h2><ul><li>Audit Drupal, architecture de contenu et priorisation technique.</li><li>Création ou refonte de sites Drupal pour PME, ASBL et institutions.</li><li>Migration, maintenance, sécurité et amélioration continue.</li><li>Optimisation SEO technique, performance et accessibilité.</li><li>Intégration d’usages IA utiles dans les workflows éditoriaux Drupal.</li></ul><p>La page est synchronisée avec un identifiant métier stable afin de pouvoir être rejouée sans duplication. Vous pouvez aussi consulter nos <a href="/services">services Drupal</a> et notre approche <a href="/ia-drupal">IA &amp; Drupal</a>.</p>',
                'format' => 'basic_html',
              ],
            ],
          ],
          'en' => [
            'title' => 'Drupal Agency Belgium',
            'alias' => '/drupal-agency-belgium',
            'fields' => [
              'field_short_description' => [
                'value' => 'Drupal agency in Belgium for SMEs, non-profits and institutions: audits, builds, redesigns, migrations and maintenance for accessible, performant websites.',
                'format' => 'basic_html',
              ],
              'field_detailed_description' => [
                'value' => '<p>Emerging Digital supports Belgian organisations that need a reliable Drupal website, clear editorial workflows and a strong foundation for performance, accessibility and organic search.</p><h2>What we cover</h2><ul><li>Drupal audits, content architecture and technical prioritisation.</li><li>Drupal website builds and redesigns for SMEs, non-profits and institutions.</li><li>Migration, maintenance, security and continuous improvement.</li><li>Technical SEO, performance and accessibility optimisation.</li><li>Practical AI use cases inside Drupal editorial workflows.</li></ul><p>This page is synchronized through a stable business identifier so it can be replayed without duplication. You can also explore our <a href="/services">Drupal services</a> and our <a href="/ia-drupal">AI &amp; Drupal</a> approach.</p>',
                'format' => 'basic_html',
              ],
            ],
          ],
        ],
        'promotions' => [
          [
            'target' => [
              'label' => 'Services page',
              'aliases' => [
                'fr' => '/services',
                'en' => '/services',
              ],
            ],
            'translations' => [
              'fr' => [
                'title' => 'Agence Drupal Belgique',
                'description' => 'Un accompagnement senior pour créer, refondre, migrer et maintenir des sites Drupal accessibles, performants et durables.',
                'url' => '/agence-drupal-belgique',
              ],
              'en' => [
                'title' => 'Drupal Agency Belgium',
                'description' => 'Senior support to build, redesign, migrate and maintain accessible, performant and durable Drupal websites.',
                'url' => '/drupal-agency-belgium',
              ],
            ],
          ],
          [
            'target' => [
              'label' => 'Homepage',
              'front' => TRUE,
              'aliases' => [
                'fr' => '/accueil',
              ],
            ],
            'translations' => [
              'fr' => [
                'title' => 'Agence Drupal Belgique',
                'description' => 'Un partenaire Drupal senior en Belgique pour structurer vos contenus, vos parcours et votre socle technique.',
                'url' => '/agence-drupal-belgique',
              ],
              'en' => [
                'title' => 'Drupal Agency Belgium',
                'description' => 'A senior Drupal partner in Belgium to structure your content, user journeys and technical foundation.',
                'url' => '/drupal-agency-belgium',
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
   * Applies translated service fields to the target node.
   */
  private function applyNodeTranslations(NodeInterface $node, array $definition, array &$report): void {
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

      foreach ($translation_values['fields'] as $field_name => $value) {
        if ($translation->hasField($field_name)) {
          $translation->set($field_name, $value);
        }
        else {
          $report['warnings'][] = sprintf('missing field on %s node: %s', $definition['type'], $field_name);
        }
      }
    }
  }

  /**
   * Loads the target node through stable aliases instead of packaged UUIDs.
   */
  private function loadNodeByBusinessKey(array $definition): ?NodeInterface {
    foreach ($definition['business_aliases'] as $langcode => $alias) {
      $node = $this->loadNodeByAlias($alias, $langcode);
      if ($node instanceof NodeInterface) {
        return $node;
      }
    }

    foreach ($definition['translations'] as $translation_values) {
      $nodes = $this->entityTypeManager->getStorage('node')->loadByProperties([
        'title' => $translation_values['title'],
      ]);
      $node = reset($nodes);

      if ($node instanceof NodeInterface) {
        return $node;
      }
    }

    return NULL;
  }

  /**
   * Synchronizes editorial links from the services page and homepage.
   */
  private function syncPromotions(array $definition, bool $dry_run, array &$report): void {
    foreach ($definition['promotions'] as $promotion) {
      $target = $promotion['target'];
      $page = !empty($target['front'])
        ? $this->loadFrontPageNode()
        : NULL;

      if (!$page instanceof NodeInterface && isset($target['aliases'])) {
        foreach ($target['aliases'] as $langcode => $alias) {
          $page = $this->loadNodeByAlias($alias, $langcode);
          if ($page instanceof NodeInterface) {
            break;
          }
        }
      }

      if (!$page instanceof NodeInterface) {
        $report['warnings'][] = sprintf('promotion target not found: %s', $target['label']);
        continue;
      }

      $paragraph = $this->findFirstParagraphByBundle($page, 'services');
      if (!$paragraph instanceof ParagraphInterface) {
        $report['warnings'][] = sprintf('services paragraph not found on %s', $target['label']);
        continue;
      }

      $changed = $this->applyListingCardTranslations($paragraph, $promotion['translations'], $dry_run, $report, $target['label']);
      if ($changed && !$dry_run) {
        $paragraph->save();
      }
    }
  }

  /**
   * Applies translated listing cards to a services paragraph.
   */
  private function applyListingCardTranslations(ParagraphInterface $paragraph, array $translations, bool $dry_run, array &$report, string $target_label): bool {
    $changed = FALSE;

    foreach ($translations as $langcode => $values) {
      $translation = $this->getOrCreateTranslation($paragraph, $langcode);
      if (!$translation->hasField('field_items')) {
        continue;
      }

      $current_values = $translation->get('field_items')->getValue();
      $updated_values = $this->upsertDelimitedCard($current_values, $values);

      if ($updated_values === $current_values) {
        $report['actions'][] = sprintf('keep listing card on %s (%s): %s', $target_label, $langcode, $values['title']);
        continue;
      }

      $report['actions'][] = sprintf('upsert listing card on %s (%s): %s', $target_label, $langcode, $values['title']);
      $changed = TRUE;

      if (!$dry_run) {
        $translation->set('field_items', $updated_values);
      }
    }

    return $changed;
  }

  /**
   * Updates or appends one pipe-delimited services card value.
   */
  private function upsertDelimitedCard(array $current_values, array $card): array {
    $card_value = sprintf('%s|%s|%s', $card['title'], $card['description'], $card['url']);
    $replacement = [
      'value' => $card_value,
      'format' => 'basic_html',
    ];

    foreach ($current_values as $delta => $current_value) {
      $parts = explode('|', (string) ($current_value['value'] ?? ''));
      if (trim($parts[0] ?? '') === $card['title']) {
        $current_values[$delta] = $replacement;
        return $current_values;
      }
    }

    $current_values[] = $replacement;

    return $current_values;
  }

  /**
   * Finds the first paragraph of the expected bundle on a page.
   */
  private function findFirstParagraphByBundle(NodeInterface $page, string $bundle): ?ParagraphInterface {
    if (!$page->hasField('field_home_components')) {
      return NULL;
    }

    foreach ($page->get('field_home_components')->referencedEntities() as $paragraph) {
      if ($paragraph instanceof ParagraphInterface && $paragraph->bundle() === $bundle) {
        return $paragraph;
      }
    }

    return NULL;
  }

  /**
   * Loads a node by alias and language.
   */
  private function loadNodeByAlias(string $alias, string $langcode): ?NodeInterface {
    $lookup = $this->aliasRepository->lookupByAlias($alias, $langcode);
    $path = $lookup['path'] ?? NULL;

    return is_string($path) ? $this->loadNodeBySystemPath($path) : NULL;
  }

  /**
   * Loads the configured front page node.
   */
  private function loadFrontPageNode(): ?NodeInterface {
    $front_path = $this->configFactory->get('system.site')->get('page.front');

    return is_string($front_path) ? $this->loadNodeBySystemPath($front_path) : NULL;
  }

  /**
   * Loads a node from a system path such as /node/5.
   */
  private function loadNodeBySystemPath(string $path): ?NodeInterface {
    if (!preg_match('@^/node/(\d+)$@', $path, $matches)) {
      return NULL;
    }

    $node = $this->entityTypeManager->getStorage('node')->load((int) $matches[1]);

    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Reloads a node so field definitions match a changed bundle.
   */
  private function reloadNode(NodeInterface $node): NodeInterface {
    $reloaded = $this->entityTypeManager->getStorage('node')->loadUnchanged((int) $node->id());
    if (!$reloaded instanceof NodeInterface) {
      throw new \RuntimeException(sprintf('Unable to reload node %s after bundle change.', $node->id()));
    }

    return $reloaded;
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

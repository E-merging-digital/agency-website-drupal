<?php

/**
 * @file
 * Post-update hooks for initial strategic content seeding.
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;

/**
 * Seeds strategic paragraphs on the frontpage.
 */
function agency_content_seed_post_update_frontpage_paragraphs(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $existing = $storage->loadByProperties([
    'type' => 'page',
    'title' => 'Accueil',
  ]);

  $front = $existing ? reset($existing) : Node::create([
    'type' => 'page',
    'title' => 'Accueil',
    'status' => 1,
  ]);

  $paragraphs = [];

  $paragraphs[] = _agency_content_seed_create_paragraph('hero', [
    'field_heading' => 'Des sites Drupal performants, avec l’IA intégrée au cœur de vos contenus',
    'field_text' => [
      'value' => 'Nous concevons des plateformes web durables pour PME et ASBL, en combinant expertise Drupal, structure éditoriale claire et intelligence artificielle utile.',
      'format' => 'basic_html',
    ],
    'field_link' => [
      'uri' => 'internal:/contact',
      'title' => 'Demander un audit',
    ],
    'field_secondary_link' => [
      'uri' => 'internal:/services',
      'title' => 'Découvrir nos services',
    ],
  ]);

  $paragraphs[] = _agency_content_seed_create_paragraph('text_block', [
    'field_heading' => 'Drupal solide. Expérience claire. IA utile.',
    'field_text' => [
      'value' => 'Nous aidons les organisations à structurer leur présence digitale avec Drupal, à améliorer la qualité de leurs contenus et à intégrer l’IA de manière concrète : rédaction assistée, optimisation SEO, traduction, enrichissement automatique des contenus.',
      'format' => 'basic_html',
    ],
  ]);

  $paragraphs[] = _agency_content_seed_create_paragraph('services', [
    'field_heading' => 'Nos services',
    'field_items' => [
      [
        'value' => 'Création de sites Drupal|Sites institutionnels, vitrines ou '
        . 'plateformes éditoriales robustes et évolutives, conçus pour durer.',
        'format' => 'basic_html',
      ],
      [
        'value' => 'Migration et modernisation|Reprise de sites existants, montée '
        . 'de version Drupal, amélioration de la structure et des performances.',
        'format' => 'basic_html',
      ],
      [
        'value' => 'SEO et performance|Un site rapide, lisible et pensé pour être '
        . 'trouvé par vos publics.',
        'format' => 'basic_html',
      ],
      [
        'value' => 'IA intégrée dans le CMS|Des outils concrets pour produire, '
        . 'corriger et enrichir vos contenus directement dans Drupal.',
        'format' => 'basic_html',
      ],
    ],
  ]);

  $paragraphs[] = _agency_content_seed_create_paragraph('ai_features', [
    'field_heading' => 'Ce que l’IA peut faire dans Drupal',
    'field_text' => [
      'value' => 'Nous intégrons des fonctionnalités utiles directement dans l’interface éditoriale.',
      'format' => 'basic_html',
    ],
    'field_items' => [
      ['value' => 'Correction orthographique et reformulation', 'format' => 'basic_html'],
      ['value' => 'Génération assistée de contenu', 'format' => 'basic_html'],
      ['value' => 'Traduction automatique', 'format' => 'basic_html'],
      ['value' => 'Tags automatiques pour les images', 'format' => 'basic_html'],
      ['value' => 'Suggestions SEO', 'format' => 'basic_html'],
      ['value' => 'Résumé et structuration de contenu', 'format' => 'basic_html'],
    ],
  ]);

  $paragraphs[] = _agency_content_seed_create_paragraph('case_clients', [
    'field_heading' => 'Des projets clairs, utiles et durables',
    'field_items' => [
      ['value' => 'Refonte d’un site institutionnel Drupal', 'format' => 'basic_html'],
      ['value' => 'Modernisation d’un site existant', 'format' => 'basic_html'],
      ['value' => 'Mise en valeur de services complexes', 'format' => 'basic_html'],
    ],
    'field_case_problem' => [
      ['value' => 'Site institutionnel difficile à maintenir.', 'format' => 'basic_html'],
      ['value' => 'Socle Drupal vieillissant et dette technique.', 'format' => 'basic_html'],
      ['value' => 'Offres perçues comme complexes et confuses.', 'format' => 'basic_html'],
    ],
    'field_case_solution' => [
      ['value' => 'Structure clarifiée et parcours d’édition simplifié.', 'format' => 'basic_html'],
      ['value' => 'Migration maîtrisée et assainissement technique.', 'format' => 'basic_html'],
      ['value' => 'Contenus simplifiés et hiérarchie clarifiée.', 'format' => 'basic_html'],
    ],
    'field_case_result' => [
      ['value' => 'Édition facilitée et meilleures performances.', 'format' => 'basic_html'],
      ['value' => 'Base technique stabilisée.', 'format' => 'basic_html'],
      ['value' => 'Meilleure lisibilité et meilleure conversion.', 'format' => 'basic_html'],
    ],
  ]);

  $paragraphs[] = _agency_content_seed_create_paragraph('trust_list', [
    'field_heading' => 'Pourquoi travailler avec nous',
    'field_items' => [
      ['value' => 'Expertise Drupal', 'format' => 'basic_html'],
      ['value' => 'Approche structurée', 'format' => 'basic_html'],
      ['value' => 'Vision long terme', 'format' => 'basic_html'],
      ['value' => 'IA utile, pas gadget', 'format' => 'basic_html'],
      ['value' => 'Compréhension des réalités PME / ASBL', 'format' => 'basic_html'],
    ],
  ]);

  $paragraphs[] = _agency_content_seed_create_paragraph('cta', [
    'field_heading' => 'Vous avez un projet Drupal ou un site à moderniser ?',
    'field_text' => [
      'value' => 'Parlons de votre contexte et de ce que l’IA peut réellement vous apporter.',
      'format' => 'basic_html',
    ],
    'field_link' => [
      'uri' => 'internal:/contact',
      'title' => 'Prendre contact',
    ],
  ]);

  $front->set('field_home_components', array_map(static function (Paragraph $paragraph): array {
    return [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
  }, $paragraphs));

  $front->setPublished();
  $front->save();

  \Drupal::configFactory()->getEditable('system.site')
    ->set('page.front', '/node/' . $front->id())
    ->save(TRUE);

  return 'Frontpage paragraphs have been created and assigned.';
}

/**
 * Creates and saves one paragraph entity.
 */
function _agency_content_seed_create_paragraph(string $bundle, array $values): Paragraph {
  $paragraph = Paragraph::create([
    'type' => $bundle,
    'status' => TRUE,
  ]);

  foreach ($values as $field_name => $value) {
    $paragraph->set($field_name, $value);
  }

  $paragraph->save();
  return $paragraph;
}

/**
 * Applies issue #81 editorial updates on existing strategic pages.
 */
function agency_content_seed_post_update_issue_81_editorial_repositioning(array &$sandbox): string {
  $pages = _agency_content_seed_load_strategic_pages([
    'Accueil',
    'Services',
    'IA & Drupal',
    'Cas clients',
  ]);

  if ($pages === []) {
    return 'Issue #81: no strategic pages found.';
  }

  if (isset($pages['Accueil'])) {
    _agency_content_seed_update_accueil_page($pages['Accueil']);
  }
  if (isset($pages['Services'])) {
    _agency_content_seed_update_services_page($pages['Services']);
  }
  if (isset($pages['IA & Drupal'])) {
    _agency_content_seed_update_ia_page($pages['IA & Drupal']);
  }
  if (isset($pages['Cas clients'])) {
    _agency_content_seed_update_case_clients_page($pages['Cas clients']);
  }

  return 'Issue #81 editorial repositioning updates applied.';
}

/**
 * Loads strategic pages by title.
 *
 * @return array<string, \Drupal\node\NodeInterface>
 *   Associative array keyed by title.
 */
function _agency_content_seed_load_strategic_pages(array $titles): array {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $nodes = $storage->loadByProperties([
    'type' => 'page',
    'title' => $titles,
  ]);

  $indexed = [];
  foreach ($nodes as $node) {
    if ($node instanceof NodeInterface) {
      $indexed[$node->label()] = $node;
    }
  }

  return $indexed;
}

/**
 * Applies paragraph updates for the Accueil page.
 */
function _agency_content_seed_update_accueil_page(NodeInterface $node): void {
  $map = [
    'hero' => [
      'field_heading' => 'Drupal pour projets structurés, accessibles et évolutifs',
      'field_text' => 'Nous accompagnons PME, ASBL et organisations publiques dans des projets Drupal robustes, avec une architecture éditoriale claire, des exigences d’accessibilité web et une IA utile au quotidien.',
      'field_secondary_link_title' => 'Explorer les services Drupal',
    ],
    'text_block' => [
      'field_heading' => 'Positionnement éditorial clair pour environnements exigeants',
      'field_text' => 'Notre approche Drupal s’adresse aux contextes structurés et institutionnels : gouvernance de contenu, parcours d’édition fiables, accessibilité web et intégration d’outils IA pour la rédaction assistée, le SEO éditorial et l’ouverture vers la traduction automatique.',
    ],
    'services' => [
      'field_items' => [
        'Création de sites Drupal|Conception de plateformes institutionnelles, PME ou ASBL avec une structure solide, maintenable et pensée pour durer.',
        'Migration et modernisation|Reprise de sites existants, montée de version Drupal, amélioration de la structure et des performances.',
        'Accessibilité, SEO et performance|Contenus lisibles, parcours clairs et socle technique optimisé pour vos publics et les moteurs de recherche.',
        'IA intégrée dans le CMS|Aide à la rédaction, amélioration de la qualité éditoriale, enrichissement et préparation à la traduction automatique des contenus.',
      ],
    ],
    'ai_features' => [
      'field_text' => 'Des usages IA concrets, intégrés à Drupal, pour aider les équipes éditoriales sans complexifier le travail quotidien.',
      'field_item_contains' => [
        'Traduction automatique' => 'Préparation à la traduction automatique multilingue',
      ],
    ],
    'trust_list' => [
      'field_item_contains' => [
        'Approche structurée' => 'Expérience des contextes institutionnels',
        'Vision long terme' => 'Maîtrise des enjeux d’accessibilité web',
      ],
    ],
    'cta' => [
      'field_text' => 'Parlons de votre contexte Drupal et de vos objectifs d’accessibilité. Vous pouvez aussi consulter nos <a href="/services">services Drupal</a> et notre approche <a href="/ia-drupal">IA &amp; Drupal</a>.',
    ],
  ];

  _agency_content_seed_apply_updates_on_page_paragraphs($node, $map);
}

/**
 * Applies paragraph updates for the Services page.
 */
function _agency_content_seed_update_services_page(NodeInterface $node): void {
  $map = [
    'hero' => [
      'field_heading' => 'Services Drupal pour projets structurés et institutionnels',
      'field_text' => 'Nous accompagnons PME, ASBL et organisations publiques dans la création, la modernisation et l’évolution de sites Drupal robustes, accessibles et éditorialement maîtrisés.',
    ],
    'text_block' => [
      'field_text' => 'Votre site doit rester clair, fiable et évolutif. Nous structurons vos contenus, sécurisons la base technique Drupal et intégrons les exigences d’accessibilité dès la conception.',
      'field_heading_from' => [
        'Pourquoi Drupal' => 'Pourquoi Drupal pour des projets exigeants',
      ],
      'field_text_from' => [
        'Drupal est une plateforme robuste, flexible et idéale pour gérer des contenus complexes de manière structurée.' => 'Drupal est une plateforme robuste et flexible, particulièrement adaptée aux projets institutionnels et aux écosystèmes de contenus complexes, avec des besoins d’accessibilité et de gouvernance.',
      ],
    ],
    'services' => [
      'field_items' => [
        'Création de site Drupal|Conception de sites sur mesure pour institutions, PME et ASBL, avec une structure éditoriale claire et durable.',
        'Migration Drupal|Mise à jour de versions anciennes, sécurisation et modernisation de votre plateforme.',
        'Maintenance et évolutions|Suivi technique, améliorations continues et support.',
        'Accessibilité, SEO et optimisation|Amélioration de la lisibilité, du référencement naturel et des performances techniques.',
        'IA intégrée|Automatisation de tâches éditoriales utiles, amélioration de la qualité et préparation à la traduction automatique.',
      ],
    ],
    'cta' => [
      'field_text' => 'Parlons de votre projet et identifions la solution la plus adaptée. Consultez aussi notre page <a href="/ia-drupal">IA &amp; Drupal</a> pour les usages éditoriaux.',
    ],
  ];

  _agency_content_seed_apply_updates_on_page_paragraphs($node, $map);
}

/**
 * Applies paragraph updates for the IA & Drupal page.
 */
function _agency_content_seed_update_ia_page(NodeInterface $node): void {
  $map = [
    'hero' => [
      'field_heading' => 'IA utile dans Drupal pour les équipes éditoriales',
      'field_text' => 'Des fonctionnalités IA concrètes pour améliorer la qualité des contenus, la cohérence éditoriale et la productivité, dans un cadre maîtrisé.',
    ],
    'text_block' => [
      'field_text_from' => [
        'L’IA ne remplace pas votre expertise, elle l’amplifie. Nous intégrons des outils utiles directement dans votre CMS.' => 'L’IA ne remplace pas l’expertise métier : elle la renforce. Nous intégrons des usages utiles directement dans Drupal, avec attention portée à l’accessibilité web et à la qualité éditoriale.',
      ],
      'field_heading_from' => [
        'Intégration' => 'Intégration dans vos processus CMS',
      ],
      'field_text_heading' => [
        'Intégration dans vos processus CMS' => 'Les outils sont intégrés dans l’interface Drupal, sans rupture pour les équipes. Le dispositif reste gouvernable pour des contextes PME, ASBL et institutionnels.',
      ],
    ],
    'ai_features' => [
      'field_heading' => 'Cas d’usage IA dans Drupal',
      'field_item_contains' => [
        'Traduction :' => 'Traduction : Préparation et automatisation progressive des contenus multilingues.',
      ],
    ],
    'trust_list' => [
      'field_item_contains' => [
        'Accessibilité améliorée' => 'Accessibilité éditoriale renforcée',
      ],
    ],
    'cta' => [
      'field_text' => 'Découvrez comment relier vos objectifs éditoriaux à des usages IA concrets. Voir aussi nos <a href="/services">services Drupal</a> et des <a href="/cas-clients">cas clients</a>.',
    ],
  ];

  _agency_content_seed_apply_updates_on_page_paragraphs($node, $map);
}

/**
 * Applies paragraph updates for the Cas clients page.
 */
function _agency_content_seed_update_case_clients_page(NodeInterface $node): void {
  $map = [
    'hero' => [
      'field_heading' => 'Cas clients Drupal sur des contextes structurés',
    ],
    'text_block' => [
      'field_text' => 'Chaque projet répond à des besoins réels avec une approche pragmatique : robustesse Drupal, clarté éditoriale, accessibilité et intégration d’IA utile.',
    ],
    'case_clients' => [
      'field_item_contains' => [
        'Refonte d’un site' => 'Refonte d’un site institutionnel',
        'Structuration de contenu' => 'Structuration éditoriale accessible',
      ],
      'field_problem_contains' => [
        'site difficile à maintenir' => 'Site difficile à maintenir et à faire évoluer',
        'contenu confus' => 'Contenus peu lisibles pour les publics',
      ],
      'field_solution_contains' => [
        'architecture claire' => 'Architecture claire et règles éditoriales partagées',
      ],
      'field_result_contains' => [
        'meilleure lisibilité' => 'Meilleure lisibilité et accessibilité web',
      ],
    ],
    'cta' => [
      'field_text' => 'Vous avez un projet similaire ? Consultez nos <a href="/services">services</a> ou notre page <a href="/ia-drupal">IA &amp; Drupal</a>, puis discutons-en.',
    ],
  ];

  _agency_content_seed_apply_updates_on_page_paragraphs($node, $map);
}

/**
 * Updates paragraph data for a strategic page.
 */
function _agency_content_seed_apply_updates_on_page_paragraphs(NodeInterface $node, array $map): void {
  if (!$node->hasField('field_home_components')) {
    return;
  }

  $components = $node->get('field_home_components')->referencedEntities();
  foreach ($components as $paragraph) {
    if (!$paragraph instanceof ParagraphInterface) {
      continue;
    }
    $bundle = $paragraph->bundle();
    if (!isset($map[$bundle])) {
      continue;
    }
    _agency_content_seed_update_paragraph($paragraph, $map[$bundle]);
  }
}

/**
 * Updates one paragraph according to provided rules.
 */
function _agency_content_seed_update_paragraph(ParagraphInterface $paragraph, array $rules): void {
  if (isset($rules['field_heading']) && $paragraph->hasField('field_heading')) {
    $paragraph->set('field_heading', ['value' => $rules['field_heading']]);
  }

  if (isset($rules['field_text']) && $paragraph->hasField('field_text')) {
    $paragraph->set('field_text', [
      'value' => $rules['field_text'],
      'format' => 'basic_html',
    ]);
  }

  if (isset($rules['field_secondary_link_title']) && $paragraph->hasField('field_secondary_link')) {
    $link = $paragraph->get('field_secondary_link')->first();
    if ($link) {
      $paragraph->set('field_secondary_link', [
        'uri' => $link->get('uri')->getString(),
        'title' => $rules['field_secondary_link_title'],
      ]);
    }
  }

  if (isset($rules['field_items']) && $paragraph->hasField('field_items')) {
    $items = [];
    foreach ($rules['field_items'] as $item) {
      $items[] = ['value' => $item, 'format' => 'basic_html'];
    }
    $paragraph->set('field_items', $items);
  }

  if (isset($rules['field_item_contains']) && $paragraph->hasField('field_items')) {
    _agency_content_seed_replace_item_values($paragraph, 'field_items', $rules['field_item_contains']);
  }
  if (isset($rules['field_problem_contains']) && $paragraph->hasField('field_case_problem')) {
    _agency_content_seed_replace_item_values($paragraph, 'field_case_problem', $rules['field_problem_contains']);
  }
  if (isset($rules['field_solution_contains']) && $paragraph->hasField('field_case_solution')) {
    _agency_content_seed_replace_item_values($paragraph, 'field_case_solution', $rules['field_solution_contains']);
  }
  if (isset($rules['field_result_contains']) && $paragraph->hasField('field_case_result')) {
    _agency_content_seed_replace_item_values($paragraph, 'field_case_result', $rules['field_result_contains']);
  }

  if (isset($rules['field_heading_from']) && $paragraph->hasField('field_heading')) {
    $current_heading = (string) $paragraph->get('field_heading')->value;
    foreach ($rules['field_heading_from'] as $from => $to) {
      if ($current_heading === $from) {
        $paragraph->set('field_heading', ['value' => $to]);
      }
    }
  }

  if (isset($rules['field_text_from']) && $paragraph->hasField('field_text')) {
    $current_text = (string) $paragraph->get('field_text')->value;
    foreach ($rules['field_text_from'] as $from => $to) {
      if ($current_text === $from) {
        $paragraph->set('field_text', ['value' => $to, 'format' => 'basic_html']);
      }
    }
  }

  if (isset($rules['field_text_heading']) && $paragraph->hasField('field_heading') && $paragraph->hasField('field_text')) {
    $heading = (string) $paragraph->get('field_heading')->value;
    if (isset($rules['field_text_heading'][$heading])) {
      $paragraph->set('field_text', [
        'value' => $rules['field_text_heading'][$heading],
        'format' => 'basic_html',
      ]);
    }
  }

  $paragraph->save();
}

/**
 * Replaces values in a multi-value text field when source contains a needle.
 */
function _agency_content_seed_replace_item_values(ParagraphInterface $paragraph, string $field_name, array $replace_map): void {
  $items = $paragraph->get($field_name)->getValue();
  foreach ($items as &$item) {
    $current_value = $item['value'] ?? '';
    foreach ($replace_map as $contains => $replacement) {
      if (str_contains((string) $current_value, (string) $contains)) {
        $item['value'] = $replacement;
        $item['format'] = 'basic_html';
      }
    }
  }
  $paragraph->set($field_name, $items);
}

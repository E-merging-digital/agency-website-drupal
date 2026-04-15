<?php
/**
 * @file
 * Post-update hooks for initial strategic content seeding.
 */

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

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

  $front->setPublished(TRUE);
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

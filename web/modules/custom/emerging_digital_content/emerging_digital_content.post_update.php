<?php

/**
 * @file
 * Post-update hooks for Emerging Digital Content.
 */

declare(strict_types=1);

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Imports packaged default content for already-installed environments.
 */
function emerging_digital_content_post_update_import_default_content(array &$sandbox): string {
  unset($sandbox);

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $existing = $node_storage->loadByProperties([
    'type' => 'page',
    'title' => 'Accueil',
  ]);

  if (!$existing) {
    _emerging_digital_content_purge_stale_entities();

    /** @var \Drupal\default_content\ImporterInterface $importer */
    $importer = \Drupal::service('default_content.importer');
    $importer->importContent('emerging_digital_content');
  }

  \Drupal::configFactory()->getEditable('system.site')
    ->set('page.front', '/')
    ->save(TRUE);

  \Drupal::state()->set('system.maintenance_mode', 0);

  return 'Default content for emerging_digital_content has been imported or was already present.';
}

/**
 * Creates missing main navigation links for the public website pages.
 */
function emerging_digital_content_post_update_main_navigation_links(array &$sandbox): string {
  unset($sandbox);

  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  $links = [
    ['title' => 'Accueil', 'uri' => 'internal:/', 'weight' => 0],
    ['title' => 'Services', 'uri' => 'internal:/services', 'weight' => 1],
    ['title' => 'IA & Drupal', 'uri' => 'internal:/ia-drupal', 'weight' => 2],
    ['title' => 'Cas clients', 'uri' => 'internal:/cas-clients', 'weight' => 3],
    ['title' => 'Contact', 'uri' => 'internal:/contact', 'weight' => 4],
  ];

  $created = 0;
  foreach ($links as $link) {
    $candidate_ids = \Drupal::entityQuery('menu_link_content')
      ->accessCheck(FALSE)
      ->condition('menu_name', 'main')
      ->condition('title', $link['title'])
      ->execute();

    if ($candidate_ids) {
      $candidates = $storage->loadMultiple($candidate_ids);
      foreach ($candidates as $candidate) {
        $link_item = $candidate->get('link')->first();
        if ($link_item && ($link_item->getValue()['uri'] ?? '') === $link['uri']) {
          continue 2;
        }
      }
    }

    MenuLinkContent::create([
      'title' => $link['title'],
      'menu_name' => 'main',
      'link' => ['uri' => $link['uri']],
      'expanded' => FALSE,
      'enabled' => TRUE,
      'weight' => $link['weight'],
    ])->save();

    $created++;
  }

  return sprintf('%d main navigation links were created.', $created);
}

/**
 * Normalizes main navigation homepage links in French and removes duplicates.
 */
function emerging_digital_content_post_update_main_navigation_home_link_cleanup(array &$sandbox): string {
  unset($sandbox);

  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  $ids = \Drupal::entityQuery('menu_link_content')
    ->accessCheck(FALSE)
    ->condition('menu_name', 'main')
    ->condition('title', ['Accueil', 'Home'], 'IN')
    ->execute();

  if (!$ids) {
    return 'No homepage links found in main navigation.';
  }

  $links = $storage->loadMultiple($ids);
  $kept_accueil = NULL;
  $removed = 0;
  $updated = 0;

  foreach ($links as $link) {
    $title = (string) $link->label();
    $uri = (string) ($link->get('link')->first()->getValue()['uri'] ?? '');

    if ($title === 'Home') {
      $link->delete();
      $removed++;
      continue;
    }

    if ($title === 'Accueil') {
      if ($kept_accueil) {
        $link->delete();
        $removed++;
        continue;
      }

      $kept_accueil = $link->id();
      if ($uri !== 'internal:/') {
        $link->set('link', ['uri' => 'internal:/']);
        $updated++;
      }
      if ((int) $link->get('weight')->value !== 0) {
        $link->set('weight', 0);
        $updated++;
      }
      $link->save();
    }
  }

  \Drupal::configFactory()->getEditable('system.site')
    ->set('page.front', '/')
    ->save(TRUE);

  return sprintf('%d homepage links removed, %d homepage settings updated.', $removed, $updated);
}

/**
 * Ensures only one French homepage link remains in the main navigation.
 */
function emerging_digital_content_post_update_main_navigation_deduplicate_home_link(array &$sandbox): string {
  unset($sandbox);

  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  $ids = \Drupal::entityQuery('menu_link_content')
    ->accessCheck(FALSE)
    ->condition('menu_name', 'main')
    ->condition('title', ['Accueil', 'Home'], 'IN')
    ->execute();

  if (!$ids) {
    return 'No Accueil/Home links found in main navigation.';
  }

  $links = $storage->loadMultiple($ids);
  $kept_id = NULL;
  $removed = 0;
  $updated = 0;

  foreach ($links as $link) {
    $uri = (string) ($link->get('link')->first()->getValue()['uri'] ?? '');
    if ($uri !== 'internal:/') {
      continue;
    }

    $title = (string) $link->label();
    if ($kept_id === NULL) {
      $kept_id = $link->id();
      if ($title !== 'Accueil') {
        $link->set('title', 'Accueil');
        $updated++;
      }
      if ((int) $link->get('weight')->value !== 0) {
        $link->set('weight', 0);
        $updated++;
      }
      if ($updated > 0) {
        $link->save();
      }
      continue;
    }

    $link->delete();
    $removed++;
  }

  return sprintf('%d homepage links removed, %d homepage links updated.', $removed, $updated);
}

/**
 * Deduplicates home links using broader matching (title and URI variants).
 */
function emerging_digital_content_post_update_main_navigation_deduplicate_home_link_v2(array &$sandbox): string {
  unset($sandbox);

  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  $ids = \Drupal::entityQuery('menu_link_content')
    ->accessCheck(FALSE)
    ->condition('menu_name', 'main')
    ->execute();

  if (!$ids) {
    return 'No links found in main navigation.';
  }

  $links = $storage->loadMultiple($ids);
  $kept_id = NULL;
  $removed = 0;
  $updated = 0;

  foreach ($links as $link) {
    $title = mb_strtolower(trim((string) $link->label()));
    $uri = (string) ($link->get('link')->first()->getValue()['uri'] ?? '');
    $is_home_title = in_array($title, ['accueil', 'home'], TRUE);
    $is_front_uri = in_array($uri, ['internal:/', 'route:<front>', 'internal:/accueil'], TRUE);

    if (!$is_home_title && !$is_front_uri) {
      continue;
    }

    if ($kept_id === NULL) {
      $kept_id = $link->id();

      if ($link->label() !== 'Accueil') {
        $link->set('title', 'Accueil');
        $updated++;
      }
      if ($uri !== 'internal:/') {
        $link->set('link', ['uri' => 'internal:/']);
        $updated++;
      }
      if ((int) $link->get('weight')->value !== 0) {
        $link->set('weight', 0);
        $updated++;
      }

      if ($updated > 0) {
        $link->save();
      }
      continue;
    }

    $link->delete();
    $removed++;
  }

  return sprintf('%d homepage links removed, %d homepage links updated.', $removed, $updated);
}

/**
 * Harmonise la page Contact et supprime le CTA redondant.
 */
function emerging_digital_content_post_update_contact_page_professional_layout(array &$sandbox): string {
  unset($sandbox);

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $existing = $node_storage->loadByProperties([
    'type' => 'page',
    'title' => 'Contact',
  ]);

  if (!$existing) {
    return 'No Contact page found.';
  }

  /** @var \Drupal\node\Entity\Node $contact */
  $contact = reset($existing);
  $components = $contact->get('field_home_components')->referencedEntities();

  $hero = NULL;
  $intro = NULL;
  $coordinates = NULL;
  $information = NULL;
  $form = NULL;
  $map = NULL;
  $extra_components = [];
  $removed_cta = 0;

  foreach ($components as $paragraph) {
    if (!$paragraph instanceof Paragraph) {
      continue;
    }

    $bundle = $paragraph->bundle();
    $heading = trim((string) $paragraph->get('field_heading')->value);

    if ($bundle === 'cta') {
      $removed_cta++;
      continue;
    }

    if ($bundle === 'hero' && $hero === NULL) {
      $hero = $paragraph;
      continue;
    }

    if ($bundle === 'text_block') {
      if ($paragraph->uuid() === '855b08da-0ec9-4261-883a-d27f214606e6' || $heading === 'Formulaire') {
        $form = $paragraph;
        continue;
      }

      if ($heading === 'Coordonnées') {
        $coordinates = $paragraph;
        continue;
      }

      if ($heading === 'Carte') {
        $map = $paragraph;
        continue;
      }

      if ($heading === 'Informations') {
        $information = $paragraph;
        continue;
      }

      if ($intro === NULL) {
        $intro = $paragraph;
        continue;
      }
    }

    $extra_components[] = $paragraph;
  }

  $intro_values = _emerging_digital_content_contact_intro_values();
  if ($intro === NULL) {
    $intro = Paragraph::create($intro_values);
    $intro->save();
  }
  else {
    foreach ($intro_values as $field_name => $value) {
      $intro->set($field_name, $value);
    }
    $intro->save();
  }

  $coordinates_values = _emerging_digital_content_contact_coordinates_values();
  if ($coordinates === NULL) {
    $coordinates = Paragraph::create($coordinates_values);
    $coordinates->save();
  }
  else {
    foreach ($coordinates_values as $field_name => $value) {
      $coordinates->set($field_name, $value);
    }
    $coordinates->save();
  }

  $map_values = _emerging_digital_content_contact_map_values();
  if ($map === NULL) {
    $map = Paragraph::create($map_values);
    $map->save();
  }
  else {
    foreach ($map_values as $field_name => $value) {
      $map->set($field_name, $value);
    }
    $map->save();
  }

  $information_values = _emerging_digital_content_contact_information_values();
  if ($information === NULL) {
    $information = Paragraph::create($information_values);
    $information->save();
  }
  else {
    foreach ($information_values as $field_name => $value) {
      $information->set($field_name, $value);
    }
    $information->save();
  }

  $ordered_components = array_values(array_filter([
    $hero,
    $intro,
    $coordinates,
    $information,
    $form,
    $map,
    ...$extra_components,
  ]));

  $contact->set('field_home_components', array_map(static function (Paragraph $paragraph): array {
    return [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
  }, $ordered_components));
  $contact->save();

  return sprintf('Contact page updated. Removed %d redundant CTA block(s).', $removed_cta);
}

/**
 * Rejoue l'harmonisation de la page Contact sur les environnements existants.
 */
function emerging_digital_content_post_update_contact_page_professional_layout_v2(array &$sandbox): string {
  return emerging_digital_content_post_update_contact_page_professional_layout($sandbox);
}

/**
 * Rejoue l'harmonisation Contact avec le bloc Informations de réassurance.
 */
function emerging_digital_content_post_update_contact_page_professional_layout_v3(array &$sandbox): string {
  return emerging_digital_content_post_update_contact_page_professional_layout($sandbox);
}

/**
 * Rejoue l'harmonisation Contact avec contenu Informations enrichi.
 */
function emerging_digital_content_post_update_contact_page_professional_layout_v4(array &$sandbox): string {
  return emerging_digital_content_post_update_contact_page_professional_layout($sandbox);
}

/**
 * Valeurs de la section intro de la page Contact.
 */
function _emerging_digital_content_contact_intro_values(): array {
  return [
    'type' => 'text_block',
    'status' => TRUE,
    'field_heading' => 'Intro',
    'field_text' => [
      'value' => '<p>Parlons de votre projet digital. Décrivez vos objectifs : nous revenons vers vous avec une réponse claire, structurée et orientée résultats.</p>',
      'format' => 'basic_html',
    ],
  ];
}

/**
 * Valeurs de la section coordonnées de la page Contact.
 */
function _emerging_digital_content_contact_coordinates_values(): array {
  return [
    'type' => 'text_block',
    'status' => TRUE,
    'field_heading' => 'Coordonnées',
    'field_text' => [
      'value' => '<p><strong>Adresse :</strong> Rue des Peupliers 1, 4254 Ligney (Geer)</p><p><strong>Email :</strong> <a href="mailto:jonathan@emergingdigital.be">jonathan@emergingdigital.be</a></p><p><strong>Téléphone :</strong> <a href="tel:+32475722884">+32 475/72.28.84</a></p><p>E-MERGING DIGITAL SRL<br>BE 0746.356.206</p>',
      'format' => 'basic_html',
    ],
  ];
}

/**
 * Valeurs de la section carte de la page Contact.
 */
function _emerging_digital_content_contact_map_values(): array {
  return [
    'type' => 'text_block',
    'status' => TRUE,
    'field_heading' => 'Carte',
    'field_text' => [
      'value' => '<iframe title="Localisation Emerging Digital" src="https://www.google.com/maps?q=Rue%20des%20Peupliers%201%2C%204254%20Ligney%20(Geer)&output=embed" width="100%" height="380" style="border:0;" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>',
      'format' => 'basic_html',
    ],
  ];
}

/**
 * Valeurs de la section informations de la page Contact.
 */
function _emerging_digital_content_contact_information_values(): array {
  return [
    'type' => 'text_block',
    'status' => TRUE,
    'field_heading' => 'Informations',
    'field_text' => [
      'value' => '<p>Disponible pour projets en Wallonie et Bruxelles.</p><p>Interventions pour PME, ASBL et organisations publiques.</p><p>Habitué à travailler sur des projets structurés et exigeants.</p><p>Premier échange sans engagement.</p>',
      'format' => 'basic_html',
    ],
  ];
}

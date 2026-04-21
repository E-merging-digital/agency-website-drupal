<?php

/**
 * @file
 * Post-update hooks for Emerging Digital Content.
 */

declare(strict_types=1);

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
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

/**
 * Creates legal notice page and legal footer links on active environments.
 */
function emerging_digital_content_post_update_legal_notice_and_footer_links(array &$sandbox): string {
  unset($sandbox);

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  $existing_pages = $node_storage->loadByProperties([
    'type' => 'page',
    'title' => 'Mentions légales',
  ]);

  /** @var \Drupal\node\Entity\Node $legal_page */
  $legal_page = $existing_pages ? reset($existing_pages) : Node::create([
    'type' => 'page',
    'title' => 'Mentions légales',
    'status' => 1,
  ]);

  $legal_body = <<<HTML
<h2>Éditeur du site</h2>
<p><strong>Nom légal complet&nbsp;:</strong> E-MERGING DIGITAL SRL<br>
<strong>Numéro d’entreprise&nbsp;:</strong> BE 0746.356.206<br>
<strong>Adresse du siège&nbsp;:</strong> Rue des Peupliers 1, 4254 Ligney (Geer), Belgique</p>
<h2>Contact</h2>
<p><strong>Email&nbsp;:</strong> <a href="mailto:jonathan@emergingdigital.be">jonathan@emergingdigital.be</a><br>
<strong>Téléphone&nbsp;:</strong> <a href="tel:+32475722884">+32 475 72 28 84</a></p>
<h2>Hébergement</h2>
<p>Informations d’hébergement disponibles sur demande.</p>
HTML;

  if ($legal_page->hasField('body')) {
    $legal_page->set('body', [
      'value' => $legal_body,
      'format' => 'basic_html',
    ]);
  }
  elseif ($legal_page->hasField('field_home_components')) {
    $legal_paragraph = NULL;
    foreach ($legal_page->get('field_home_components')->referencedEntities() as $component) {
      if ($component->bundle() !== 'text_block') {
        continue;
      }
      if ((string) $component->get('field_heading')->value !== 'Mentions légales') {
        continue;
      }
      $legal_paragraph = $component;
      break;
    }

    $legal_paragraph = $legal_paragraph ?? Paragraph::create([
      'type' => 'text_block',
      'status' => TRUE,
    ]);
    $legal_paragraph->set('field_heading', 'Mentions légales');
    $legal_paragraph->set('field_text', [
      'value' => $legal_body,
      'format' => 'basic_html',
    ]);
    $legal_paragraph->save();

    $legal_page->set('field_home_components', [
      [
        'target_id' => $legal_paragraph->id(),
        'target_revision_id' => $legal_paragraph->getRevisionId(),
      ],
    ]);
  }

  if ($legal_page->hasField('path')) {
    $legal_page->set('path', [
      'alias' => '/mentions-legales',
      'pathauto' => 0,
    ]);
  }
  $legal_page->setPublished();
  $legal_page->save();

  $menu_links = [
    [
      'title' => 'Mentions légales',
      'uri' => 'internal:/mentions-legales',
      'weight' => 0,
      'enabled' => TRUE,
    ],
    [
      'title' => 'Politique de confidentialité',
      'uri' => 'internal:/politique-confidentialite',
      'weight' => 1,
      'enabled' => FALSE,
    ],
    [
      'title' => 'Cookies',
      'uri' => 'internal:/cookies',
      'weight' => 2,
      'enabled' => FALSE,
    ],
  ];

  $created = 0;
  $updated = 0;

  foreach ($menu_links as $menu_link) {
    $ids = \Drupal::entityQuery('menu_link_content')
      ->accessCheck(FALSE)
      ->condition('menu_name', 'footer')
      ->condition('title', $menu_link['title'])
      ->execute();

    if ($ids) {
      /** @var \Drupal\menu_link_content\Entity\MenuLinkContent[] $links */
      $links = $link_storage->loadMultiple($ids);
      $kept = FALSE;

      foreach ($links as $link) {
        if (!$kept) {
          $link->set('link', ['uri' => $menu_link['uri']]);
          $link->set('weight', $menu_link['weight']);
          $link->set('enabled', $menu_link['enabled']);
          $link->save();
          $updated++;
          $kept = TRUE;
          continue;
        }

        $link->delete();
        $updated++;
      }

      continue;
    }

    MenuLinkContent::create([
      'title' => $menu_link['title'],
      'menu_name' => 'footer',
      'link' => ['uri' => $menu_link['uri']],
      'expanded' => FALSE,
      'enabled' => $menu_link['enabled'],
      'weight' => $menu_link['weight'],
    ])->save();

    $created++;
  }

  return sprintf('Legal page ensured, %d footer links created, %d footer links updated.', $created, $updated);
}

/**
 * Creates the cookie policy page and exposes it in the footer menu.
 */
function emerging_digital_content_post_update_cookie_policy_page(array &$sandbox): string {
  unset($sandbox);

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  $existing_pages = $node_storage->loadByProperties([
    'type' => 'page',
    'title' => 'Politique de cookies',
  ]);

  /** @var \Drupal\node\Entity\Node $cookie_page */
  $cookie_page = $existing_pages ? reset($existing_pages) : Node::create([
    'type' => 'page',
    'title' => 'Politique de cookies',
    'status' => 1,
  ]);

  $cookie_body = <<<HTML
<p>Cette page explique simplement comment notre site utilise des cookies et des contenus externes. Le but est de vous informer clairement, sans jargon.</p>
<h2>Pourquoi utilisons-nous des cookies&nbsp;?</h2>
<p>Les cookies nous aident à faire fonctionner le site correctement et à vous proposer une expérience stable. Nous ne les utilisons pas pour collecter des données inutiles.</p>
<h2>Cookies nécessaires</h2>
<p>Ces cookies sont indispensables au fonctionnement technique du site (sécurité, préférences de base, stabilité d’affichage). Sans eux, certaines fonctionnalités peuvent ne pas fonctionner correctement.</p>
<h2>Contenus externes et services tiers</h2>
<p>Certaines pages peuvent afficher des contenus provenant de services tiers. Par exemple, nous utilisons Google Maps sur la page de contact pour afficher notre localisation. Lors de l’affichage de cette carte, Google peut déposer des cookies ou traiter certaines données techniques (comme votre adresse IP), selon sa propre politique de confidentialité.</p>
<h2>Comment gérer votre consentement&nbsp;?</h2>
<p>Vous pouvez accepter ou refuser les cookies non nécessaires via le bandeau cookies affiché sur le site. Vous pouvez aussi modifier vos choix à tout moment en rouvrant ce gestionnaire de consentement, ou en configurant votre navigateur pour bloquer ou supprimer les cookies.</p>
HTML;

  if ($cookie_page->hasField('body')) {
    $cookie_page->set('body', [
      'value' => $cookie_body,
      'format' => 'basic_html',
    ]);
  }
  elseif ($cookie_page->hasField('field_home_components')) {
    $cookie_paragraph = NULL;
    foreach ($cookie_page->get('field_home_components')->referencedEntities() as $component) {
      if ($component->bundle() !== 'text_block') {
        continue;
      }
      if ((string) $component->get('field_heading')->value !== 'Politique de cookies') {
        continue;
      }
      $cookie_paragraph = $component;
      break;
    }

    $cookie_paragraph = $cookie_paragraph ?? Paragraph::create([
      'type' => 'text_block',
      'status' => TRUE,
    ]);
    $cookie_paragraph->set('field_heading', 'Politique de cookies');
    $cookie_paragraph->set('field_text', [
      'value' => $cookie_body,
      'format' => 'basic_html',
    ]);
    $cookie_paragraph->save();

    $cookie_page->set('field_home_components', [
      [
        'target_id' => $cookie_paragraph->id(),
        'target_revision_id' => $cookie_paragraph->getRevisionId(),
      ],
    ]);
  }

  if ($cookie_page->hasField('path')) {
    $cookie_page->set('path', [
      'alias' => '/cookies',
      'pathauto' => 0,
    ]);
  }
  $cookie_page->setPublished();
  $cookie_page->save();

  $ids = \Drupal::entityQuery('menu_link_content')
    ->accessCheck(FALSE)
    ->condition('menu_name', 'footer')
    ->condition('title', ['Cookies', 'Politique de cookies'], 'IN')
    ->execute();

  $updated = 0;
  $created = 0;

  if ($ids) {
    /** @var \Drupal\menu_link_content\Entity\MenuLinkContent[] $links */
    $links = $link_storage->loadMultiple($ids);
    $kept = FALSE;

    foreach ($links as $link) {
      if (!$kept) {
        $link->set('title', 'Politique de cookies');
        $link->set('link', ['uri' => 'internal:/cookies']);
        $link->set('enabled', TRUE);
        $link->set('weight', 2);
        $link->save();
        $updated++;
        $kept = TRUE;
        continue;
      }

      $link->delete();
      $updated++;
    }
  }
  else {
    MenuLinkContent::create([
      'title' => 'Politique de cookies',
      'menu_name' => 'footer',
      'link' => ['uri' => 'internal:/cookies'],
      'expanded' => FALSE,
      'enabled' => TRUE,
      'weight' => 2,
    ])->save();
    $created++;
  }

  return sprintf('Cookie policy page ensured, %d footer links created, %d footer links updated.', $created, $updated);
}

/**
 * Ensures privacy policy page, footer link, and contact webform consent field.
 */
function emerging_digital_content_post_update_privacy_policy_and_contact_consent(array &$sandbox): string {
  unset($sandbox);

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $link_storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  $existing_pages = $node_storage->loadByProperties([
    'type' => 'page',
    'title' => 'Politique de confidentialité',
  ]);

  /** @var \Drupal\node\Entity\Node $privacy_page */
  $privacy_page = $existing_pages ? reset($existing_pages) : Node::create([
    'type' => 'page',
    'title' => 'Politique de confidentialité',
    'status' => 1,
  ]);

  $privacy_body = <<<HTML
<p>Cette page explique de manière claire comment nous traitons les données personnelles envoyées via notre formulaire de contact.</p>
<h2>Données collectées</h2>
<p>Lorsque vous nous contactez, nous collectons uniquement les informations que vous nous transmettez dans le formulaire&nbsp;: votre nom, votre adresse e-mail et votre message.</p>
<h2>Finalités du traitement</h2>
<p>Ces données sont utilisées uniquement pour répondre à votre demande et assurer le suivi de nos échanges.</p>
<h2>Base légale</h2>
<p>Le traitement repose sur votre consentement explicite, donné au moment de l’envoi du formulaire de contact.</p>
<h2>Durée de conservation</h2>
<p>Les données sont conservées pendant une durée maximale de 12 mois après le dernier échange, puis supprimées.</p>
<h2>Vos droits</h2>
<p>Conformément au RGPD, vous pouvez demander l’accès à vos données, leur rectification, leur suppression, la limitation du traitement ou vous opposer au traitement lorsque cela est applicable.</p>
<h2>Contact RGPD</h2>
<p>Pour toute demande relative à vos données personnelles&nbsp;: <a href="mailto:rgpd@emergingdigital.be">rgpd@emergingdigital.be</a>.</p>
HTML;

  if ($privacy_page->hasField('body')) {
    $privacy_page->set('body', [
      'value' => $privacy_body,
      'format' => 'basic_html',
    ]);
  }
  elseif ($privacy_page->hasField('field_home_components')) {
    $privacy_paragraph = NULL;
    foreach ($privacy_page->get('field_home_components')->referencedEntities() as $component) {
      if ($component->bundle() !== 'text_block') {
        continue;
      }
      if ((string) $component->get('field_heading')->value !== 'Politique de confidentialité') {
        continue;
      }
      $privacy_paragraph = $component;
      break;
    }

    $privacy_paragraph = $privacy_paragraph ?? Paragraph::create([
      'type' => 'text_block',
      'status' => TRUE,
    ]);
    $privacy_paragraph->set('field_heading', 'Politique de confidentialité');
    $privacy_paragraph->set('field_text', [
      'value' => $privacy_body,
      'format' => 'basic_html',
    ]);
    $privacy_paragraph->save();

    $privacy_page->set('field_home_components', [
      [
        'target_id' => $privacy_paragraph->id(),
        'target_revision_id' => $privacy_paragraph->getRevisionId(),
      ],
    ]);
  }

  if ($privacy_page->hasField('path')) {
    $privacy_page->set('path', [
      'alias' => '/politique-confidentialite',
      'pathauto' => 0,
    ]);
  }
  $privacy_page->setPublished();
  $privacy_page->save();

  $ids = \Drupal::entityQuery('menu_link_content')
    ->accessCheck(FALSE)
    ->condition('menu_name', 'footer')
    ->condition('title', ['Politique de confidentialité', 'Confidentialité'], 'IN')
    ->execute();

  $updated = 0;
  $created = 0;

  if ($ids) {
    /** @var \Drupal\menu_link_content\Entity\MenuLinkContent[] $links */
    $links = $link_storage->loadMultiple($ids);
    $kept = FALSE;

    foreach ($links as $link) {
      if (!$kept) {
        $link->set('title', 'Politique de confidentialité');
        $link->set('link', ['uri' => 'internal:/politique-confidentialite']);
        $link->set('enabled', TRUE);
        $link->set('weight', 1);
        $link->save();
        $updated++;
        $kept = TRUE;
        continue;
      }

      $link->delete();
      $updated++;
    }
  }
  else {
    MenuLinkContent::create([
      'title' => 'Politique de confidentialité',
      'menu_name' => 'footer',
      'link' => ['uri' => 'internal:/politique-confidentialite'],
      'expanded' => FALSE,
      'enabled' => TRUE,
      'weight' => 1,
    ])->save();
    $created++;
  }

  return sprintf('Privacy policy page ensured, %d footer links created, %d footer links updated.', $created, $updated);
}

/**
 * Re-runs privacy policy synchronization on existing environments.
 */
function emerging_digital_content_post_update_privacy_policy_and_contact_consent_rerun(array &$sandbox): string {
  return emerging_digital_content_post_update_privacy_policy_and_contact_consent($sandbox);
}

/**
 * Backfills missing contact CTA links on strategic pages for installed sites.
 */
function emerging_digital_content_post_update_backfill_strategic_cta_contact_links(array &$sandbox): string {
  unset($sandbox);

  $node_storage = \Drupal::entityTypeManager()->getStorage('node');
  $page_ids = \Drupal::entityQuery('node')
    ->accessCheck(FALSE)
    ->condition('type', 'page')
    ->condition('title', ['Services', 'IA & Drupal', 'Cas clients'], 'IN')
    ->execute();

  if (!$page_ids) {
    return 'No strategic pages found for CTA backfill.';
  }

  /** @var \Drupal\node\NodeInterface[] $pages */
  $pages = $node_storage->loadMultiple($page_ids);
  $updated = 0;

  foreach ($pages as $page) {
    if (!$page->hasField('field_home_components')) {
      continue;
    }

    foreach ($page->get('field_home_components')->referencedEntities() as $component) {
      if ($component->bundle() !== 'cta' || !$component->hasField('field_link')) {
        continue;
      }

      $existing_link = $component->get('field_link')->first();
      if ($existing_link && !empty($existing_link->getValue()['uri'])) {
        continue;
      }

      $component->set('field_link', [
        'uri' => 'internal:/contact',
        'title' => 'Prendre contact',
      ]);
      $component->save();
      $updated++;
    }
  }

  return sprintf('%d CTA paragraph(s) updated with contact links.', $updated);
}

/**
 * Applies issue #81 editorial texts on installed sites (module enabled).
 */
function emerging_digital_content_post_update_issue_81_editorial_repositioning_live(array &$sandbox): string {
  unset($sandbox);

  $entity_repository = \Drupal::service('entity.repository');
  $updated = 0;

  $field_updates = [
    '3b376be4-852e-4fa2-85ba-a64ff0fefa9d' => [
      'field_heading' => [['value' => 'Drupal pour projets structurés, accessibles et évolutifs']],
      'field_text' => [[
        'value' => 'Nous accompagnons PME, ASBL et organisations publiques dans des projets Drupal robustes, avec une architecture éditoriale claire, des exigences d’accessibilité web et une IA utile au quotidien.',
        'format' => 'basic_html',
      ],
      ],
      'field_secondary_link' => [['uri' => 'internal:/services', 'title' => 'Explorer les services Drupal']],
    ],
    'ad8e2138-9355-4951-90dc-354e07847eca' => [
      'field_heading' => [['value' => 'Positionnement éditorial clair pour environnements exigeants']],
      'field_text' => [[
        'value' => 'Notre approche Drupal s’adresse aux contextes structurés et institutionnels : gouvernance de contenu, parcours d’édition fiables, accessibilité web et intégration d’outils IA pour la rédaction assistée, le SEO éditorial et l’ouverture vers la traduction automatique.',
        'format' => 'basic_html',
      ],
      ],
    ],
    '50c9c0ef-85f8-49a3-819c-4aa63ddd1737' => [
      'field_heading' => [['value' => 'Services Drupal pour projets structurés et institutionnels']],
      'field_text' => [[
        'value' => 'Nous accompagnons PME, ASBL et organisations publiques dans la création, la modernisation et l’évolution de sites Drupal robustes, accessibles et éditorialement maîtrisés.',
        'format' => 'basic_html',
      ],
      ],
    ],
    '2f4f723a-90d5-4a95-84dc-333363d51655' => [
      'field_heading' => [['value' => 'Pourquoi Drupal pour des projets exigeants']],
      'field_text' => [[
        'value' => 'Drupal est une plateforme robuste et flexible, particulièrement adaptée aux projets institutionnels et aux écosystèmes de contenus complexes, avec des besoins d’accessibilité et de gouvernance.',
        'format' => 'basic_html',
      ],
      ],
    ],
    'bfa1a9d3-2715-4a04-8758-d14ece990e6e' => [
      'field_heading' => [['value' => 'IA utile dans Drupal pour les équipes éditoriales']],
      'field_text' => [[
        'value' => 'Des fonctionnalités IA concrètes pour améliorer la qualité des contenus, la cohérence éditoriale et la productivité, dans un cadre maîtrisé.',
        'format' => 'basic_html',
      ],
      ],
    ],
    'b878853f-1368-47be-bee9-8cdc1dba826a' => [
      'field_heading' => [['value' => 'Cas clients Drupal sur des contextes structurés']],
    ],
    '88bc3809-2c51-4ec1-93a8-2eed9ce6930c' => [
      'field_text' => [[
        'value' => 'Parlons de votre contexte Drupal et de vos objectifs d’accessibilité. Vous pouvez aussi consulter nos <a href="/services">services Drupal</a> et notre approche <a href="/ia-drupal">IA &amp; Drupal</a>.',
        'format' => 'basic_html',
      ],
      ],
    ],
    'f4581518-8acc-4632-8b25-884776b3aeb4' => [
      'field_text' => [[
        'value' => 'Découvrez comment relier vos objectifs éditoriaux à des usages IA concrets. Voir aussi nos <a href="/services">services Drupal</a> et des <a href="/cas-clients">cas clients</a>.',
        'format' => 'basic_html',
      ],
      ],
    ],
    'd36b8734-7d9e-4782-a2e5-efa82d8ecfea' => [
      'field_text' => [[
        'value' => 'Vous avez un projet similaire ? Consultez nos <a href="/services">services</a> ou notre page <a href="/ia-drupal">IA &amp; Drupal</a>, puis discutons-en.',
        'format' => 'basic_html',
      ],
      ],
    ],
  ];

  foreach ($field_updates as $uuid => $fields) {
    $paragraph = $entity_repository->loadEntityByUuid('paragraph', $uuid);
    if (!$paragraph) {
      continue;
    }
    foreach ($fields as $field_name => $value) {
      if ($paragraph->hasField($field_name)) {
        $paragraph->set($field_name, $value);
      }
    }
    $paragraph->save();
    $updated++;
  }

  return sprintf('Issue #81 live editorial update applied on %d paragraph(s).', $updated);
}

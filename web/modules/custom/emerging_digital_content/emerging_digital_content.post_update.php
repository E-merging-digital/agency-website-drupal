<?php

/**
 * @file
 * Post-update hooks for Emerging Digital Content.
 */

declare(strict_types=1);

use Drupal\menu_link_content\Entity\MenuLinkContent;

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

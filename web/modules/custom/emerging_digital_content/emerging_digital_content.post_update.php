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
    ->set('page.front', '/accueil')
    ->save(TRUE);

  \Drupal::state()->set('system.maintenance_mode', 0);

  return 'Default content for emerging_digital_content has been imported or was already present.';
}

/**
 * Creates missing main navigation links for the public website pages.
 */
function emerging_digital_content_post_update_main_navigation_links(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');

  $links = [
    ['title' => 'Accueil', 'uri' => 'internal:/accueil', 'weight' => 0],
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

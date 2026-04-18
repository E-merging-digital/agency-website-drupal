<?php

/**
 * @file
 * Post-update hooks for Emerging Digital Content.
 */

declare(strict_types=1);

use Drupal\emerging_digital_content\MainNavigationManager;

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
  $updated = MainNavigationManager::ensureMainNavigationLinks();

  return sprintf('%d main navigation links were created or updated.', $updated);
}

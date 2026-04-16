<?php

declare(strict_types=1);

/**
 * @file
 * Post-update hooks for Emerging Digital Content.
 */

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

  return 'Default content for emerging_digital_content has been imported or was already present.';
}

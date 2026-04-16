<?php

declare(strict_types=1);

require_once DRUPAL_ROOT . '/modules/custom/emerging_digital_content/emerging_digital_content.install';

_emerging_digital_content_purge_stale_entities();

$importer = \Drupal::service('default_content.importer');
$importer->importContent('emerging_digital_content');

\Drupal::configFactory()->getEditable('system.site')
  ->set('page.front', '/accueil')
  ->save(TRUE);

print "Default content imported and front page set to /accueil.\n";

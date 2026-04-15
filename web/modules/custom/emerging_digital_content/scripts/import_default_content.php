<?php

declare(strict_types=1);

$importer = \Drupal::service('default_content.importer');
$importer->importContent('emerging_digital_content');

\Drupal::configFactory()->getEditable('system.site')
  ->set('page.front', '/accueil')
  ->save(TRUE);

print "Default content imported and front page set to /accueil.\n";

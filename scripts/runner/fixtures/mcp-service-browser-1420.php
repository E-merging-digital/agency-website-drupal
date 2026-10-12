<?php

declare(strict_types=1);

use Drupal\node\NodeInterface;

/**
 * Ephemeral, strictly Agency DDEV MCP browser fixture for #1420.
 *
 * Drush php:script only. Never run against PREPROD or PROD.
 */
if (PHP_SAPI !== 'cli' || getenv('AGENCY_BROWSER_FIXTURE_MODE') !== 'mcp-1420') {
  throw new RuntimeException('MCP fixture execution requires its explicit test-only gate.');
}
$expectedProject = (string) getenv('AGENCY_EXPECTED_DDEV_PROJECT');
$actualProject = (string) getenv('DDEV_PROJECT');
if (!preg_match('/^agency-browser-[0-9]+-[0-9]+$/D', $expectedProject)
  || $actualProject !== $expectedProject) {
  throw new RuntimeException('This fixture requires the exact isolated Agency browser DDEV project.');
}
$options = \Drupal::database()->getConnectionOptions();
if (($options['host'] ?? NULL) !== 'db' || ($options['database'] ?? NULL) !== 'db') {
  throw new RuntimeException('Refusing non-DDEV or unexpected database connection.');
}
$relative = (string) getenv('AGENCY_BROWSER_FIXTURE_JSON');
if (!preg_match('#^\.ddev/\.downloads/mcp-browser-[0-9]+-[0-9]+\.json$#D', $relative)) {
  throw new RuntimeException('Unexpected fixture source path.');
}
$appRoot = dirname(DRUPAL_ROOT);
$directory = realpath($appRoot . '/.ddev/.downloads');
$sourcePath = realpath($appRoot . '/' . $relative);
if ($directory === FALSE || $sourcePath === FALSE
  || !str_starts_with($sourcePath, $directory . '/')
  || !is_file($sourcePath)) {
  throw new RuntimeException('Fixture source is missing or outside its isolated workspace.');
}
$payload = json_decode((string) file_get_contents($sourcePath), TRUE, 512, JSON_THROW_ON_ERROR);
if (!is_array($payload)
  || ($payload['schema_version'] ?? NULL) !== 2
  || ($payload['issue_number'] ?? NULL) !== 1415
  || ($payload['bundle'] ?? NULL) !== 'service'
  || ($payload['published'] ?? NULL) !== TRUE) {
  throw new RuntimeException('Expected the exact bounded approved Service candidate schema.');
}
$definitions = [
  [
    'uuid' => '4081ff3d-bdce-49e1-90e3-17a7281d9880',
    'fr' => $payload['fr'] ?? NULL,
    'en' => $payload['en'] ?? NULL,
    'aliases' => $payload['stored_aliases'] ?? NULL,
  ],
  [
    'uuid' => '4e4b100e-c1a4-4a8c-ae1f-738a4c5fd370',
    'fr' => [
      'title' => 'Service témoin synthétique',
      'short_description' => 'Page témoin de validation isolée.',
      'detailed_description_html' => '<p>Service synthétique sans présentation MCP.</p>',
    ],
    'en' => [
      'title' => 'Synthetic comparison service',
      'short_description' => 'Isolated validation comparator page.',
      'detailed_description_html' => '<p>Synthetic service without MCP presentation.</p>',
    ],
    'aliases' => [
      'fr' => '/service-temoin-mcp-1420',
      'en' => '/mcp-comparator-service-1420',
    ],
  ],
];
$storage = \Drupal::entityTypeManager()->getStorage('node');
$aliases = \Drupal::entityTypeManager()->getStorage('path_alias');
foreach ($definitions as $definition) {
  if ($storage->loadByProperties(['uuid' => $definition['uuid']]) !== []) {
    throw new RuntimeException('A fixture UUID already exists; fresh DEV is required.');
  }
  foreach (['fr', 'en'] as $langcode) {
    $fields = $definition[$langcode];
    $alias = $definition['aliases'][$langcode] ?? NULL;
    if (!is_array($fields) || !is_string($alias)
      || !preg_match('#^/[a-z0-9-]+$#D', $alias)
      || $aliases->loadByProperties(['alias' => $alias]) !== []) {
      throw new RuntimeException('Invalid or occupied fixture alias/translation.');
    }
    foreach (['title', 'short_description', 'detailed_description_html'] as $key) {
      if (!is_string($fields[$key] ?? NULL) || trim($fields[$key]) === '') {
        throw new RuntimeException('Incomplete approved fixture content.');
      }
    }
  }
}
foreach ($definitions as $definition) {
  $fr = $definition['fr'];
  $en = $definition['en'];
  $node = $storage->create([
    'type' => 'service',
    'uuid' => $definition['uuid'],
    'langcode' => 'fr',
    'uid' => 1,
    'status' => 1,
    'title' => $fr['title'],
    'field_short_description' => [[
      'value' => $fr['short_description'],
      'format' => 'basic_html',
    ]],
    'field_detailed_description' => [[
      'value' => $fr['detailed_description_html'],
      'format' => 'basic_html',
    ]],
    'path' => ['alias' => $definition['aliases']['fr'], 'pathauto' => 0],
  ]);
  if (!$node instanceof NodeInterface) {
    throw new RuntimeException('The created fixture must be a Drupal node.');
  }
  $node->addTranslation('en', [
    'title' => $en['title'],
    'status' => 1,
    'field_short_description' => [[
      'value' => $en['short_description'],
      'format' => 'basic_html',
    ]],
    'field_detailed_description' => [[
      'value' => $en['detailed_description_html'],
      'format' => 'basic_html',
    ]],
    'path' => ['alias' => $definition['aliases']['en'], 'pathauto' => 0],
  ]);
  $node->save();
  $storage->resetCache([(int) $node->id()]);
  $reloaded = $storage->load($node->id());
  if (!$reloaded instanceof NodeInterface
    || $reloaded->bundle() !== 'service'
    || $reloaded->uuid() !== $definition['uuid']
    || !$reloaded->hasTranslation('en')) {
    throw new RuntimeException('Fixture persistence or identity validation failed.');
  }
  foreach (['fr', 'en'] as $langcode) {
    $translation = $reloaded->getTranslation($langcode);
    $expected = $definition[$langcode];
    if (!$translation->isPublished()
      || $translation->label() !== $expected['title']
      || $translation->get('field_short_description')->value !== $expected['short_description']
      || $translation->get('field_detailed_description')->value !== $expected['detailed_description_html']
      || $translation->get('field_short_description')->format !== 'basic_html'
      || $translation->get('field_detailed_description')->format !== 'basic_html'
      || $translation->get('path')->alias !== $definition['aliases'][$langcode]) {
      throw new RuntimeException('Saved fixture content, language or path does not match.');
    }
    $view = \Drupal::entityTypeManager()->getViewBuilder('node')->view($translation, 'full');
    if (($view['#view_mode'] ?? NULL) !== 'full') {
      throw new RuntimeException('The Service full view mode is unavailable.');
    }
  }
}
print "MCP_FIXTURE_1420_PASS uuid=4081ff3d-bdce-49e1-90e3-17a7281d9880 translations=fr,en comparator=PASS view=full\n";
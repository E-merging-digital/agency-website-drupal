<?php

/**
 * @file
 * #1417: bounded publication candidate for the 37 existing NL translations.
 *
 * No NL import. Run via Drush as the authorized human PROD operator only.
 * A dry-run never writes. An apply additionally requires separately verified
 * human rendered approval, legal/security gates, and a recoverable DB backup.
 */

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\node\NodeInterface;

function agency1417_release_stop(string $reason): never {
  throw new \RuntimeException('CONTROLLED_STOP=' . $reason);
}

/** Editorial fields only: immutable source-language proof across NL saves. */
function agency1417_release_source(ContentEntityInterface $node, string $lang): string {
  $translation = $node->getTranslation($lang);
  $values = [];
  foreach ($translation->getFieldDefinitions() as $name => $definition) {
    if ($name === 'title' || $name === 'status' || $name === 'body' || str_starts_with($name, 'field_')) {
      $values[$name] = $translation->get($name)->getValue();
    }
  }
  ksort($values);
  return hash('sha256', json_encode(
    $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
  ));
}

$mode = getenv('AGENCY1417_RELEASE_MODE') ?: '';
if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
  agency1417_release_stop('INVALID_MODE');
}
$account = posix_getpwuid(posix_geteuid());
$cwd = realpath(getcwd());
if (gethostname() !== 'emergingdigital' ||
    ($account['name'] ?? '') !== 'deploy' ||
    $cwd === FALSE || !str_starts_with($cwd, '/var/www/agency/releases/')) {
  agency1417_release_stop('WRONG_HOST_USER_OR_RELEASE');
}
if (\Drupal::languageManager()->getLanguage('nl') === NULL ||
    \Drupal::config('language.negotiation')->get('url.source') !== 'path_prefix' ||
    \Drupal::config('language.negotiation')->get('url.prefixes.nl') !== 'nl' ||
    \Drupal::config('language.entity.nl')->get('status') !== TRUE ||
    \Drupal::config('system.site')->get('page.front') !== '/node/5') {
  agency1417_release_stop('NL_CONFIGURATION_OR_FRONT_CHANGED');
}

// PROD entity identities are NOT interchangeable with PREPROD node IDs.
$nodeIds = array_merge(range(1, 36), [42]);
$nodes = \Drupal::entityTypeManager()->getStorage('node');
$menus = \Drupal::entityTypeManager()->getStorage('menu_link_content');
$pathAliases = \Drupal::entityTypeManager()->getStorage('path_alias');
$repo = \Drupal::service('path_alias.repository');
$aliasManager = \Drupal::service('path_alias.manager');
$cleaner = \Drupal::service('pathauto.alias_cleaner');

// Resolve semantic destinations through existing current PROD FR URLs.
// The historical PREPROD mapping is intentionally NOT reused by node ID.
$destinations = [];
foreach ([
  'services' => '/services',
  'ia' => '/ia-drupal',
  'cases' => '/cas-clients',
  'contact' => '/contact',
  'team' => '/equipe',
] as $key => $frAlias) {
  $system = $aliasManager->getPathByAlias($frAlias, 'fr');
  if (!preg_match('~^/node/([1-9][0-9]*)$~D', $system, $match)) {
    agency1417_release_stop('FR_DESTINATION_NOT_A_NODE_' . $key);
  }
  $nid = (int) $match[1];
  if (!in_array($nid, $nodeIds, TRUE)) {
    agency1417_release_stop('DESTINATION_NOT_IN_37_' . $key);
  }
  $destinations[$key] = $nid;
}
if ($destinations['team'] !== 23) {
  agency1417_release_stop('TEAM_IDENTITY_DRIFT');
}

$fixedAliases = [
  $destinations['services'] => '/diensten',
  $destinations['ia'] => '/ai-drupal',
  $destinations['cases'] => '/klantcases',
  $destinations['contact'] => '/contact',
  6 => '/wettelijke-vermeldingen',
  7 => '/cookiebeleid',
  8 => '/privacyverklaring',
  23 => '/team',
];
if (count($fixedAliases) !== 8) {
  agency1417_release_stop('SEMANTIC_DESTINATION_COLLISION');
}
$expectedLegal = [
  6 => ['Mentions légales', 'Wettelijke vermeldingen'],
  7 => ['Politique de cookies', 'Cookiebeleid'],
  8 => ['Politique de confidentialité', 'Privacyverklaring'],
];

// ID => original menu, FR title, FR URI, NL title, NL URI.
$menuPlan = [
  1 => ['main', 'Accueil', 'internal:/', 'Startpagina', 'internal:/'],
  2 => ['main', 'Services', 'internal:/services', 'Diensten', 'entity:node/' . $destinations['services']],
  3 => ['main', 'IA & Drupal', 'internal:/ia-drupal', 'AI & Drupal', 'entity:node/' . $destinations['ia']],
  4 => ['main', 'Cas clients', 'internal:/cas-clients', 'Klantcases', 'entity:node/' . $destinations['cases']],
  5 => ['main', 'Contact', 'internal:/contact', 'Contact', 'entity:node/' . $destinations['contact']],
  9 => ['main', 'Équipe', 'internal:/fr/equipe', 'Team', 'entity:node/23'],
  10 => ['main', 'Blog', 'internal:/blog', 'Blog', 'internal:/blog'],
  6 => ['footer', 'Mentions légales', 'entity:node/6', 'Wettelijke vermeldingen', 'entity:node/6'],
  7 => ['footer', 'Politique de cookies', 'entity:node/7', 'Cookiebeleid', 'entity:node/7'],
  8 => ['footer', 'Politique de confidentialité', 'entity:node/8', 'Privacyverklaring', 'entity:node/8'],
];

$nodePlan = [];
$missingAliases = [];
$uniques = [];
foreach ($nodeIds as $nid) {
  $node = $nodes->load($nid);
  if (!$node instanceof NodeInterface ||
      !in_array($node->bundle(), ['page', 'article', 'service', 'case_client', 'ai_feature'], TRUE) ||
      !$node->hasTranslation('fr') || !$node->hasTranslation('en') ||
      !$node->hasTranslation('nl') ||
      $node->getTranslation('nl')->isPublished()) {
    agency1417_release_stop('COHORT_OR_NL_STATUS_' . $nid);
  }
  if (isset($expectedLegal[$nid]) &&
      ($node->getTranslation('fr')->label() !== $expectedLegal[$nid][0] ||
       $node->getTranslation('nl')->label() !== $expectedLegal[$nid][1])) {
    agency1417_release_stop('LEGAL_IDENTITY_DRIFT_' . $nid);
  }
  $target = '/node/' . $nid;
  $alias = NULL;
  if ($nid !== 5) {
    $existing = $repo->lookupBySystemPath($target, 'nl');
    $old = ($existing['langcode'] ?? '') === 'nl' ? ($existing['alias'] ?? NULL) : NULL;
    $prefix = match ($node->bundle()) {
      'article' => '/blog/',
      'service' => '/diensten/',
      'case_client' => '/klantcases/',
      'ai_feature' => '/ai/',
      default => '/',
    };
    $slug = trim($cleaner->cleanString($node->getTranslation('nl')->label()), '/');
    if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug)) {
      agency1417_release_stop('INVALID_SLUG_' . $nid);
    }
    $candidate = $fixedAliases[$nid] ?? $prefix . $slug;
    if ($old !== NULL && ($old !== $candidate ||
        preg_match('~^/(?:fr|en|node/|admin/)~', $old))) {
      agency1417_release_stop('EXISTING_NL_ALIAS_DRIFT_' . $nid);
    }
    $alias = $old ?? $candidate;
    if (isset($uniques[$alias])) {
      agency1417_release_stop('ALIAS_DUPLICATE_' . $nid);
    }
    $uniques[$alias] = $nid;
    $conflict = $repo->lookupByAlias($alias, 'nl');
    if (is_array($conflict) && ($conflict['langcode'] ?? '') === 'nl' &&
        ($conflict['path'] ?? '') !== $target) {
      agency1417_release_stop('ALIAS_COLLISION_' . $nid);
    }
    if ($old === NULL) {
      $missingAliases[$nid] = $alias;
    }
  }
  $nodePlan[$nid] = [
    'node' => $node,
    'uuid' => $node->uuid(),
    'revision' => (int) $node->getRevisionId(),
    'fr' => agency1417_release_source($node, 'fr'),
    'en' => agency1417_release_source($node, 'en'),
    'nl' => agency1417_release_source($node, 'nl'),
    'alias' => $alias,
  ];
}

$menuEntities = [];
foreach ($menuPlan as $id => [$menu, $frTitle, $frUri, $nlTitle, $nlUri]) {
  $link = $menus->load($id);
  if (!$link instanceof ContentEntityInterface ||
      $link->get('menu_name')->value !== $menu ||
      $link->getTranslation('fr')->label() !== $frTitle ||
      $link->getTranslation('fr')->get('link')->uri !== $frUri ||
      $link->get('link')->getFieldDefinition()->isTranslatable() ||
      !$link->get('title')->getFieldDefinition()->isTranslatable() ||
      $link->hasTranslation('nl')) {
    agency1417_release_stop('MENU_SOURCE_OR_TRANSLATABILITY_' . $id);
  }
  $menuEntities[$id] = $link;
}

// The proof binds all current node revisions, NL content, aliases and menu
// identities to one candidate. Do not include private textual content in output.
$proofData = ['nodes' => [], 'menus' => [], 'main' => getenv('AGENCY1417_EXPECTED_MAIN') ?: ''];
foreach ($nodePlan as $id => $plan) {
  $proofData['nodes'][$id] = [
    $plan['uuid'], $plan['revision'], $plan['fr'],
    $plan['en'], $plan['nl'], $plan['alias'],
  ];
}
foreach ($menuEntities as $id => $link) {
  $proofData['menus'][$id] = [
    $link->uuid(), $link->getRevisionId(), $menuPlan[$id],
  ];
}
$proof = hash('sha256', json_encode(
  $proofData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
));
if ($mode === 'dry-run') {
  printf(
    "NL_RELEASE_DRY_RUN=PASS NODES=37 MENU_LINKS=10 ALIASES_TO_CREATE=%d WRITE=NONE CANDIDATE_SHA256=%s\n",
    count($missingAliases), $proof,
  );
  return;
}

// External authorization MUST be verified through the existing owner+GitHub
// release policy before this path is used. Flags are not approvals by themselves.
if (getenv('AGENCY1417_OWNER_RENDER_APPROVED') !== 'YES' ||
    getenv('AGENCY1417_PUBLICATION_HOLDS_CLEARED') !== 'YES' ||
    getenv('AGENCY1417_PROD_BACKUP_VERIFIED') !== 'YES' ||
    getenv('AGENCY1417_APPROVED_CANDIDATE_SHA256') !== $proof) {
  agency1417_release_stop('SEPARATE_EXACT_PUBLICATION_AUTHORITY_REQUIRED');
}
$transaction = \Drupal::database()->startTransaction();
try {
  foreach ($nodePlan as $id => $plan) {
    $node = $plan['node'];
    $node->getTranslation('nl')->setPublished();
    $node->setNewRevision(TRUE);
    $node->save();
    $nodes->resetCache([$id]);
    $updated = $nodes->load($id);
    if (!$updated->getTranslation('nl')->isPublished() ||
        agency1417_release_source($updated, 'fr') !== $plan['fr'] ||
        agency1417_release_source($updated, 'en') !== $plan['en']) {
      agency1417_release_stop('NODE_POST_SAVE_DRIFT_' . $id);
    }
  }
  foreach ($missingAliases as $id => $alias) {
    $path = '/node/' . $id;
    $current = $repo->lookupBySystemPath($path, 'nl');
    if (($current['langcode'] ?? '') === 'nl') {
      if (($current['alias'] ?? NULL) !== $alias) {
        agency1417_release_stop('ALIAS_CHANGED_DURING_APPLY_' . $id);
      }
      continue;
    }
    $pathAliases->create([
      'path' => $path, 'alias' => $alias, 'langcode' => 'nl',
    ])->save();
  }
  foreach ($menuPlan as $id => $entry) {
    $link = $menuEntities[$id];
    // Core's link URI is shared across languages. An entity URI keeps the
    // exact same FR node target while resolving each language's own alias.
    $enTitle = $link->hasTranslation('en')
      ? $link->getTranslation('en')->label() : NULL;
    if ($entry[2] !== $entry[4]) {
      $link->set('link', ['uri' => $entry[4]]);
    }
    $link->addTranslation('nl', ['title' => $entry[3]]);
    $link->save();
    if ($link->getTranslation('fr')->label() !== $entry[1] ||
        $link->getTranslation('fr')->get('link')->uri !== $entry[4] ||
        $link->getTranslation('nl')->get('link')->uri !== $entry[4] ||
        ($enTitle !== NULL &&
          $link->getTranslation('en')->label() !== $enTitle)) {
      agency1417_release_stop('MENU_SOURCE_LANGUAGE_DRIFT_' . $id);
    }
  }
}
catch (\Throwable $error) {
  $transaction->rollBack();
  throw $error;
}
echo 'NL_RELEASE_APPLY=PASS NODES=37 MENU_LINKS=10 PUBLICATION=NL_ONLY' . PHP_EOL;

<?php

/**
 * @file
 * #1417: strictly additive NL draft candidate for identical Drupal nodes.
 *
 * The owner operates this on PROD only after the applicable GitHub approvals.
 * Dry-run never writes. Apply never publishes, aliases or changes FR/EN.
 * The unchanged private manifest is the only content source.
 */

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Fails closed without exposing private field values.
 */
function agency1417_draft_stop(string $reason): never {
  throw new RuntimeException('CONTROLLED_STOP=' . $reason);
}

/**
 * Normalizes FR/EN identically to the verified private source preflight.
 */
function agency1417_draft_fields(ContentEntityInterface $entity, string $langcode, bool $source, array $map, int $depth = 0): array {
  if ($depth > 3 || !$entity->hasTranslation($langcode)) {
    agency1417_draft_stop('MISSING_NESTED_TRANSLATION');
  }
  $out = [];
  foreach ($entity->getTranslation($langcode)->getFields() as $name => $field) {
    if (!$field->getFieldDefinition()->isTranslatable() ||
        in_array($name, ['langcode', 'default_langcode', 'status', 'path',
          'changed', 'created', 'uid', 'revision_log', 'revision_timestamp',
          'revision_uid', 'revision_translation_affected',
          'content_translation_source', 'content_translation_outdated',
          'content_translation_uid', 'content_translation_created',
          'parent_id', 'parent_type', 'parent_field_name',
          'behavior_settings'], TRUE)) {
      continue;
    }
    $type = $field->getFieldDefinition()->getType();
    if (in_array($type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      $refs = [];
      if (count($field->referencedEntities()) !== $field->count()) {
        agency1417_draft_stop('UNRESOLVED_EXISTING_REFERENCE');
      }
      foreach ($field->referencedEntities() as $ref) {
        if (!$ref instanceof ContentEntityInterface) {
          agency1417_draft_stop('UNSUPPORTED_EXISTING_REFERENCE');
        }
        $typeId = $ref->getEntityTypeId();
        $identity = $ref->uuid();
        if ($typeId === 'node') {
          $id = (int) $ref->id();
          $identity = $source ? (string) ($map[$id] ?? '') : (string) $id;
          if ($identity === '') {
            agency1417_draft_stop('UNMAPPED_EXISTING_NODE');
          }
        }
        $entry = [
          'type' => $typeId,
          'bundle' => $ref->bundle(),
          'identity' => $identity,
        ];
        if ($typeId === 'paragraph') {
          $entry['fields'] = agency1417_draft_fields(
            $ref, $langcode, $source, $map, $depth + 1,
          );
        }
        $refs[] = $entry;
      }
      $out[$name] = ['reference' => $refs];
    }
    else {
      $out[$name] = ['value' => $field->getValue()];
    }
  }
  ksort($out);
  return $out;
}

/**
 * Compares original private per-field digests, ignoring only known metadata.
 */
function agency1417_draft_fingerprints(array $fields): array {
  $out = [];
  foreach ($fields as $name => $field) {
    if (array_key_exists('value', $field)) {
      $out[$name] = [
        'kind' => 'value',
        'hash' => hash('sha256', json_encode(
          $field['value'], JSON_UNESCAPED_UNICODE |
          JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )),
      ];
    }
    else {
      $refs = [];
      foreach ($field['reference'] as $ref) {
        $refs[] = [
          'type' => $ref['type'],
          'bundle' => $ref['bundle'],
          'identity_hash' => hash('sha256', (string) $ref['identity']),
          'fields' => isset($ref['fields'])
            ? agency1417_draft_fingerprints($ref['fields']) : [],
        ];
      }
      $out[$name] = ['kind' => 'reference', 'references' => $refs];
    }
  }
  ksort($out);
  return $out;
}

/**
 * Validates homepage source/target equivalence apart from named bookkeeping.
 */
function agency1417_draft_homepage_compare(array $source, array $target, array $allowed, string $path = ''): void {
  if (array_keys($source) !== array_keys($target)) {
    agency1417_draft_stop('DRAFT_HOMEPAGE_FIELD_SET_CHANGED');
  }
  foreach ($source as $name => $a) {
    $b = $target[$name];
    $key = $path . $name;
    if (($a['kind'] ?? '') !== ($b['kind'] ?? '')) {
      agency1417_draft_stop('DRAFT_HOMEPAGE_FIELD_KIND_CHANGED');
    }
    if ($a['kind'] === 'value') {
      if ($a['hash'] !== $b['hash'] && !in_array($key, $allowed, TRUE)) {
        agency1417_draft_stop('DRAFT_HOMEPAGE_EDITORIAL_DRIFT');
      }
      continue;
    }
    if (count($a['references']) !== count($b['references'])) {
      agency1417_draft_stop('DRAFT_HOMEPAGE_REFERENCE_COUNT');
    }
    foreach ($a['references'] as $index => $ref) {
      $other = $b['references'][$index];
      if ($ref['type'] !== $other['type'] ||
          $ref['bundle'] !== $other['bundle'] ||
          $ref['identity_hash'] !== $other['identity_hash']) {
        agency1417_draft_stop('DRAFT_HOMEPAGE_REFERENCE_IDENTITY');
      }
      agency1417_draft_homepage_compare(
        $ref['fields'], $other['fields'], $allowed,
        $key . '[' . $index . '].',
      );
    }
  }
}

/**
 * Reuses the already-verified private diagnostic, not a new export.
 */
function agency1417_draft_homepage_proof(array $entry, array $fields, string $lang): void {
  if (($entry['source_nid'] ?? 0) !== 5 ||
      ($entry['target_nid'] ?? 0) !== 5 ||
      !isset($entry['langs'][$lang])) {
    agency1417_draft_stop('DRAFT_HOMEPAGE_DIAGNOSTIC_IDENTITY');
  }
  $allowed = $lang === 'fr'
    ? ['field_home_components[1].content_translation_changed']
    : [
      'field_home_components[0].content_translation_changed',
      'field_home_components[1].content_translation_changed',
    ];
  agency1417_draft_homepage_compare(
    $entry['langs'][$lang], agency1417_draft_fingerprints($fields),
    $allowed,
  );
}

/**
 * Remaps only explicit node links within the approved same-identity cohort.
 */
function agency1417_draft_links(array $items, array $safeIds, array $map): array {
  foreach ($items as &$item) {
    if (!is_array($item) || !isset($item['uri']) ||
        !is_string($item['uri'])) {
      agency1417_draft_stop('DRAFT_LINK_URI_MISSING');
    }
    $uri = $item['uri'];
    if (preg_match('~^(entity:node/|internal:/node/)([1-9][0-9]*)([?#].*)?$~', $uri, $parts)) {
      $target = $map[(int) $parts[2]] ?? NULL;
      if ($target === NULL || !in_array($target, $safeIds, TRUE)) {
        agency1417_draft_stop('DRAFT_LINK_NODE_DEPENDENCY_NOT_READY');
      }
      $item['uri'] = $parts[1] . $target . ($parts[3] ?? '');
    }
    elseif (!preg_match('~^(https?://|mailto:|tel:)~i', $uri) ||
            str_contains(strtolower($uri), 'preprod.emergingdigital.be')) {
      // Aliases, relative URLs and PREPROD links are not identity mappings.
      agency1417_draft_stop('DRAFT_LINK_URI_UNVERIFIED');
    }
  }
  unset($item);
  return $items;
}

/**
 * Maps NL fields to existing entities; prepares owned Paragraph translations.
 */
function agency1417_draft_values(ContentEntityInterface $entity, array $fields, array $safeIds, array $map, array &$paragraphs, int $depth = 0): array {
  $newNlParagraph = $entity->getEntityTypeId() === 'paragraph' &&
    $entity->isNew() && $entity->hasTranslation('nl');
  if ($depth > 3 || (!$entity->hasTranslation('fr') && !$newNlParagraph)) {
    agency1417_draft_stop('DRAFT_REFERENCE_DEPTH_OR_LANGUAGE');
  }
  $values = [];
  $allowed = [
    'string', 'string_long', 'text', 'text_long', 'text_with_summary',
    'boolean', 'integer', 'decimal', 'float', 'list_string', 'list_integer',
    'link', 'email', 'telephone', 'datetime', 'metatag',
  ];
  foreach ($fields as $name => $field) {
    if ($name === 'content_translation_changed') {
      // Drupal maintains this translation timestamp on save; never import it.
      continue;
    }
    if ($name === 'metatag' && $entity->hasField($name) &&
        $entity->getFieldDefinition($name)->getType() === 'metatag_computed') {
      // Metatag 2.x generates this field; it is not editable SEO content.
      // Keep stored type 'metatag' and FR/EN fingerprints unchanged.
      continue;
    }
    if (!$entity->hasField($name) ||
        !$entity->getFieldDefinition($name)->isTranslatable()) {
      agency1417_draft_stop('DRAFT_FIELD_DEFINITION_MISMATCH');
    }
    $type = $entity->getFieldDefinition($name)->getType();
    if (array_key_exists('value', $field)) {
      if (!is_array($field['value']) ||
          ($field['value'] !== [] && !in_array($type, $allowed, TRUE))) {
        // Raw file IDs are never copied between independent databases.
        agency1417_draft_stop('DRAFT_UNSUPPORTED_VALUE_TYPE_' . $name);
      }
      if ($type === 'link') {
        $values[$name] = agency1417_draft_links(
          $field['value'], $safeIds, $map,
        );
      }
      else {
        $values[$name] = $field['value'];
      }
      continue;
    }
    if (!isset($field['reference']) || !is_array($field['reference'])) {
      agency1417_draft_stop('DRAFT_INVALID_REFERENCE_' . $name);
    }
    $values[$name] = [];
    if ($field['reference'] === []) {
      continue;
    }
    if (!in_array($type, ['entity_reference', 'entity_reference_revisions'], TRUE)) {
      agency1417_draft_stop('DRAFT_UNSUPPORTED_REFERENCE_' . $name);
    }

    // Never pair Paragraphs by position alone: require the same UUID.
    $existing = [];
    if (!$entity->isNew()) {
      foreach ($entity->getTranslation('fr')->get($name)->referencedEntities() as $ref) {
        if ($ref instanceof ContentEntityInterface) {
          $existing[$ref->uuid()] = $ref;
        }
      }
    }
    foreach ($field['reference'] as $ref) {
      if ($type === 'entity_reference_revisions') {
        if (($ref['type'] ?? '') !== 'paragraph' ||
            !isset($ref['fields']) || !is_array($ref['fields'])) {
          agency1417_draft_stop('DRAFT_UNSUPPORTED_REFERENCE_' . $name);
        }
        $uuid = $ref['identity'];
        $paragraph = $existing[$uuid] ?? NULL;
        if (!$paragraph instanceof ContentEntityInterface) {
          // NID 2 has NL Paragraph references without existing FR UUIDs.
          // Create NL-exclusive entities; never pair by array position.
          if ($entity->getEntityTypeId() !== 'node' ||
              (int) $entity->id() !== 2 ||
              $name !== 'field_home_components' ||
              isset($paragraphs[$uuid]) ||
              count(\Drupal::entityTypeManager()->getStorage('paragraph')
                ->loadByProperties(['uuid' => $uuid])) !== 0) {
            agency1417_draft_stop('DRAFT_PARAGRAPH_IDENTITY_OR_TRANSLATION_UUID');
          }
          $paragraph = \Drupal::entityTypeManager()->getStorage('paragraph')
            ->create(['type' => $ref['bundle'], 'langcode' => 'nl']);
          if (!$paragraph instanceof ContentEntityInterface ||
              !$paragraph->isNew() ||
              $paragraph->bundle() !== $ref['bundle'] ||
              !$paragraph->hasTranslation('nl')) {
            agency1417_draft_stop('DRAFT_NEW_PARAGRAPH_INVALID');
          }
          $nested = agency1417_draft_values(
            $paragraph, $ref['fields'], $safeIds, $map, $paragraphs,
            $depth + 1,
          );
          foreach ($nested as $fieldName => $items) {
            $paragraph->set($fieldName, $items);
          }
          $paragraphs[$uuid] = ['entity' => $paragraph, 'new' => TRUE];
          // Entity Reference Revisions persists the owned Paragraph on
          // node save. No entity/database writes occur during dry-run.
          $values[$name][] = ['entity' => $paragraph];
          continue;
        }
        if ($paragraph->bundle() !== $ref['bundle']) {
          agency1417_draft_stop('DRAFT_PARAGRAPH_IDENTITY_OR_TRANSLATION_BUNDLE');
        }
        if ($paragraph->hasTranslation('nl')) {
          agency1417_draft_stop('DRAFT_PARAGRAPH_IDENTITY_OR_TRANSLATION_NL_EXISTS');
        }
        if (isset($paragraphs[$uuid])) {
          agency1417_draft_stop('DRAFT_PARAGRAPH_IDENTITY_OR_TRANSLATION_DUPLICATE');
        }
        $nested = agency1417_draft_values(
          $paragraph, $ref['fields'], $safeIds, $map, $paragraphs, $depth + 1,
        );
        $paragraphs[$uuid] = [
          'entity' => $paragraph,
          'values' => $nested,
        ];
        $values[$name][] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
        continue;
      }
      if (($ref['type'] ?? '') === 'media') {
        // Equal media UUID does not prove identical underlying file bytes.
        agency1417_draft_stop('DRAFT_MEDIA_ASSET_PARITY_UNPROVEN');
      }
      if (!in_array($ref['type'], ['node', 'taxonomy_term'], TRUE)) {
        agency1417_draft_stop('DRAFT_UNSUPPORTED_REFERENCE_TYPE');
      }
      $storage = \Drupal::entityTypeManager()->getStorage($ref['type']);
      if ($ref['type'] === 'node') {
        $targetId = (int) $ref['identity'];
        if (!in_array($targetId, $safeIds, TRUE)) {
          agency1417_draft_stop('DRAFT_NODE_DEPENDENCY_NOT_READY');
        }
        $target = $storage->load($targetId);
      }
      else {
        $matches = $storage->loadByProperties(['uuid' => $ref['identity']]);
        if (count($matches) !== 1) {
          agency1417_draft_stop('DRAFT_REFERENCE_IDENTITY_UNPROVEN');
        }
        $target = reset($matches);
      }
      if (!$target instanceof ContentEntityInterface ||
          $target->bundle() !== $ref['bundle']) {
        agency1417_draft_stop('DRAFT_REFERENCE_BUNDLE_MISMATCH');
      }
      $values[$name][] = ['target_id' => $target->id()];
    }
  }
  return $values;
}

/**
 * Substitutes only revisions saved during this exact NL staging transaction.
 */
function agency1417_draft_revisions(array $values, array $revisions): array {
  foreach ($values as &$items) {
    foreach ($items as &$item) {
      if (isset($item['target_revision_id'])) {
        $id = (int) $item['target_id'];
        if (!isset($revisions[$id])) {
          agency1417_draft_stop('DRAFT_PARAGRAPH_REVISION_NOT_SAVED');
        }
        $item['target_revision_id'] = $revisions[$id];
      }
    }
    unset($item);
  }
  unset($items);
  return $values;
}

$mode = getenv('AGENCY1417_DRAFT_MODE') ?: '';
if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
  agency1417_draft_stop('INVALID_DRAFT_MODE');
}
$account = posix_getpwuid(posix_geteuid());
$cwd = realpath(getcwd());
if (gethostname() !== 'emergingdigital' ||
    ($account['name'] ?? '') !== 'deploy' ||
    $cwd === FALSE || !str_starts_with($cwd, '/var/www/agency/releases/')) {
  agency1417_draft_stop('WRONG_PROD_HOST_USER_OR_RELEASE');
}
$manifest = getenv('AGENCY1417_PRIVATE_FILE') ?: '';
$sha = getenv('AGENCY1417_EXPECTED_SHA256') ?: '';
if ($manifest !== '/home/deploy/agency-1417-nl-private-export.json' ||
    !preg_match('/^[a-f0-9]{64}$/', $sha) ||
    !is_file($manifest) || is_link($manifest) ||
    hash_file('sha256', $manifest) !== $sha) {
  agency1417_draft_stop('PRIVATE_MANIFEST_INTEGRITY');
}
$data = json_decode(file_get_contents($manifest), TRUE, 512, JSON_THROW_ON_ERROR);
if (($data['contract'] ?? '') !== 'agency-1417-private-nl-preflight-v1' ||
    ($data['source_count'] ?? NULL) !== 47 ||
    ($data['exceptions'] ?? NULL) !== [43, 46] ||
    count($data['items'] ?? []) !== 47) {
  agency1417_draft_stop('PRIVATE_MANIFEST_CONTRACT');
}

// Same-UUID cohort, plus homepage NID 5 with exact metadata-only proof.
$safeIds = array_values(array_diff(range(1, 38), [37, 38]));
$map = array_combine(range(1, 38), range(1, 38));
$map += [39 => 39, 40 => 41, 41 => 42, 42 => 45, 44 => 43,
  45 => 44, 47 => 46, 48 => 47, 49 => 48];
$byId = [];
foreach ($data['items'] as $item) {
  $id = $item['source_nid'] ?? 0;
  if (!isset($map[$id]) ||
      ($item['target_nid'] ?? NULL) !== $map[$id] ||
      isset($byId[$id])) {
    agency1417_draft_stop('SOURCE_TARGET_MAPPING_CHANGED');
  }
  $byId[$id] = $item;
}
if (count($byId) !== 47) {
  agency1417_draft_stop('INCOMPLETE_MANIFEST_MAPPING');
}
// The owner already verified this separate immutable hash in R135.
$diagPath = '/home/deploy/agency-1417-nl-field-diagnostics.json';
$diagSha = 'a3807b748db92e496fb28c2fe0dd506710c9276fc2b6b8b879b28f5434c72f50';
if (!is_file($diagPath) || is_link($diagPath) ||
    hash_file('sha256', $diagPath) !== $diagSha) {
  agency1417_draft_stop('DRAFT_HOMEPAGE_DIAGNOSTIC_INTEGRITY');
}
$diagnostic = json_decode(file_get_contents($diagPath), TRUE, 512, JSON_THROW_ON_ERROR);
if (($diagnostic['contract'] ?? '') !== 'agency-1417-private-nl-diagnostic-v1' ||
    ($diagnostic['source_export_sha256'] ?? '') !== $sha) {
  agency1417_draft_stop('DRAFT_HOMEPAGE_DIAGNOSTIC_CONTRACT');
}
$homeEntries = array_values(array_filter(
  $diagnostic['entries'] ?? [],
  static fn (array $entry): bool => ($entry['source_nid'] ?? NULL) === 5,
));
if (count($homeEntries) !== 1 ||
    $homeEntries[0]['uuid'] !== $byId[5]['uuid']) {
  agency1417_draft_stop('DRAFT_HOMEPAGE_DIAGNOSTIC_IDENTITY');
}
$homeProof = $homeEntries[0];
$storage = \Drupal::entityTypeManager()->getStorage('node');
$prepared = [];
$paragraphs = [];
$fieldBlockers = [];
foreach ($safeIds as $id) {
  $item = $byId[$id];
  $node = $storage->load($id);
  if (!$node instanceof ContentEntityInterface ||
      $node->uuid() !== $item['uuid'] ||
      $node->bundle() !== $item['bundle'] ||
      !$node->hasTranslation('fr') || !$node->hasTranslation('en') ||
      $node->hasTranslation('nl') ||
      $node->getTranslation('fr')->label() !== $item['title_fr']) {
    agency1417_draft_stop('DRAFT_TARGET_IDENTITY_CHANGED_' . $id);
  }
  $alias = \Drupal::service('path_alias.repository')
    ->lookupBySystemPath('/node/' . $id, 'fr');
  if (($alias['alias'] ?? '') !== $item['alias_fr']) {
    agency1417_draft_stop('DRAFT_TARGET_ALIAS_CHANGED_' . $id);
  }
  foreach (['fr', 'en'] as $lang) {
    $fields = agency1417_draft_fields($node, $lang, FALSE, $map);
    $digest = hash('sha256', json_encode(
      $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
      JSON_THROW_ON_ERROR,
    ));
    if ($id === 5) {
      agency1417_draft_homepage_proof($homeProof, $fields, $lang);
    }
    elseif ($digest !== $item['fingerprint_' . $lang]) {
      agency1417_draft_stop('DRAFT_FR_EN_CHANGED_' . $id . '_' . $lang);
    }
  }
  $nodeParagraphs = $paragraphs;
  try {
    $values = agency1417_draft_values(
      $node, $item['nl_fields'], $safeIds, $map, $nodeParagraphs,
    );
    $prepared[$id] = $values;
    $paragraphs = $nodeParagraphs;
  }
  catch (RuntimeException $error) {
    if ($mode !== 'dry-run' ||
        !str_starts_with($error->getMessage(), 'CONTROLLED_STOP=DRAFT_')) {
      throw $error;
    }
    // Collect one safe failure code per node in a single read-only pass.
    $fieldBlockers[$id] = substr($error->getMessage(), strlen('CONTROLLED_STOP='));
  }
}
if ($fieldBlockers) {
  foreach ($fieldBlockers as $id => $reason) {
    echo 'DRAFT_FIELD_BLOCKER NID=' . $id . ' CODE=' . $reason . PHP_EOL;
  }
  agency1417_draft_stop('DRAFT_FIELD_PREFLIGHT_BLOCKERS_' . count($fieldBlockers));
}
if (count($prepared) !== 36) {
  agency1417_draft_stop('INCOMPLETE_DRAFT_COHORT');
}
$lang = \Drupal::languageManager()->getLanguage('nl');
if ($mode === 'dry-run') {
  echo 'DRAFT_FIELD_PREFLIGHT=PASS NODES=36 NL_LANGUAGE=' .
    ($lang ? 'ACTIVE' : 'MISSING') .
    ' TARGET_WRITE=NONE PUBLIC_ALIASES=NONE' . PHP_EOL;
  return;
}
if (!$lang) {
  agency1417_draft_stop('NL_LANGUAGE_NOT_ACTIVE');
}

// A code flag is not GitHub authorization: the exact owner-go and current
// production dry-run must be verified externally before this branch is used.
if (getenv('AGENCY1417_APPROVED_MANIFEST_SHA256') !== $sha ||
    getenv('AGENCY1417_APPLY_DRAFT_GO') !== 'YES') {
  agency1417_draft_stop('EXACT_OWNER_DRAFT_GO_REQUIRED');
}
$transaction = \Drupal::database()->startTransaction();
try {
  $revisions = [];
  // Create NL Paragraph revisions first; FR/EN references keep old revisions.
  foreach ($paragraphs as $definition) {
    if (!empty($definition['new'])) {
      // Unsaved NL-only Paragraphs are persisted by their node reference.
      continue;
    }
    $paragraph = $definition['entity'];
    $values = agency1417_draft_revisions(
      $definition['values'], $revisions,
    );
    $paragraph->addTranslation('nl', $values);
    $paragraph->setNewRevision(TRUE);
    $paragraph->save();
    $revisions[(int) $paragraph->id()] = $paragraph->getRevisionId();
  }
  foreach ($prepared as $id => $values) {
    $node = $storage->load($id);
    $values = agency1417_draft_revisions($values, $revisions);
    $translation = $node->addTranslation('nl', $values);
    $translation->setUnpublished();
    $node->setNewRevision(TRUE);
    $node->save();
    $storage->resetCache([$id]);
    $reloaded = $storage->load($id);
    if (!$reloaded->hasTranslation('nl') ||
        $reloaded->getTranslation('nl')->isPublished()) {
      agency1417_draft_stop('DRAFT_POST_SAVE_STATUS');
    }
    foreach (['fr', 'en'] as $langcode) {
      $digest = hash('sha256', json_encode(
        agency1417_draft_fields($reloaded, $langcode, FALSE, $map),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR,
      ));
      if ($id === 5) {
        agency1417_draft_homepage_proof(
          $homeProof, agency1417_draft_fields(
            $reloaded, $langcode, FALSE, $map,
          ), $langcode,
        );
      }
      elseif ($digest !== $byId[$id]['fingerprint_' . $langcode]) {
        agency1417_draft_stop('DRAFT_POST_SAVE_FR_EN_DRIFT');
      }
    }
  }
}
catch (Throwable $error) {
  $transaction->rollBack();
  throw $error;
}
echo 'DRAFT_APPLY=PASS NODES=36 NL_STATUS=UNPUBLISHED' .
  ' PUBLIC_ALIASES=NONE' . PHP_EOL;

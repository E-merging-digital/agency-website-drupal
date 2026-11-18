<?php

/**
 * @file
 * #1417 R177: additive NL drafts for ten PROD-identified editorial nodes.
 *
 * SOURCE ONLY. This script is not an authorization to run on any environment.
 * The two private files must be supplied by a separately authorized operator;
 * their contents and raw fields must never be committed, logged or exported.
 */

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityInterface;

function agency1417_ten_stop(string $reason): never {
  throw new RuntimeException('CONTROLLED_STOP=' . $reason);
}

/** Deterministic canonical JSON for the previously approved FR item hashes. */
function agency1417_ten_sort(mixed $value): mixed {
  if (!is_array($value)) {
    return $value;
  }
  if (!array_is_list($value)) {
    ksort($value, SORT_STRING);
  }
  foreach ($value as &$part) {
    $part = agency1417_ten_sort($part);
  }
  unset($part);
  return $value;
}

function agency1417_ten_digest(mixed $value): string {
  return hash('sha256', json_encode(agency1417_ten_sort($value),
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/** Read private bytes only within a separately authorized future runtime. */
function agency1417_ten_private(string $path, string $sha): array {
  if ($path === '' || !str_starts_with($path, '/') ||
      !preg_match('/^[a-f0-9]{64}$/D', $sha) || !is_file($path) ||
      is_link($path) || !is_readable($path) ||
      ((fileperms($path) & 0077) !== 0) ||
      (function_exists('posix_geteuid') && fileowner($path) !== posix_geteuid()) ||
      !hash_equals($sha, hash_file('sha256', $path))) {
    agency1417_ten_stop('PRIVATE_FILE_UNAVAILABLE_OR_INTEGRITY');
  }
  $data = json_decode(file_get_contents($path), TRUE, 512, JSON_THROW_ON_ERROR);
  if (!is_array($data)) {
    agency1417_ten_stop('INVALID_PRIVATE_JSON');
  }
  return $data;
}

/** Only previously existing, safe links are admissible. */
function agency1417_ten_links(string $html): array {
  if (preg_match('~<\s*(script|iframe|object|embed)\b~i', $html)) {
    agency1417_ten_stop('UNSAFE_HTML');
  }
  preg_match_all('~\bhref\s*=\s*(["\x27])([^\x27"]*)\1~i', $html, $found);
  $count = preg_match_all('~\bhref\s*=~i', $html);
  if ($count !== count($found[2])) {
    agency1417_ten_stop('UNQUOTED_OR_UNSAFE_LINK');
  }
  foreach ($found[2] as $url) {
    if ($url === '' || preg_match('~(^//|[\x00-\x1f]|preprod\.emergingdigital\.be|^(javascript|data|file|entity):)~i', $url) ||
        !preg_match('~^(https?://|mailto:|tel:|/|#)~i', $url)) {
      agency1417_ten_stop('UNSAFE_LINK_URI');
    }
  }
  return $found[2];
}

function agency1417_ten_same_links(string $fr, string $nl): void {
  if (agency1417_ten_links($fr) !== agency1417_ten_links($nl)) {
    agency1417_ten_stop('EDITORIAL_LINK_DRIFT');
  }
}

/**
 * Validates the two PRIVATE contracts without depending on real file contents.
 *
 * @return array<int, array{source: array, nl: array}>
 */
function agency1417_ten_batch(array $source, array $candidate): array {
  $ids = [37, 38, 39, 41, 45, 43, 44, 46, 47, 48];
  if (($source['contract'] ?? NULL) !== 'agency1417-10-prod-fr-texts-v1' ||
      ($candidate['contract'] ?? NULL) !== 'agency-1417-prod-10-nl-be-editorial-candidate-v1' ||
      ($candidate['source_contract'] ?? NULL) !== $source['contract'] ||
      ($candidate['source_sha256'] ?? NULL) !== '5817ef5ca96bf80c3bc9df00c5582260d765157c5b1b419d067708874da9cb83' ||
      ($candidate['language'] ?? NULL) !== 'nl-BE' ||
      ($candidate['publication_authorized'] ?? TRUE) !== FALSE ||
      ($candidate['runtime_write'] ?? NULL) !== 'NONE' ||
      count($source['items'] ?? []) !== 10 ||
      count($candidate['items'] ?? []) !== 10) {
    agency1417_ten_stop('PRIVATE_BATCH_CONTRACT');
  }
  $batch = [];
  foreach ($ids as $index => $id) {
    $fr = $source['items'][$index] ?? [];
    $nl = $candidate['items'][$index] ?? [];
    $bundle = $id === 41 ? 'page' : 'article';
    if (($fr['prod_nid'] ?? NULL) !== $id ||
        ($nl['prod_nid'] ?? NULL) !== $id ||
        ($fr['bundle'] ?? NULL) !== $bundle ||
        ($nl['bundle'] ?? NULL) !== $bundle ||
        ($fr['title_fr'] ?? NULL) !== ($nl['title_fr_for_review'] ?? NULL) ||
        ($fr['alias_fr'] ?? NULL) !== ($nl['alias_fr_for_review'] ?? NULL) ||
        ($nl['source_fr_sha256'] ?? NULL) !== agency1417_ten_digest($fr) ||
        ($nl['status_nl'] ?? NULL) !== 'DRAFT_NOT_PUBLISHED' ||
        !is_array($fr['fields_fr'] ?? NULL) ||
        !is_array($nl['fields_nl'] ?? NULL) ||
        !is_string($nl['fields_nl']['title'] ?? NULL) ||
        trim($nl['fields_nl']['title']) === '' ||
        !is_string($nl['fields_nl']['field_short_description'] ?? NULL)) {
      agency1417_ten_stop('CANDIDATE_NODE_IDENTITY_OR_FIELDS');
    }

    if ($bundle === 'article') {
      if (array_keys($nl['fields_nl']) !== ['title', 'field_short_description', 'body', 'field_feature_image'] ||
          !is_array($nl['fields_nl']['body'] ?? NULL) ||
          !is_string($nl['fields_nl']['body']['value'] ?? NULL) ||
          !is_string($nl['fields_nl']['body']['format'] ?? NULL) ||
          !is_string($nl['fields_nl']['field_feature_image']['alt'] ?? NULL) ||
          ($nl['fields_nl']['body']['format'] ?? NULL) !== ($fr['fields_fr']['body'][0]['format'] ?? NULL)) {
        agency1417_ten_stop('ARTICLE_FIELD_CONTRACT');
      }
      agency1417_ten_same_links(
        (string) ($fr['fields_fr']['body'][0]['value'] ?? ''),
        $nl['fields_nl']['body']['value'],
      );
    }
    else {
      $sections = $nl['fields_nl']['field_home_components'] ?? NULL;
      $originals = $fr['fields_fr']['field_home_components'] ?? NULL;
      if (array_keys($nl['fields_nl']) !== ['title', 'field_short_description', 'field_home_components'] ||
          !is_array($sections) || !is_array($originals) ||
          count($sections) !== 10 || count($originals) !== 10) {
        agency1417_ten_stop('PARAGRAPH_COUNT_UNPROVEN');
      }
      foreach ($sections as $offset => $section) {
        $original = $originals[$offset];
        if (($section['paragraph_index'] ?? NULL) !== $offset ||
            ($section['bundle'] ?? NULL) !== ($original['bundle'] ?? NULL) ||
            !is_array($section['fields_nl'] ?? NULL)) {
          agency1417_ten_stop('PARAGRAPH_MAPPING_UNPROVEN');
        }
        foreach ($section['fields_nl'] as $name => $items) {
          if (!in_array($name, ['field_heading', 'field_text', 'field_items', 'field_link', 'field_secondary_link'], TRUE) ||
              !array_key_exists($name, $original['fields'] ?? []) ||
              !is_array($items) || count($items) !== count($original['fields'][$name])) {
            agency1417_ten_stop('PARAGRAPH_FIELD_CONTRACT');
          }
          foreach ($items as $position => $item) {
            if (!is_array($item) || !is_array($original['fields'][$name][$position] ?? NULL)) {
              agency1417_ten_stop('PARAGRAPH_ITEM_CONTRACT');
            }
            $prior = $original['fields'][$name][$position];
            if (isset($item['uri'])) {
              if (($item['uri'] ?? NULL) !== ($prior['uri'] ?? NULL) ||
                  !is_string($item['title'] ?? NULL)) {
                agency1417_ten_stop('PARAGRAPH_LINK_DRIFT');
              }
              agency1417_ten_links('<a href="' . htmlspecialchars($item['uri'], ENT_QUOTES) . '">x</a>');
            }
            elseif (isset($item['value'])) {
              if (!is_string($item['value']) ||
                  (isset($item['format']) && ($item['format'] !== ($prior['format'] ?? NULL)))) {
                agency1417_ten_stop('PARAGRAPH_VALUE_CONTRACT');
              }
              agency1417_ten_same_links((string) ($prior['value'] ?? ''), $item['value']);
            }
            else {
              agency1417_ten_stop('PARAGRAPH_ITEM_CONTRACT');
            }
          }
        }
      }
    }
    $batch[$id] = ['source' => $fr, 'nl' => $nl];
  }
  return $batch;
}

/** Checks a canonical FR field recursively; never guesses media/Paragraph IDs. */
function agency1417_ten_canonical(ContentEntityInterface $entity, array $fields): void {
  foreach ($fields as $name => $original) {
    if (!$entity->hasField($name) || !is_array($original)) {
      agency1417_ten_stop('FR_FIELD_MISSING_' . $name);
    }
    $field = $entity->get($name);
    if ($name === 'field_home_components') {
      $refs = $field->referencedEntities();
      $raw = $field->getValue();
      if (count($refs) !== count($original) || count($raw) !== count($original)) {
        agency1417_ten_stop('PARAGRAPH_IDENTITY_UNPROVEN');
      }
      $seen = [];
      foreach (array_values($refs) as $i => $paragraph) {
        if (!$paragraph instanceof ContentEntityInterface || !$paragraph->hasTranslation('fr') ||
            !$paragraph->id() || !$paragraph->getRevisionId() ||
            !$paragraph->uuid() || isset($seen[$paragraph->uuid()]) ||
            $paragraph->bundle() !== ($original[$i]['bundle'] ?? NULL) ||
            (int) ($raw[$i]['target_revision_id'] ?? 0) !== (int) $paragraph->getRevisionId()) {
          agency1417_ten_stop('PARAGRAPH_IDENTITY_UNPROVEN');
        }
        $seen[$paragraph->uuid()] = TRUE;
        agency1417_ten_canonical($paragraph->getTranslation('fr'), $original[$i]['fields'] ?? []);
      }
    }
    elseif ($name === 'field_feature_image') {
      $raw = $field->getValue();
      if (count($raw) !== count($original)) {
        agency1417_ten_stop('MEDIA_IDENTITY_UNPROVEN');
      }
      foreach ($raw as $i => $fileItem) {
        $file = \Drupal::entityTypeManager()->getStorage('file')->load($fileItem['target_id'] ?? 0);
        if (!$file || !$file->uuid() ||
            ($fileItem['alt'] ?? NULL) !== ($original[$i]['alt'] ?? NULL) ||
            ($fileItem['title'] ?? NULL) !== ($original[$i]['title'] ?? NULL)) {
          agency1417_ten_stop('MEDIA_IDENTITY_UNPROVEN');
        }
      }
    }
    elseif ($name === 'field_blog_category') {
      $refs = $field->referencedEntities();
      if (count($refs) !== count($original)) {
        agency1417_ten_stop('TAXONOMY_IDENTITY_UNPROVEN');
      }
      foreach (array_values($refs) as $i => $term) {
        if (!$term->uuid() || ($original[$i]['kind'] ?? NULL) !== $term->bundle() ||
            ($original[$i]['label'] ?? NULL) !== $term->label()) {
          agency1417_ten_stop('TAXONOMY_IDENTITY_UNPROVEN');
        }
      }
    }
    elseif (agency1417_ten_digest($field->getValue()) !== agency1417_ten_digest($original)) {
      agency1417_ten_stop('FR_CANONICAL_DRIFT_' . $name);
    }
  }
}

/** A metadata-only FR/EN checkpoint (excluding revision timestamps). */
function agency1417_ten_snapshot(ContentEntityInterface $entity, string $language): string {
  $translation = $entity->getTranslation($language);
  $fields = [];
  foreach ($translation->getFields() as $name => $field) {
    if (!$field->getFieldDefinition()->isTranslatable() ||
        in_array($name, ['changed', 'created', 'revision_log', 'revision_timestamp',
          'revision_uid', 'revision_translation_affected',
          'content_translation_outdated', 'content_translation_created',
          'content_translation_uid', 'path'], TRUE)) {
      continue;
    }
    $fields[$name] = $field->getValue();
  }
  return agency1417_ten_digest($fields);
}

/**
 * PHPUnit includes the pure validators without invoking Drupal or the runtime.
 */
if (defined('AGENCY1417_TEN_SYNTHETIC_TEST')) {
  return;
}

$mode = getenv('AGENCY1417_TEN_MODE') ?: '';
if (!in_array($mode, ['dry-run', 'apply'], TRUE)) {
  agency1417_ten_stop('INVALID_MODE');
}
// A code flag is not an authorization. Future runtime must be separately
// approved with exact target/backup identity; never run this on PROD.
$user = posix_getpwuid(posix_geteuid());
$cwd = realpath(getcwd());
if (gethostname() !== 'agency-preprod-01' ||
    ($user['name'] ?? '') !== 'agency-preprod' ||
    $cwd === FALSE || !str_starts_with($cwd, '/var/www/agency-preprod/releases/')) {
  agency1417_ten_stop('NOT_AUTHORIZED_STAGING_SURFACE');
}
$candidate = agency1417_ten_private(
  getenv('AGENCY1417_TEN_CANDIDATE_FILE') ?: '',
  '98231b9c41fdb51b90834c81b0e6876d4eeb91399806bcafa36a6947d046ab86',
);
$source = agency1417_ten_private(
  getenv('AGENCY1417_TEN_FR_FILE') ?: '',
  '5817ef5ca96bf80c3bc9df00c5582260d765157c5b1b419d067708874da9cb83',
);
$batch = agency1417_ten_batch($source, $candidate);
if (\Drupal::languageManager()->getLanguage('nl') === NULL) {
  agency1417_ten_stop('NL_LANGUAGE_NOT_ACTIVE');
}
$storage = \Drupal::entityTypeManager()->getStorage('node');
$before = [];
$nodes = [];
foreach ($batch as $id => $entry) {
  $node = $storage->load($id);
  $fr = $entry['source'];
  if (!$node instanceof ContentEntityInterface ||
      $node->bundle() !== $fr['bundle'] ||
      !$node->hasTranslation('fr') || !$node->hasTranslation('en') ||
      $node->hasTranslation('nl') ||
      $node->getTranslation('fr')->label() !== $fr['title_fr'] ||
      $node->getTranslation('fr')->isPublished() !== $fr['published_fr'] ||
      (\Drupal::service('path_alias.repository')
        ->lookupBySystemPath('/node/' . $id, 'fr')['alias'] ?? NULL) !== $fr['alias_fr']) {
    agency1417_ten_stop('TARGET_IDENTITY_NL_OR_ALIAS_' . $id);
  }
  agency1417_ten_canonical($node->getTranslation('fr'), $fr['fields_fr']);
  $before[$id] = [
    'fr' => agency1417_ten_snapshot($node, 'fr'),
    'en' => agency1417_ten_snapshot($node, 'en'),
  ];
  $nodes[$id] = $node;
}
if ($mode === 'dry-run') {
  echo 'NL_TEN_PREFLIGHT=PASS NODES=10 WRITE=NONE PUBLIC_ALIASES=NONE' . PHP_EOL;
  return;
}
if (getenv('AGENCY1417_TEN_OWNER_STAGE_GO') !== 'YES' ||
    getenv('AGENCY1417_TEN_APPROVED_CANDIDATE_SHA256') !==
      '98231b9c41fdb51b90834c81b0e6876d4eeb91399806bcafa36a6947d046ab86' ||
    getenv('AGENCY1417_TEN_STAGE_BACKUP_VERIFIED') !== 'YES') {
  agency1417_ten_stop('SEPARATE_EXACT_STAGE_AUTHORITY_REQUIRED');
}
$transaction = \Drupal::database()->startTransaction();
try {
  foreach ($batch as $id => $entry) {
    $node = $nodes[$id];
    $frNode = $node->getTranslation('fr');
    $fields = $entry['nl']['fields_nl'];
    $values = [
      'title' => [['value' => $fields['title']]],
      'field_short_description' => $frNode->get('field_short_description')->getValue(),
    ];
    $values['field_short_description'][0]['value'] = $fields['field_short_description'];
    if ($id !== 41) {
      $values['body'] = [$fields['body']];
      // Retain the SAME existing file reference, translate ALT only.
      $values['field_feature_image'] = $frNode->get('field_feature_image')->getValue();
      foreach ($values['field_feature_image'] as &$image) {
        $image['alt'] = $fields['field_feature_image']['alt'];
      }
      unset($image);
    }
    else {
      $paragraphItems = [];
      $paragraphs = array_values($frNode->get('field_home_components')->referencedEntities());
      foreach ($fields['field_home_components'] as $index => $section) {
        $paragraph = $paragraphs[$index];
        if ($paragraph->hasTranslation('nl')) {
          agency1417_ten_stop('EXISTING_NL_PARAGRAPH');
        }
        $paragraph->addTranslation('nl', $section['fields_nl']);
        $paragraph->setNewRevision(TRUE);
        $paragraph->save();
        $paragraphItems[] = [
          'target_id' => $paragraph->id(),
          'target_revision_id' => $paragraph->getRevisionId(),
        ];
      }
      $values['field_home_components'] = $paragraphItems;
    }
    $translation = $node->addTranslation('nl', $values);
    $translation->setUnpublished();
    $node->setNewRevision(TRUE);
    $node->save();
    $storage->resetCache([$id]);
    $reloaded = $storage->load($id);
    if (!$reloaded->hasTranslation('nl') || $reloaded->getTranslation('nl')->isPublished() ||
        $before[$id]['fr'] !== agency1417_ten_snapshot($reloaded, 'fr') ||
        $before[$id]['en'] !== agency1417_ten_snapshot($reloaded, 'en')) {
      agency1417_ten_stop('POST_SAVE_LANGUAGE_OR_CONTENT_DRIFT');
    }
  }
}
catch (Throwable $error) {
  $transaction->rollBack();
  throw $error;
}
echo 'NL_TEN_DRAFT_APPLY=PASS NODES=10 STATUS=UNPUBLISHED PUBLIC_ALIASES=NONE' . PHP_EOL;

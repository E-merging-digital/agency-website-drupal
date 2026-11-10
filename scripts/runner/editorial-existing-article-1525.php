<?php

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * One existing published Article, through Drupal Core Entity API only.
 *
 * This file deliberately never creates a node, selects a nid, or touches IA.
 */
final class AgencyExistingArticle1525 {

  public function execute(string $mode, string $sourceUuid, string $prodUuid, string $candidateFile, string $assetFile, string $proof): array {
    if (!in_array($mode, ['export', 'dry-run', 'apply', 'postcheck'], TRUE)
      || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $sourceUuid)
      || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $prodUuid)) {
      throw new RuntimeException('Closed single-Article mode/identity required.');
    }
    $target = getenv('AGENCY_EXISTING_TARGET') ?: '';
    if (($mode === 'export' && $target !== 'PREPROD') || ($mode !== 'export' && $target !== 'PROD')) {
      throw new RuntimeException('Single-Article environment mismatch.');
    }
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties([
      'uuid' => $mode === 'export' ? $sourceUuid : $prodUuid,
    ]);
    if (count($nodes) !== 1) {
      throw new RuntimeException('Article UUID is absent or ambiguous.');
    }
    $node = reset($nodes);
    if ($node instanceof NodeInterface && self::isHeldIaIdentity($target, (int) $node->id())) {
      throw new RuntimeException('HOLD_IA_PREPROD_IDENTITY: historical PROD Article is not eligible.');
    }
    if (!$node instanceof NodeInterface || $node->bundle() !== 'article'
      || $node->language()->getId() !== 'fr' || !$node->hasTranslation('en')
      || !$node->isPublished() || !$node->hasField('field_feature_image')
      || !$node->hasField('field_short_description') || !$node->hasField('body')) {
      throw new RuntimeException('Existing published bilingual Article is not eligible.');
    }
    $aliases = \Drupal::service('path_alias.manager');
    $path = '/node/' . $node->id();
    $frAlias = $aliases->getAliasByPath($path, 'fr');
    $enAlias = $aliases->getAliasByPath($path, 'en');
    foreach ([$frAlias, $enAlias] as $alias) {
      if (!preg_match('#^/blog/[a-z0-9-]+$#D', $alias)
        || str_contains($alias, 'ia-pour-pme-7-cas-utiles')
        || str_contains($alias, 'ai-smes-7-useful-use-cases')) {
        throw new RuntimeException('Article alias is not in the eligible blog scope (IA held).');
      }
    }
    if ($mode === 'export') {
      $translations = [];
      $mediaHash = NULL;
      $sourceUri = NULL;
      foreach (['fr', 'en'] as $lang) {
        $tr = $node->getTranslation($lang);
        $item = $tr->get('field_feature_image')->first();
        $fid = $item?->get('target_id')->getValue();
        $file = $fid ? \Drupal::entityTypeManager()->getStorage('file')->load((int) $fid) : NULL;
        if (!$file instanceof FileInterface || !$file->isPermanent()) {
          throw new RuntimeException('Published Article feature image is unavailable.');
        }
        $uri = $file->getFileUri();
        if (!is_file($uri) || filesize($uri) <= 0 || filesize($uri) > 5000000
          || (getimagesize($uri)['mime'] ?? '') !== 'image/webp') {
          throw new RuntimeException('Article feature image is not a bounded WebP.');
        }
        $sha = hash_file('sha256', $uri);
        if (!is_string($sha) || ($mediaHash !== NULL && !hash_equals($mediaHash, $sha))) {
          throw new RuntimeException('FR and EN feature image bytes diverge.');
        }
        $mediaHash = $sha;
        $sourceUri = $uri;
        $alt = (string) ($item?->get('alt')->getValue() ?? '');
        if (trim($alt) === '') {
          throw new RuntimeException('Bilingual image ALT is required.');
        }
        $translations[$lang] = [
          'title' => $tr->label(),
          'short_description' => (string) $tr->get('field_short_description')->value,
          'short_description_format' => $this->fieldFormat($tr, 'field_short_description'),
          'body_html' => (string) $tr->get('body')->value,
          'body_format' => $this->fieldFormat($tr, 'body'),
          'alt' => $alt,
        ];
      }
      $snapshot = [
        'schema_version' => 2,
        'source_uuid' => $sourceUuid,
        'fr_alias' => $frAlias,
        'en_alias' => $enAlias,
        'image_sha256' => $mediaHash,
        'fr' => $translations['fr'],
        'en' => $translations['en'],
      ];
      if (!copy($sourceUri, $assetFile)) {
        throw new RuntimeException('Unable to snapshot reviewed Article WebP.');
      }
      $bytes = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
      if (file_put_contents($candidateFile, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Unable to snapshot reviewed Article fields.');
      }
      return $this->receipt($mode, $node, $frAlias, $enAlias, hash('sha256', $bytes), $mediaHash, 'EXPORTED');
    }

    if (!is_file($candidateFile) || !is_file($assetFile)) {
      throw new RuntimeException('Exact reviewed candidate and WebP are missing.');
    }
    $bytes = file_get_contents($candidateFile);
    $candidate = json_decode((string) $bytes, TRUE, 16, JSON_THROW_ON_ERROR);
    if (!is_array($candidate) || array_keys($candidate) !== [
      'schema_version', 'source_uuid', 'fr_alias', 'en_alias', 'image_sha256', 'fr', 'en',
    ] || $candidate['schema_version'] !== 2 || $candidate['source_uuid'] !== $sourceUuid
      || $candidate['fr_alias'] !== $frAlias || $candidate['en_alias'] !== $enAlias) {
      throw new RuntimeException('Target aliases or exact source provenance differ.');
    }
    foreach (['fr', 'en'] as $lang) {
      $fields = $candidate[$lang] ?? NULL;
      if (!is_array($fields) || array_keys($fields) !== [
        'title', 'short_description', 'short_description_format', 'body_html', 'body_format', 'alt',
      ]) {
        throw new RuntimeException('Exact bilingual Article fields are missing.');
      }
      foreach (['title', 'short_description', 'body_html', 'alt'] as $name) {
        $value = $fields[$name];
        if (!is_string($value) || trim($value) === '') {
          throw new RuntimeException('Incomplete bilingual Article field.');
        }
      }
      foreach (['short_description_format', 'body_format'] as $name) {
        if ($fields[$name] !== NULL && !is_string($fields[$name])) {
          throw new RuntimeException('Invalid reviewed text format.');
        }
      }
      $tr = $node->getTranslation($lang);
      if ($this->fieldFormat($tr, 'field_short_description') !== $fields['short_description_format']
        || $this->fieldFormat($tr, 'body') !== $fields['body_format']) {
        throw new RuntimeException('Target FR/EN text formats differ from reviewed PREPROD candidate.');
      }
    }
    $imageSha = $candidate['image_sha256'];
    if (!is_string($imageSha) || !preg_match('/^[0-9a-f]{64}$/D', $imageSha)
      || filesize($assetFile) <= 0 || filesize($assetFile) > 5000000
      || (getimagesize($assetFile)['mime'] ?? '') !== 'image/webp'
      || !hash_equals($imageSha, (string) hash_file('sha256', $assetFile))) {
      throw new RuntimeException('Reviewed WebP fingerprint differs.');
    }
    $candidateSha = hash('sha256', (string) $bytes);
    $revision = (int) $node->getRevisionId();
    $identityProof = hash('sha256', implode('|', [$sourceUuid, $prodUuid, (int) $node->id(), $revision, $frAlias, $enAlias, $candidateSha, $imageSha]));
    $exact = $this->matches($node, $candidate, $imageSha);
    if ($mode === 'apply') {
      if (!preg_match('/^[0-9a-f]{64}$/D', $proof) || !hash_equals($identityProof, $proof)) {
        throw new RuntimeException('Fresh exact target revision proof is required before UPDATE.');
      }
      if (!$exact) {
        $created = $node->getCreatedTime();
        $categories = [];
        foreach (['field_category', 'field_categories', 'field_blog_category', 'field_blog_categories', 'field_tags'] as $field) {
          if ($node->hasField($field)) {
            $categories[$field] = $node->get($field)->getValue();
          }
        }
        $file = $this->file($imageSha, $assetFile);
        foreach (['fr', 'en'] as $lang) {
          $tr = $node->getTranslation($lang);
          $fields = $candidate[$lang];
          $tr->setTitle($fields['title']);
          $tr->get('field_short_description')->first()->set('value', $fields['short_description']);
          $tr->get('body')->first()->set('value', $fields['body_html']);
          $tr->set('field_feature_image', [['target_id' => $file->id(), 'alt' => $fields['alt']]]);
          $tr->set('path', ['alias' => $candidate[$lang . '_alias'], 'pathauto' => 0]);
        }
        $node->setNewRevision(TRUE);
        $node->setRevisionLogMessage('Agency #1525 exact existing Article revision');
        if ($node->validate()->count() !== 0) {
          throw new RuntimeException('Drupal Article validation failed before save.');
        }
        $node->save();
        \Drupal::entityTypeManager()->getStorage('node')->resetCache([(int) $node->id()]);
        $node = \Drupal::entityTypeManager()->getStorage('node')->load((int) $node->id());
        $aliases->cacheClear('/node/' . $node->id());
        if ($node->uuid() !== $prodUuid || $node->getCreatedTime() !== $created
          || (int) $node->getRevisionId() <= $revision) {
          throw new RuntimeException('Updated Article identity, date or revision drift.');
        }
        foreach ($categories as $field => $value) {
          if ($node->get($field)->getValue() !== $value) {
            throw new RuntimeException('Updated Article category drift.');
          }
        }
      }
    }
    if (in_array($mode, ['apply', 'postcheck'], TRUE)
      && (!$this->matches($node, $candidate, $imageSha)
        || $aliases->getAliasByPath('/node/' . $node->id(), 'fr') !== $frAlias
        || $aliases->getAliasByPath('/node/' . $node->id(), 'en') !== $enAlias)) {
      throw new RuntimeException('Article postcheck differs from exact bilingual candidate.');
    }
    $receipt = $this->receipt($mode, $node, $frAlias, $enAlias, $candidateSha, $imageSha,
      $exact ? 'IDEMPOTENT' : ($mode === 'apply' ? 'UPDATED' : 'UPDATE_READY'));
    $receipt['proof'] = $identityProof;
    $receipt['revision_before'] = $revision;
    return $receipt;
  }

  private function matches(NodeInterface $node, array $candidate, string $sha): bool {
    foreach (['fr', 'en'] as $lang) {
      $tr = $node->getTranslation($lang);
      $image = $tr->get('field_feature_image')->first();
      $fid = $image?->get('target_id')->getValue();
      $file = $fid ? \Drupal::entityTypeManager()->getStorage('file')->load((int) $fid) : NULL;
      if ($tr->label() !== $candidate[$lang]['title']
        || (string) $tr->get('field_short_description')->value !== $candidate[$lang]['short_description']
        || $this->fieldFormat($tr, 'field_short_description') !== $candidate[$lang]['short_description_format']
        || (string) $tr->get('body')->value !== $candidate[$lang]['body_html']
        || $this->fieldFormat($tr, 'body') !== $candidate[$lang]['body_format']
        || (string) ($image?->get('alt')->getValue() ?? '') !== $candidate[$lang]['alt']
        || !$file instanceof FileInterface
        || !hash_equals($sha, (string) hash_file('sha256', $file->getFileUri()))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The held historic PROD nid is a veto after UUID-based lookup, never a selector.
   */
  private static function isHeldIaIdentity(string $target, int $nid): bool {
    return $target === 'PROD' && $nid === 40;
  }

  /**
   * Preserve the exact field item format, including a field without a format.
   */
  private function fieldFormat(NodeInterface $translation, string $field): ?string {
    $item = $translation->get($field)->first();
    if ($item === NULL) {
      throw new RuntimeException('Existing bilingual text field item is missing.');
    }
    $format = $item->getValue()['format'] ?? NULL;
    if ($format !== NULL && !is_string($format)) {
      throw new RuntimeException('Existing bilingual text format is invalid.');
    }
    return $format;
  }

  private function file(string $sha, string $asset): FileInterface {
    $directory = 'public://articles';
    if (!\Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new RuntimeException('Article image directory unavailable.');
    }
    $uri = $directory . '/approved-' . $sha . '.webp';
    $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
    if (count($files) > 1 || ($files === [] && file_exists($uri))) {
      throw new RuntimeException('Ambiguous existing Article WebP destination.');
    }
    if ($files !== []) {
      $file = reset($files);
      if (!$file instanceof FileInterface || !hash_equals($sha, (string) hash_file('sha256', $file->getFileUri()))) {
        throw new RuntimeException('Existing WebP bytes conflict.');
      }
      return $file;
    }
    $file = \Drupal::service('file.repository')->writeData((string) file_get_contents($asset), $uri, FileExists::Error);
    $file->setPermanent();
    $file->save();
    return $file;
  }

  private function receipt(string $mode, NodeInterface $node, string $frAlias, string $enAlias, string $sourceSha, string $imageSha, string $verdict): array {
    return [
      'status' => 'PASS', 'mode' => $mode, 'target' => getenv('AGENCY_EXISTING_TARGET'),
      'source_uuid' => getenv('AGENCY_EXISTING_SOURCE_UUID'),
      'prod_uuid' => getenv('AGENCY_EXISTING_PROD_UUID'),
      'nid' => (int) $node->id(), 'uuid' => $node->uuid(), 'revision' => (int) $node->getRevisionId(),
      'fr_url' => 'https://' . ((getenv('AGENCY_EXISTING_TARGET') === 'PREPROD') ? 'preprod.' : '') . 'emergingdigital.be/fr' . $frAlias,
      'en_url' => 'https://' . ((getenv('AGENCY_EXISTING_TARGET') === 'PREPROD') ? 'preprod.' : '') . 'emergingdigital.be/en' . $enAlias,
      'source_sha256' => $sourceSha, 'image_sha256' => $imageSha,
      'verdict' => $verdict,
      'prod_write' => $mode === 'apply' ? 'BOUNDED_UPDATE' : 'NONE',
      'hold_ia' => 'PRESERVED',
    ];
  }
}

$result = getenv('AGENCY_EXISTING_RESULT') ?: '';
try {
  if ($result === '') {
    throw new RuntimeException('Result path is required.');
  }
  $value = (new AgencyExistingArticle1525())->execute(
    getenv('AGENCY_EXISTING_MODE') ?: '',
    getenv('AGENCY_EXISTING_SOURCE_UUID') ?: '',
    getenv('AGENCY_EXISTING_PROD_UUID') ?: '',
    getenv('AGENCY_EXISTING_CANDIDATE') ?: '',
    getenv('AGENCY_EXISTING_ASSET') ?: '',
    getenv('AGENCY_EXISTING_PROOF') ?: '',
  );
  file_put_contents($result, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
catch (Throwable $exception) {
  if ($result !== '') {
    file_put_contents($result, json_encode(['status' => 'FAIL', 'prod_write' => 'NONE', 'reason' => $exception->getMessage()]) . "\n");
  }
  throw $exception;
}

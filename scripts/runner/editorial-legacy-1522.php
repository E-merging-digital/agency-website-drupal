<?php

declare(strict_types=1);

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/**
 * Bounded revision of three existing bilingual Articles (#1522).
 *
 * Uses Drupal core Entity API; no create-node or arbitrary entity/path/field
 * capability. PROD requires separately governed transport and exact UUIDs.
 */
final class AgencyLegacyEditorial1522 {

  private const SCOPE = [
    [
      'fr' => '/blog/ia-pour-pme-7-cas-utiles-et-les-limites-poser-avant-dautomatiser',
      'en' => '/blog/ai-smes-7-useful-use-cases-and-limits-set-automating',
      'file' => 'issue-1522-ai-pme-sept-usages.webp',
      'sha' => '31821fa06570cf1135056f98398d0b329a7c330238542cf342d18f95cbba9072',
    ],
    [
      'fr' => '/blog/drupal-10-arrive-en-fin-de-support-comment-preparer-votre-passage-drupal-11-avant-le-9',
      'en' => '/blog/drupal-10-end-life-how-prepare-your-upgrade-drupal-11-december-9-2026',
      'uuid' => '196a8ad1-ff50-41f5-82f0-14d662f48a6f',
      'file' => 'issue-1522-drupal-10-vers-11.webp',
      'sha' => 'a28485b4940934da889580cd5a449f77e7515521eb8f02d850f5eb099d772399',
    ],
    [
      'fr' => '/blog/drupal-symfony-ou-laravel-quelle-base-pour-votre-projet-web',
      'en' => '/blog/drupal-symfony-or-laravel-which-foundation-fits-your-web-project',
      'uuid' => '3d638f3e-8a31-4b7c-9466-c40f70f4999a',
      'file' => 'issue-1522-drupal-symfony-laravel.webp',
      'sha' => '0eaca29c4e740a7df42bc299106c16b3619a0c2f21aa8070055e8530235626ff',
    ],
    [
      'fr' => '/blog/checklist-avant-une-refonte-de-site-internet-12-points-verifier',
      'en' => '/blog/website-redesign-checklist-12-things-verify-you-start',
      'uuid' => '8b5c25de-88ce-4493-a28a-54d55957baaf',
      'file' => 'issue-1522-refonte-site-checklist.webp',
      'sha' => '6741c1260f4d76b197ce9308fa61b819ae0cb505115c4c792bfca183f60dd0a8',
    ],
  ];

  public function __construct(
    private readonly \Drupal\Core\Entity\EntityTypeManagerInterface $entities,
    private readonly \Drupal\path_alias\AliasManagerInterface $aliases,
    private readonly \Drupal\file\FileRepositoryInterface $files,
    private readonly FileSystemInterface $fileSystem,
    private readonly \Drupal\Core\Session\AccountSwitcherInterface $accounts,
  ) {}

  public static function fromDrupal(): self {
    $container = \Drupal::getContainer();
    return new self(
      $container->get('entity_type.manager'),
      $container->get('path_alias.manager'),
      $container->get('file.repository'),
      $container->get('file_system'),
      $container->get('account_switcher'),
    );
  }

  public function execute(array $candidate, string $assets, string $mode): array {
    $target = getenv('AGENCY_LEGACY_1522_TARGET') ?: 'PREPROD';
    if (!in_array($target, ['PREPROD', 'PROD'], TRUE)) {
      throw new RuntimeException('Closed #1522 target mismatch.');
    }
    if (!in_array($mode, ['inspect', 'dry-run', 'apply'], TRUE)
      || ($candidate['schema_version'] ?? NULL) !== 1
      || ($candidate['issue_number'] ?? NULL) !== 1522
      || !is_array($candidate['articles'] ?? NULL)
      || count($candidate['articles']) !== 4) {
      throw new RuntimeException('Closed #1522 PREPROD revision contract mismatch.');
    }

    // The historic IA Article is missing from PREPROD and stays on HOLD.
    // The prior dry-run authorizes each Article by its exact identity/revision.
    $allowed = [];
    $allowlist = getenv('AGENCY_LEGACY_1522_ALLOWLIST') ?: '';
    if ($mode === 'apply') {
      if (!preg_match('/^2:([0-9a-f]{64}|HOLD),3:([0-9a-f]{64}|HOLD),4:([0-9a-f]{64}|HOLD)$/D', $allowlist, $matches)) {
        throw new RuntimeException('Exact per-Article dry-run authorization is missing.');
      }
      $allowed = [2 => $matches[1], 3 => $matches[2], 4 => $matches[3]];
    }
    elseif ($allowlist !== '') {
      throw new RuntimeException('Dry-run authorization is not accepted outside apply.');
    }

    // Preflight is independent by Article; no writes until all identities are classified.
    $plan = [];
    $seen = [];
    $issues = [];
    foreach (self::SCOPE as $index => $scope) {
      $item = $candidate['articles'][$index];
      if (!is_array($item) || ($item['aliases'] ?? NULL) !== ['fr' => $scope['fr'], 'en' => $scope['en']]
        || ($item['image']['path'] ?? NULL) !== 'assets/editorial/' . $scope['file']
        || ($item['image']['sha256'] ?? NULL) !== $scope['sha']) {
        throw new RuntimeException('Unexpected Article, alias or media in #1522 candidate.');
      }
      if ($index === 0) {
        // No PREPROD alias/nid is proven for the IA Article: never create or modify it here.
        continue;
      }
      $number = $index + 1;
      try {
        foreach (['fr', 'en'] as $lang) {
          $fields = $item[$lang] ?? NULL;
          if (!is_array($fields)
            || array_keys($fields) !== ['title', 'short_description', 'seo_title', 'seo_description', 'body_html']
            || !is_string($fields['title']) || trim($fields['title']) === ''
            || !is_string($fields['short_description']) || trim($fields['short_description']) === ''
            || !is_string($fields['body_html']) || !str_contains($fields['body_html'], '<p>')) {
            throw new RuntimeException('Incomplete bilingual article candidate.');
          }
          if (!is_string($item['image']['alt'][$lang] ?? NULL)
            || trim($item['image']['alt'][$lang]) === '') {
            throw new RuntimeException('Missing bilingual feature image ALT.');
          }
        }
        $asset = $assets . '/' . $scope['file'];
        if (!is_file($asset) || filesize($asset) <= 0 || filesize($asset) > 5000000
          || !hash_equals($scope['sha'], (string) hash_file('sha256', $asset))) {
          throw new RuntimeException('Approved WebP bytes missing or different.');
        }
        $raster = getimagesize($asset);
        if (!is_array($raster) || ($raster['mime'] ?? NULL) !== 'image/webp'
          || $raster[0] !== 1672 || $raster[1] !== 941) {
          throw new RuntimeException('Approved WebP type/dimensions mismatch.');
        }
        // In PROD, a conflicting destination must fail during read-only
        // preflight, before any of the three Article revisions can be saved.
        if ($target === 'PROD') {
          $uri = 'public://articles/' . $scope['file'];
          $existingFiles = $this->entities->getStorage('file')->loadByProperties(['uri' => $uri]);
          if (count($existingFiles) > 1) {
            throw new RuntimeException('Ambiguous PROD feature image File entity.');
          }
          if ($existingFiles !== []) {
            $existingFile = reset($existingFiles);
            if (!$existingFile instanceof FileInterface
              || !hash_equals($scope['sha'], (string) hash_file('sha256', $existingFile->getFileUri()))) {
              throw new RuntimeException('PROD WebP destination bytes differ.');
            }
          }
          elseif (file_exists($uri)) {
            throw new RuntimeException('PROD WebP exists without File entity.');
          }
        }

        $pathFr = $this->aliases->getPathByAlias($scope['fr'], 'fr');
        $pathEn = $this->aliases->getPathByAlias($scope['en'], 'en');
        $frResolved = preg_match('#^/node/([1-9][0-9]*)$#', $pathFr, $frMatch) === 1;
        $enResolved = preg_match('#^/node/([1-9][0-9]*)$#', $pathEn, $enMatch) === 1;
        if (!$frResolved || !$enResolved || $pathEn !== $pathFr) {
          throw new RuntimeException(sprintf(
            'candidate=%d fr_nid=%s en_nid=%s',
            $number,
            $frResolved ? $frMatch[1] : 'UNRESOLVED',
            $enResolved ? $enMatch[1] : 'UNRESOLVED',
          ));
        }
        $nid = (int) $frMatch[1];
        if (isset($seen[$nid])) {
          $issues[$seen[$nid]] = 'Two candidates resolve to the same Article.';
          unset($plan[$seen[$nid]]);
          throw new RuntimeException('Two candidates resolve to the same Article.');
        }
        $seen[$nid] = $number;
        $node = $this->entities->getStorage('node')->load($nid);
        if (!$node instanceof NodeInterface || $node->bundle() !== 'article'
          || $node->language()->getId() !== 'fr' || !$node->hasTranslation('en')
          || !$node->hasField('field_feature_image') || !$node->isPublished()) {
          throw new RuntimeException('Existing published bilingual Article cannot be proven.');
        }
        if ($target === 'PROD' && !hash_equals($scope['uuid'], $node->uuid())) {
          throw new RuntimeException('PROD Article UUID differs from approved PREPROD identity.');
        }
        $proof = hash('sha256', implode('|', [1522, $number, $nid, $node->uuid(), $node->getRevisionId(), $scope['sha'], $scope['fr'], $scope['en']]));
        if ($mode === 'apply' && ($allowed[$number] === 'HOLD' || !hash_equals($allowed[$number], $proof))) {
          throw new RuntimeException('Article absent or identity/revision changed since exact dry-run.');
        }
        $plan[$number] = ['node' => $node, 'scope' => $scope, 'item' => $item, 'asset' => $asset, 'candidate_index' => $number, 'proof' => $proof];
      }
      catch (Throwable $exception) {
        $issues[$number] = $exception->getMessage();
      }
    }

    $author = $this->entities->getStorage('user')->load(1);
    // PROD must be all-or-nothing at the preflight boundary: a missing or
    // divergent historical Article cannot cause a partial batch mutation.
    if ($target === 'PROD' && count($plan) !== 3) {
      throw new RuntimeException('PROD three-Article identity preflight failed closed: ' . implode('; ', $issues));
    }
    if ($mode === 'apply' && (!$author instanceof UserInterface || !$author->isActive())) {
      throw new RuntimeException('Existing technical Drupal author is unavailable.');
    }

    $results = [];
    foreach ([2, 3, 4] as $number) {
      if (isset($issues[$number])) {
        $results[] = ['candidate_index' => $number, 'status' => 'CONTROLLED_STOP', 'proof' => 'HOLD', 'reason' => $issues[$number]];
        continue;
      }
      $entry = $plan[$number];
      try {
        $node = $entry['node'];
        $scope = $entry['scope'];
        $item = $entry['item'];
        $oldRevision = (int) $node->getRevisionId();
        $uuid = $node->uuid();
        $created = $node->getCreatedTime();
        $alreadyExact = $this->matches($node, $scope, $item);
        if ($mode === 'apply' && !$alreadyExact) {
          $this->accounts->switchTo($author);
          try {
            $fid = (int) $this->materialize($scope, $entry['asset'])->id();
            foreach (['fr', 'en'] as $lang) {
              $translation = $node->getTranslation($lang);
              $fields = $item[$lang];
              $translation->setTitle($fields['title']);
              $translation->set('field_short_description', [['value' => $fields['short_description'], 'format' => 'basic_html']]);
              $translation->set('body', [['value' => $fields['body_html'], 'format' => 'basic_html']]);
              $translation->set('field_feature_image', [['target_id' => $fid, 'alt' => $item['image']['alt'][$lang]]]);
              // Title changes must NOT let Pathauto regenerate historic URLs.
              $translation->set('path', ['alias' => $scope[$lang], 'pathauto' => 0]);
            }
            $node->setNewRevision(TRUE);
            $node->setRevisionUserId(1);
            $node->setRevisionLogMessage('Agency #1522 exact existing Article bilingual ' . $target . ' revision');
            $violations = $node->validate();
            if ($violations->count() > 0) {
              throw new RuntimeException('Article constraint violations before save.');
            }
            $node->save();
          }
          finally {
            $this->accounts->switchBack();
          }
          $this->entities->getStorage('node')->resetCache([(int) $node->id()]);
          $node = $this->entities->getStorage('node')->load((int) $node->id());
          $this->aliases->cacheClear('/node/' . $node->id());
        }
        if ($node->uuid() !== $uuid || $node->getCreatedTime() !== $created
          || $this->aliases->getAliasByPath('/node/' . $node->id(), 'fr') !== $scope['fr']
          || $this->aliases->getAliasByPath('/node/' . $node->id(), 'en') !== $scope['en']) {
          throw new RuntimeException('Existing Article identity, original date or alias drift.');
        }
        if ($mode === 'apply' && !$alreadyExact && (int) $node->getRevisionId() <= $oldRevision) {
          throw new RuntimeException('Existing Article revision was not incremented.');
        }
        if ($mode === 'apply' && !$this->matches($node, $scope, $item)) {
          throw new RuntimeException('Persisted bilingual Article, image or ALT differs from exact candidate.');
        }
        $results[] = [
          'candidate_index' => $entry['candidate_index'],
          'status' => 'PASS',
          'proof' => $entry['proof'],
          'nid' => (int) $node->id(),
          'uuid' => $uuid,
          'revision_before' => $oldRevision,
          'revision_after' => (int) $node->getRevisionId(),
          'fr_url' => ($target === 'PROD' ? 'https://emergingdigital.be/fr' : 'https://preprod.emergingdigital.be/fr') . $scope['fr'],
          'en_url' => ($target === 'PROD' ? 'https://emergingdigital.be/en' : 'https://preprod.emergingdigital.be/en') . $scope['en'],
          'image_sha256' => $scope['sha'],
          'verdict' => $alreadyExact ? 'IDEMPOTENT' : ($mode === 'apply' ? 'UPDATED' : 'UPDATE_READY'),
        ];
      }
      catch (Throwable $exception) {
        $results[] = ['candidate_index' => $number, 'status' => 'CONTROLLED_STOP', 'proof' => 'HOLD', 'reason' => $exception->getMessage()];
      }
    }

    $passed = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'PASS'));
    $status = $passed === 3 ? 'PASS' : ($passed > 0 ? 'PARTIAL' : 'CONTROLLED_STOP');
    $eligibility = implode(',', array_map(static fn (array $row): string => $row['candidate_index'] . ':' . $row['proof'], $results));

    return [
      'status' => $status,
      'target' => $target,
      'prod_write' => $target === 'PROD' && $mode === 'apply' ? 'BOUNDED_UPDATE' : 'NONE',
      'issue_number' => 1522,
      'mode' => $mode,
      'held_article' => 'IA_PREPROD_IDENTITY',
      'eligible' => $eligibility,
      'articles' => $results,
    ];
  }

  private function matches(NodeInterface $node, array $scope, array $item): bool {
    foreach (['fr', 'en'] as $lang) {
      $translation = $node->getTranslation($lang);
      $fields = $item[$lang];
      $imageItem = $translation->get('field_feature_image')->first();
      $fid = $imageItem?->get('target_id')->getValue();
      $existing = $fid ? $this->entities->getStorage('file')->load((int) $fid) : NULL;
      $assetExact = $existing instanceof FileInterface
        && hash_equals($scope['sha'], (string) hash_file('sha256', $existing->getFileUri()));
      if ($translation->label() !== $fields['title']
        || (string) $translation->get('field_short_description')->value !== $fields['short_description']
        || (string) $translation->get('body')->value !== $fields['body_html']
        || !$assetExact
        || (string) ($imageItem?->get('alt')->getValue() ?? '') !== $item['image']['alt'][$lang]) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function materialize(array $scope, string $asset): FileInterface {
    $directory = 'public://articles';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new RuntimeException('Existing Article media directory is unavailable.');
    }
    $uri = $directory . '/' . $scope['file'];
    $storage = $this->entities->getStorage('file');
    $existing = $storage->loadByProperties(['uri' => $uri]);
    if (count($existing) > 1) {
      throw new RuntimeException('Ambiguous existing WebP File entity.');
    }
    if ($existing !== []) {
      $file = reset($existing);
      if (!$file instanceof FileInterface
        || !hash_equals($scope['sha'], (string) hash_file('sha256', $file->getFileUri()))) {
        throw new RuntimeException('Existing WebP destination bytes differ.');
      }
      return $file;
    }
    if (file_exists($uri)) {
      throw new RuntimeException('WebP exists without File entity; manual inspection required.');
    }
    $bytes = file_get_contents($asset);
    if (!is_string($bytes) || $bytes === '') {
      throw new RuntimeException('Approved WebP is unreadable.');
    }
    $file = $this->files->writeData($bytes, $uri, FileExists::Error);
    $file->setPermanent();
    $file->setOwnerId(1);
    $file->save();
    return $file;
  }
}

$resultPath = getenv('AGENCY_LEGACY_1522_RESULT') ?: '';
try {
  $mode = getenv('AGENCY_LEGACY_1522_MODE') ?: '';
  $candidatePath = getenv('AGENCY_LEGACY_1522_CANDIDATE') ?: '';
  $assetDirectory = getenv('AGENCY_LEGACY_1522_ASSETS') ?: '';
  if ($resultPath === '' || !is_file($candidatePath) || !is_dir($assetDirectory)) {
    throw new RuntimeException('Required closed #1522 runtime input is missing.');
  }
  $candidate = json_decode((string) file_get_contents($candidatePath), TRUE, 12, JSON_THROW_ON_ERROR);
  $result = AgencyLegacyEditorial1522::fromDrupal()->execute($candidate, $assetDirectory, $mode);
  file_put_contents($resultPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
catch (Throwable $exception) {
  if ($resultPath !== '') {
    file_put_contents($resultPath, json_encode([
      'status' => 'FAIL',
      'target' => getenv('AGENCY_LEGACY_1522_TARGET') ?: 'PREPROD',
      'prod_write' => 'NONE',
      'message' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
  }
  throw $exception;
}

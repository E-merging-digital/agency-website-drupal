<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PREPROD-only orchestration around the existing #576 Article publisher.
 *
 * The closed Article schema, validation and initial create path remain owned by
 * AgencyEditorialPublication. This helper adds only the disposable PREPROD
 * candidate update path required when the same editorial issue receives a new
 * payload hash before human review.
 */
final class AgencyEditorialPreprodCandidate {

  private const BUNDLE = 'article';
  private const STATE_PREFIX = 'agency_editorial.issue.';
  private const SOURCE_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const TEXT_FORMAT = 'basic_html';
  private const CATEGORY_VOCABULARY = 'blog_categories';
  private const AUTHOR_UID = 1;

  public function __construct(
    private readonly AgencyEditorialPublication $publisher,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      AgencyEditorialPublication::fromContainer($container),
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('account_switcher'),
    );
  }

  /**
   * Read-only inspection of the reused Article contract and PREPROD mapping.
   */
  public function inspect(int $issueNumber): array {
    $result = $this->publisher->inspect($issueNumber);
    $result['target'] = 'PREPROD';
    $result['candidate_id'] = $this->candidateId($issueNumber);
    $result['candidate_store'] = 'GITHUB_ISSUE_COMMENT';
    $result['prod_write'] = 'NONE';
    return $result;
  }

  /**
   * Validates create, exact replay or changed-payload update without writing.
   */
  public function dryRun(array $payload, int $issueNumber, string $payloadSha): array {
    $this->publisher->validatePayload($payload, $issueNumber);
    $inspect = $this->publisher->inspect($issueNumber);
    if (($inspect['verdict'] ?? NULL) !== 'READY') {
      throw new RuntimeException('Editorial PREPROD runtime prerequisites are not ready.');
    }

    $mapping = $inspect['runtime']['mapping'] ?? NULL;
    if ($mapping === NULL) {
      $result = $this->publisher->dryRun($payload, $issueNumber, $payloadSha);
      return $this->candidateResult($result, $issueNumber);
    }
    if (!is_array($mapping)) {
      throw new RuntimeException('Editorial candidate mapping has an invalid shape.');
    }

    $mappedId = $mapping['node_id'] ?? NULL;
    $mappedHash = $mapping['payload_sha256'] ?? NULL;
    if (!is_int($mappedId) || !is_string($mappedHash)) {
      throw new RuntimeException('Editorial candidate mapping is incomplete.');
    }
    if ($mappedHash === $payloadSha) {
      $result = $this->publisher->dryRun($payload, $issueNumber, $payloadSha);
      return $this->candidateResult($result, $issueNumber);
    }

    $node = $this->loadMappedArticle($mappedId);
    $category = $this->resolveCategory($payload['category']);
    $this->assertNoOtherTitleCollision($payload['fr']['title'], $mappedId);

    return [
      'status' => 'PASS',
      'verdict' => 'UPDATE_READY',
      'mode' => 'dry-run',
      'target' => 'PREPROD',
      'issue_number' => $issueNumber,
      'candidate_id' => $this->candidateId($issueNumber),
      'payload_sha256' => $payloadSha,
      'previous_payload_sha256' => $mappedHash,
      'category' => [
        'tid' => (int) $category->id(),
        'fr' => $category->label(),
      ],
      'node' => [
        'id' => (int) $node->id(),
        'revision_id' => (int) $node->getRevisionId(),
      ],
      'prod_write' => 'NONE',
    ];
  }

  /**
   * Creates through #576 or updates only the already-mapped PREPROD Article.
   */
  public function apply(array $payload, int $issueNumber, string $payloadSha): array {
    $dryRun = $this->dryRun($payload, $issueNumber, $payloadSha);
    if (($dryRun['verdict'] ?? NULL) !== 'UPDATE_READY') {
      $result = $this->publisher->apply($payload, $issueNumber, $payloadSha);
      return $this->candidateResult($result, $issueNumber);
    }

    $mappedId = (int) $dryRun['node']['id'];
    $node = $this->loadMappedArticle($mappedId);
    $category = $this->resolveCategory($payload['category']);
    $author = $this->loadAuthor();
    $this->accountSwitcher->switchTo($author);

    try {
      $node->setTitle($payload['fr']['title']);
      $node->set('field_short_description', [[
        'value' => $payload['fr']['short_description'],
        'format' => self::TEXT_FORMAT,
      ]]);
      $node->set('body', [[
        'value' => $payload['fr']['body_html'],
        'format' => self::TEXT_FORMAT,
      ]]);
      $node->set('field_blog_category', [['target_id' => (int) $category->id()]]);
      $node->set('status', $payload['published']);

      if ($node->hasTranslation(self::TRANSLATION_LANGCODE)) {
        $translation = $node->getTranslation(self::TRANSLATION_LANGCODE);
        $translation->setTitle($payload['en']['title']);
        $translation->set('field_short_description', [[
          'value' => $payload['en']['short_description'],
          'format' => self::TEXT_FORMAT,
        ]]);
        $translation->set('body', [[
          'value' => $payload['en']['body_html'],
          'format' => self::TEXT_FORMAT,
        ]]);
        $translation->set('field_blog_category', [['target_id' => (int) $category->id()]]);
        $translation->set('status', $payload['published']);
      }
      else {
        $node->addTranslation(self::TRANSLATION_LANGCODE, [
          'title' => $payload['en']['title'],
          'field_short_description' => [[
            'value' => $payload['en']['short_description'],
            'format' => self::TEXT_FORMAT,
          ]],
          'body' => [[
            'value' => $payload['en']['body_html'],
            'format' => self::TEXT_FORMAT,
          ]],
          'field_blog_category' => [['target_id' => (int) $category->id()]],
          'status' => $payload['published'],
        ]);
      }

      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage(sprintf(
        'Agency PREPROD editorial candidate issue #%d payload %s',
        $issueNumber,
        $payloadSha,
      ));
      $node->setRevisionUserId(self::AUTHOR_UID);

      $violations = $node->validate();
      if ($violations->count() > 0) {
        $messages = [];
        foreach ($violations as $violation) {
          $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }
        throw new RuntimeException('PREPROD Article validation failed: ' . implode(' | ', $messages));
      }

      $node->save();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $this->state->set(self::STATE_PREFIX . $issueNumber, [
      'node_id' => (int) $node->id(),
      'payload_sha256' => $payloadSha,
    ]);
    $this->entityTypeManager->getStorage('node')->resetCache([(int) $node->id()]);

    // Reuse #576's own category, mapping and node-result verification after save.
    $verified = $this->publisher->dryRun($payload, $issueNumber, $payloadSha);
    if (($verified['verdict'] ?? NULL) !== 'IDEMPOTENT') {
      throw new RuntimeException('Updated PREPROD candidate did not converge idempotently.');
    }
    $verified['verdict'] = 'UPDATED';
    $verified['mode'] = 'apply';
    $verified['previous_payload_sha256'] = $dryRun['previous_payload_sha256'];
    return $this->candidateResult($verified, $issueNumber);
  }

  private function candidateResult(array $result, int $issueNumber): array {
    $result['target'] = 'PREPROD';
    $result['candidate_id'] = $this->candidateId($issueNumber);
    $result['candidate_store'] = 'GITHUB_ISSUE_COMMENT';
    $result['prod_write'] = 'NONE';
    return $result;
  }

  private function candidateId(int $issueNumber): string {
    return 'agency-article-' . $issueNumber;
  }

  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface || !$author->isActive()) {
      throw new RuntimeException('Required Drupal author uid=1 is unavailable.');
    }
    return $author;
  }

  private function loadMappedArticle(int $nodeId): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      throw new RuntimeException('Editorial candidate mapping does not point to an Article.');
    }
    if ($node->language()->getId() !== self::SOURCE_LANGCODE) {
      throw new RuntimeException('Editorial candidate source language is not FR.');
    }
    return $node;
  }

  private function resolveCategory(array $category): TermInterface {
    $term = $this->entityTypeManager->getStorage('taxonomy_term')->load((int) $category['tid']);
    if (!$term instanceof TermInterface || $term->bundle() !== self::CATEGORY_VOCABULARY) {
      throw new InvalidArgumentException('Selected Blog category does not exist in PREPROD.');
    }
    $expected = trim(preg_replace('/\s+/u', ' ', (string) $category['name']) ?? '');
    $actual = trim(preg_replace('/\s+/u', ' ', $term->label()) ?? '');
    if ($expected === '' || $actual !== $expected) {
      throw new InvalidArgumentException('Selected Blog category name/TID pair is stale or mismatched.');
    }
    return $term;
  }

  private function assertNoOtherTitleCollision(string $title, int $mappedId): void {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', self::BUNDLE)
      ->condition('langcode', self::SOURCE_LANGCODE)
      ->condition('title', $title)
      ->accessCheck(FALSE)
      ->execute();
    foreach ($ids as $id) {
      if ((int) $id !== $mappedId) {
        throw new RuntimeException('Another Article already uses the exact FR candidate title.');
      }
    }
  }

}

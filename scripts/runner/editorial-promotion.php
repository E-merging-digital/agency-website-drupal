<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finalizes an exact PREPROD-approved Article promotion after its image exists.
 */
final class AgencyEditorialPromotion {

  private const BUNDLE = 'article';
  private const SOURCE_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const AUTHOR_UID = 1;
  private const STATE_PREFIX = 'agency_editorial.issue.';
  private const IMAGE_FIELD = 'field_feature_image';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * Builds the helper from Drupal public services.
   */
  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('account_switcher'),
    );
  }

  /**
   * Publishes only the mapped Article that exactly matches content and image.
   *
   * @return array<string, mixed>
   *   Bounded promotion evidence.
   */
  public function finalize(
    array $payload,
    array $imageProfile,
    int $issueNumber,
    string $payloadSha,
  ): array {
    if (($payload['issue_number'] ?? NULL) !== $issueNumber
      || ($payload['bundle'] ?? NULL) !== self::BUNDLE
      || ($payload['published'] ?? NULL) !== TRUE) {
      throw new InvalidArgumentException('PROD promotion requires the exact published Article payload.');
    }
    if (($imageProfile['issue_number'] ?? NULL) !== $issueNumber
      || ($imageProfile['bundle'] ?? NULL) !== self::BUNDLE
      || ($imageProfile['field_name'] ?? NULL) !== self::IMAGE_FIELD
      || ($imageProfile['article_payload_sha256'] ?? NULL) !== $payloadSha) {
      throw new InvalidArgumentException('PROD promotion image profile does not match the exact Article payload.');
    }

    $node = $this->loadMappedNode($issueNumber, $payloadSha);
    $this->assertExactContent($node, $payload);
    $this->assertExactImage($node, $imageProfile);

    $translation = $node->getTranslation(self::TRANSLATION_LANGCODE);
    if ($node->isPublished() && $translation->isPublished()) {
      return [
        'status' => 'PASS',
        'verdict' => 'IDEMPOTENT',
        'issue_number' => $issueNumber,
        'payload_sha256' => $payloadSha,
        'node' => $this->nodeResult($node),
      ];
    }
    if ($node->isPublished() !== $translation->isPublished()) {
      throw new RuntimeException('FR/EN publication states diverge before promotion.');
    }

    $author = $this->loadAuthor();
    $this->accountSwitcher->switchTo($author);
    try {
      $node->setPublished(TRUE);
      $translation->setPublished(TRUE);
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(self::AUTHOR_UID);
      $node->setRevisionLogMessage(sprintf(
        'Agency PREPROD-first editorial promotion issue #%d payload %s',
        $issueNumber,
        $payloadSha,
      ));

      $violations = $node->validate();
      if ($violations->count() > 0) {
        $messages = [];
        foreach ($violations as $violation) {
          $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
        }
        throw new RuntimeException('Promoted Article validation failed: ' . implode(' | ', $messages));
      }

      $node->save();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $nodeId = (int) $node->id();
    $this->entityTypeManager->getStorage('node')->resetCache([$nodeId]);
    $reloaded = $this->loadMappedNode($issueNumber, $payloadSha);
    $this->assertExactContent($reloaded, $payload);
    $this->assertExactImage($reloaded, $imageProfile);
    if (!$reloaded->isPublished()
      || !$reloaded->getTranslation(self::TRANSLATION_LANGCODE)->isPublished()) {
      throw new RuntimeException('Exact Article did not converge to published FR/EN state.');
    }

    return [
      'status' => 'PASS',
      'verdict' => 'PROMOTED',
      'issue_number' => $issueNumber,
      'payload_sha256' => $payloadSha,
      'node' => $this->nodeResult($reloaded),
    ];
  }

  /**
   * Loads the Article mapped to the exact issue/hash pair.
   */
  private function loadMappedNode(int $issueNumber, string $payloadSha): NodeInterface {
    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if (!is_array($mapping)
      || !is_int($mapping['node_id'] ?? NULL)
      || !is_string($mapping['payload_sha256'] ?? NULL)
      || !hash_equals($payloadSha, $mapping['payload_sha256'])) {
      throw new RuntimeException('Exact editorial issue/hash mapping is missing or stale.');
    }
    $node = $this->entityTypeManager->getStorage('node')->load($mapping['node_id']);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      throw new RuntimeException('Exact editorial mapping does not point to an Article.');
    }
    if (!$node->hasTranslation(self::TRANSLATION_LANGCODE)) {
      throw new RuntimeException('Exact editorial mapping is missing the EN translation.');
    }
    return $node;
  }

  /**
   * Refuses candidate drift between PREPROD approval and PROD publication.
   */
  private function assertExactContent(NodeInterface $node, array $payload): void {
    $categoryTid = (int) ($payload['category']['tid'] ?? 0);
    $checks = [
      $node->label() === ($payload['fr']['title'] ?? NULL),
      (string) $node->get('field_short_description')->value === ($payload['fr']['short_description'] ?? NULL),
      (string) $node->get('body')->value === ($payload['fr']['body_html'] ?? NULL),
      (int) $node->get('field_blog_category')->target_id === $categoryTid,
    ];
    $translation = $node->getTranslation(self::TRANSLATION_LANGCODE);
    $checks[] = $translation->label() === ($payload['en']['title'] ?? NULL);
    $checks[] = (string) $translation->get('field_short_description')->value === ($payload['en']['short_description'] ?? NULL);
    $checks[] = (string) $translation->get('body')->value === ($payload['en']['body_html'] ?? NULL);
    $checks[] = (int) $translation->get('field_blog_category')->target_id === $categoryTid;
    if (in_array(FALSE, $checks, TRUE)) {
      throw new RuntimeException('Mapped Article content drifted from the exact approved candidate.');
    }
  }

  /**
   * Refuses publication until the exact governed image and FR/EN ALT exist.
   */
  private function assertExactImage(NodeInterface $node, array $profile): void {
    if (!$node->hasField(self::IMAGE_FIELD)) {
      throw new RuntimeException('Article has no governed feature-image field.');
    }
    $translation = $node->getTranslation(self::TRANSLATION_LANGCODE);
    $frItem = $node->get(self::IMAGE_FIELD)->first();
    $enItem = $translation->get(self::IMAGE_FIELD)->first();
    $frFid = $frItem?->get('target_id')->getValue();
    $enFid = $enItem?->get('target_id')->getValue();
    if ($frFid === NULL || $enFid === NULL || (int) $frFid !== (int) $enFid) {
      throw new RuntimeException('Article promotion requires one exact FR/EN feature image.');
    }

    $alt = $profile['alt'] ?? [];
    if ((string) ($frItem?->get('alt')->getValue() ?? '') !== ($alt['fr'] ?? NULL)
      || (string) ($enItem?->get('alt')->getValue() ?? '') !== ($alt['en'] ?? NULL)) {
      throw new RuntimeException('Article promotion requires the exact approved FR/EN ALT values.');
    }

    $file = $this->entityTypeManager->getStorage('file')->load((int) $frFid);
    if (!$file instanceof FileInterface) {
      throw new RuntimeException('Approved Article feature image File entity is missing.');
    }
    $actualHash = hash_file('sha256', $file->getFileUri());
    $expectedHash = $profile['asset']['sha256'] ?? NULL;
    if (!is_string($actualHash)
      || !is_string($expectedHash)
      || !hash_equals($expectedHash, $actualHash)) {
      throw new RuntimeException('Article feature image bytes drifted from the approved asset.');
    }
  }

  /**
   * Loads the fixed publication author.
   */
  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface || !$author->isActive()) {
      throw new RuntimeException('Required Drupal author uid=1 is missing or blocked.');
    }
    return $author;
  }

  /**
   * Returns metadata-only promotion evidence.
   *
   * @return array<string, mixed>
   *   Safe Article metadata.
   */
  private function nodeResult(NodeInterface $node): array {
    return [
      'id' => (int) $node->id(),
      'revision_id' => (int) $node->getRevisionId(),
      'published_fr' => $node->isPublished(),
      'published_en' => $node->getTranslation(self::TRANSLATION_LANGCODE)->isPublished(),
    ];
  }

}

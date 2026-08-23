<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_content\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\pathauto\PathautoGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finalizes derived Pathauto aliases for governed editor-owned Articles.
 */
final class EditorialPathautoFinalizer {

  private const BUNDLE = 'article';
  private const SOURCE_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const STATE_PREFIX = 'agency_editorial.issue.';
  private const ALIAS_PREFIX = '/blog/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly AliasManagerInterface $aliasManager,
    private readonly PathautoGeneratorInterface $pathautoGenerator,
  ) {
  }

  /**
   * Builds the finalizer from Drupal's existing public services.
   */
  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('path_alias.manager'),
      $container->get('pathauto.generator'),
    );
  }

  /**
   * Reports whether the mapped Article needs derived alias repair.
   *
   * @return array<string, mixed>
   *   Structured alias-finalization evidence.
   */
  public function inspect(int $issueNumber, string $payloadSha): array {
    $node = $this->loadMappedNode($issueNumber, $payloadSha);
    $aliases = $this->aliases($node);
    $source = '/node/' . $node->id();
    $needsRepair = [];

    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      $alias = $aliases[$langcode];
      if ($alias === $source || !str_starts_with($alias, self::ALIAS_PREFIX)) {
        $needsRepair[] = $langcode;
      }
    }

    return [
      'status' => 'PASS',
      'verdict' => $needsRepair === [] ? 'IDEMPOTENT' : 'REPAIR_REQUIRED',
      'mode' => 'dry-run',
      'issue_number' => $issueNumber,
      'payload_sha256' => $payloadSha,
      'aliases_to_repair' => $needsRepair,
      'node' => $this->nodeResult($node, $aliases),
    ];
  }

  /**
   * Regenerates only missing derived aliases without saving the Article.
   *
   * @return array<string, mixed>
   *   Structured alias-finalization evidence.
   */
  public function apply(int $issueNumber, string $payloadSha): array {
    $before = $this->inspect($issueNumber, $payloadSha);
    if ($before['verdict'] === 'IDEMPOTENT') {
      $before['mode'] = 'apply';
      return $before;
    }

    $node = $this->loadMappedNode($issueNumber, $payloadSha);
    $revisionId = (int) $node->getRevisionId();

    foreach ($before['aliases_to_repair'] as $langcode) {
      $translation = $node->getTranslation($langcode);
      $this->pathautoGenerator->updateEntityAlias(
        $translation,
        'bulkupdate',
        ['force' => TRUE],
      );
    }

    $this->entityTypeManager->getStorage('node')->resetCache([(int) $node->id()]);
    $reloaded = $this->loadMappedNode($issueNumber, $payloadSha);
    if ((int) $reloaded->getRevisionId() !== $revisionId) {
      throw new \RuntimeException('Pathauto alias repair unexpectedly created a node revision.');
    }

    $after = $this->inspect($issueNumber, $payloadSha);
    if ($after['verdict'] !== 'IDEMPOTENT') {
      throw new \RuntimeException('Pathauto alias repair did not converge for FR and EN.');
    }

    $after['verdict'] = 'REPAIRED';
    $after['mode'] = 'apply';
    $after['aliases_to_repair'] = [];
    return $after;
  }

  /**
   * Loads the Article bound to the exact issue and payload hash.
   */
  private function loadMappedNode(int $issueNumber, string $payloadSha): NodeInterface {
    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if (!is_array($mapping)) {
      throw new \RuntimeException('Editorial alias finalization requires an existing issue mapping.');
    }

    $nodeId = $mapping['node_id'] ?? NULL;
    $mappedHash = $mapping['payload_sha256'] ?? NULL;
    if (!is_int($nodeId) || !is_string($mappedHash)) {
      throw new \RuntimeException('Editorial alias finalization mapping is incomplete.');
    }
    if (!hash_equals($mappedHash, $payloadSha)) {
      throw new \RuntimeException('Editorial alias finalization payload hash mismatch.');
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      throw new \RuntimeException('Editorial alias finalization mapping points to an invalid Article.');
    }
    if (!$node->hasTranslation(self::TRANSLATION_LANGCODE)) {
      throw new \RuntimeException('Editorial alias finalization requires the EN translation.');
    }

    return $node;
  }

  /**
   * Returns current FR and EN aliases for the mapped Article.
   *
   * @return array<string, string>
   *   Aliases keyed by language code.
   */
  private function aliases(NodeInterface $node): array {
    $source = '/node/' . $node->id();
    $this->aliasManager->cacheClear($source);

    return [
      self::SOURCE_LANGCODE => $this->aliasManager->getAliasByPath(
        $source,
        self::SOURCE_LANGCODE,
      ),
      self::TRANSLATION_LANGCODE => $this->aliasManager->getAliasByPath(
        $source,
        self::TRANSLATION_LANGCODE,
      ),
    ];
  }

  /**
   * Builds immutable Article evidence for the route result.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The mapped Article.
   * @param array<string, string> $aliases
   *   Current aliases keyed by language code.
   *
   * @return array<string, mixed>
   *   Node evidence without editorial mutation.
   */
  private function nodeResult(NodeInterface $node, array $aliases): array {
    return [
      'id' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'revision_id' => (int) $node->getRevisionId(),
      'published' => $node->isPublished(),
      'title_fr' => $node->label(),
      'title_en' => $node->getTranslation(self::TRANSLATION_LANGCODE)->label(),
      'category_tid' => (int) $node->get('field_blog_category')->target_id,
      'aliases' => $aliases,
    ];
  }

}

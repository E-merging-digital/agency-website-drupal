<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\path_alias\AliasManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Finalizes Pathauto aliases for the governed editor-owned Article route.
 */
final class AgencyEditorialPathautoFinalizer {

  private const BUNDLE = 'article';
  private const SOURCE_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const STATE_PREFIX = 'agency_editorial.issue.';
  private const ALIAS_PREFIX = '/blog/';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly AliasManagerInterface $aliasManager,
    private readonly object $pathautoGenerator,
  ) {
    if (!method_exists($this->pathautoGenerator, 'updateEntityAlias')) {
      throw new RuntimeException('Pathauto generator does not expose updateEntityAlias().');
    }
  }

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
   * Regenerates only missing/invalid derived aliases without saving the node.
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
      throw new RuntimeException('Pathauto alias repair unexpectedly created a node revision.');
    }

    $after = $this->inspect($issueNumber, $payloadSha);
    if ($after['verdict'] !== 'IDEMPOTENT') {
      throw new RuntimeException('Pathauto alias repair did not converge for FR and EN.');
    }

    $after['verdict'] = 'REPAIRED';
    $after['mode'] = 'apply';
    $after['aliases_to_repair'] = [];
    return $after;
  }

  private function loadMappedNode(int $issueNumber, string $payloadSha): NodeInterface {
    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if (!is_array($mapping)) {
      throw new RuntimeException('Editorial alias finalization requires an existing issue mapping.');
    }

    $nodeId = $mapping['node_id'] ?? NULL;
    $mappedHash = $mapping['payload_sha256'] ?? NULL;
    if (!is_int($nodeId) || !is_string($mappedHash)) {
      throw new RuntimeException('Editorial alias finalization mapping is incomplete.');
    }
    if (!hash_equals($mappedHash, $payloadSha)) {
      throw new RuntimeException('Editorial alias finalization payload hash mismatch.');
    }

    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      throw new RuntimeException('Editorial alias finalization mapping points to an invalid Article.');
    }
    if (!$node->hasTranslation(self::TRANSLATION_LANGCODE)) {
      throw new RuntimeException('Editorial alias finalization requires the EN translation.');
    }

    return $node;
  }

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

if (!defined('AGENCY_EDITORIAL_PATHAUTO_LIBRARY_ONLY')) {
  $mode = getenv('AGENCY_EDITORIAL_MODE') ?: '';
  $issueRaw = getenv('AGENCY_EDITORIAL_ISSUE') ?: '';
  $payloadSha = getenv('AGENCY_EDITORIAL_PAYLOAD_SHA') ?: '';
  $payloadPath = getenv('AGENCY_EDITORIAL_PAYLOAD_PATH') ?: '';
  $resultPath = getenv('AGENCY_EDITORIAL_RESULT_PATH') ?: '';
  $libraryPath = getenv('AGENCY_EDITORIAL_LIBRARY_PATH') ?: '';

  $writeResult = static function (array $result) use ($resultPath): void {
    if ($resultPath === '') {
      throw new RuntimeException('AGENCY_EDITORIAL_RESULT_PATH is required.');
    }
    file_put_contents(
      $resultPath,
      json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
      ) . PHP_EOL,
    );
  };

  try {
    if (!preg_match('/^[1-9][0-9]*$/', $issueRaw)) {
      throw new InvalidArgumentException('AGENCY_EDITORIAL_ISSUE must be positive numeric.');
    }
    $issueNumber = (int) $issueRaw;
    if (!in_array($mode, ['inspect', 'dry-run', 'apply'], TRUE)) {
      throw new InvalidArgumentException('Unsupported AGENCY_EDITORIAL_MODE.');
    }
    if ($libraryPath === '' || !is_file($libraryPath)) {
      throw new RuntimeException('Trusted editorial publication library is missing.');
    }

    if (!defined('AGENCY_EDITORIAL_LIBRARY_ONLY')) {
      define('AGENCY_EDITORIAL_LIBRARY_ONLY', TRUE);
    }
    require_once $libraryPath;

    $publisher = AgencyEditorialPublication::fromContainer(\Drupal::getContainer());
    $finalizer = AgencyEditorialPathautoFinalizer::fromContainer(\Drupal::getContainer());

    if ($mode === 'inspect') {
      $result = $publisher->inspect($issueNumber);
      if (is_array($result['runtime']['mapping'] ?? NULL)) {
        $aliasResult = $finalizer->inspect(
          $issueNumber,
          (string) $result['runtime']['mapping']['payload_sha256'],
        );
        $result['runtime']['alias_finalization'] = [
          'verdict' => $aliasResult['verdict'],
          'aliases_to_repair' => $aliasResult['aliases_to_repair'],
          'node' => $aliasResult['node'],
        ];
      }
    }
    else {
      if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha)) {
        throw new InvalidArgumentException('AGENCY_EDITORIAL_PAYLOAD_SHA must be SHA-256.');
      }
      if ($payloadPath === '' || !is_file($payloadPath)) {
        throw new InvalidArgumentException('Editorial payload file is missing.');
      }
      $actualHash = hash_file('sha256', $payloadPath);
      if (!is_string($actualHash) || !hash_equals($payloadSha, $actualHash)) {
        throw new RuntimeException('Editorial payload hash mismatch on production.');
      }
      $payload = json_decode(
        (string) file_get_contents($payloadPath),
        TRUE,
        32,
        JSON_THROW_ON_ERROR,
      );
      if (!is_array($payload)) {
        throw new InvalidArgumentException('Editorial payload must decode to an object.');
      }

      if ($mode === 'dry-run') {
        $result = $publisher->dryRun($payload, $issueNumber, $payloadSha);
        if ($result['verdict'] === 'IDEMPOTENT') {
          $aliasResult = $finalizer->inspect($issueNumber, $payloadSha);
          if ($aliasResult['verdict'] === 'REPAIR_REQUIRED') {
            $result['verdict'] = 'REPAIR_REQUIRED';
          }
          $result['alias_finalization'] = $aliasResult['verdict'];
          $result['aliases_to_repair'] = $aliasResult['aliases_to_repair'];
          $result['node'] = $aliasResult['node'];
        }
      }
      else {
        $result = $publisher->apply($payload, $issueNumber, $payloadSha);
        $aliasResult = $finalizer->apply($issueNumber, $payloadSha);
        if ($result['verdict'] === 'IDEMPOTENT' && $aliasResult['verdict'] === 'REPAIRED') {
          $result['verdict'] = 'REPAIRED';
        }
        $result['alias_finalization'] = $aliasResult['verdict'];
        $result['aliases_to_repair'] = $aliasResult['aliases_to_repair'];
        $result['node'] = $aliasResult['node'];
      }
    }

    $writeResult($result);
  }
  catch (Throwable $exception) {
    $writeResult([
      'status' => 'FAIL',
      'verdict' => 'FAIL_CLOSED',
      'mode' => $mode,
      'issue_number' => ctype_digit($issueRaw) ? (int) $issueRaw : NULL,
      'message' => $exception->getMessage(),
    ]);
    exit(1);
  }
}

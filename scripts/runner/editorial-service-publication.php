<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bounded PROD publisher for the exact #1117 Service candidate.
 *
 * This helper is deliberately closed to node:service, issue #1117, FR+EN,
 * the existing Service text fields and exact public-route/stored-alias pairs.
 * It is not a generic entity writer.
 */
final class AgencyEditorialServicePublication {

  private const BUNDLE = 'service';
  private const SUPPORTED_ISSUE = 1117;
  private const SOURCE_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const TEXT_FORMAT = 'basic_html';
  private const AUTHOR_UID = 1;
  private const STATE_PREFIX = 'agency_editorial.service.issue.';
  private const CANDIDATE_ID = 'agency-service-1117';

  private const PUBLIC_ROUTES = [
    'fr' => '/fr/audit-site-web',
    'en' => '/en/website-audit',
  ];

  private const STORED_ALIASES = [
    'fr' => '/audit-site-web',
    'en' => '/website-audit',
  ];

  private const TOP_LEVEL_KEYS = [
    'bundle',
    'en',
    'fr',
    'issue_number',
    'public_routes',
    'published',
    'schema_version',
    'stored_aliases',
  ];

  private const LANGUAGE_KEYS = [
    'detailed_description_html',
    'short_description',
    'title',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly StateInterface $state,
    private readonly AccountSwitcherInterface $accountSwitcher,
  ) {}

  /**
   * Builds the bounded publisher from Drupal public services.
   */
  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('language_manager'),
      $container->get('state'),
      $container->get('account_switcher'),
    );
  }

  /**
   * Inspects the exact Service runtime and durable PROD mapping.
   */
  public function inspect(int $issueNumber): array {
    $this->assertIssueNumber($issueNumber);
    $this->assertRuntimePrerequisites();
    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if ($mapping !== NULL && !is_array($mapping)) {
      throw new RuntimeException('Service PROD mapping has an invalid shape.');
    }

    return [
      'status' => 'PASS',
      'verdict' => 'READY',
      'mode' => 'inspect',
      'target' => 'PROD',
      'issue_number' => $issueNumber,
      'candidate_id' => self::CANDIDATE_ID,
      'candidate_store' => 'GIT_MAIN_FILE',
      'runtime' => ['mapping' => $mapping],
      'prod_write' => 'NONE',
      'content_sync' => 'NONE',
      'db_copy' => 'NONE',
    ];
  }

  /**
   * Validates the exact #1117 Service candidate without writing.
   */
  public function dryRun(
    array $payload,
    int $issueNumber,
    string $payloadSha,
  ): array {
    $this->validatePayload($payload, $issueNumber, $payloadSha);
    $this->assertRuntimePrerequisites();

    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if ($mapping === NULL) {
      $this->assertNoCollisions($payload);
      return $this->result(
        'READY',
        'dry-run',
        $payload,
        $issueNumber,
        $payloadSha,
        NULL,
      );
    }
    if (!is_array($mapping)
      || !is_int($mapping['node_id'] ?? NULL)
      || !is_string($mapping['payload_sha256'] ?? NULL)) {
      throw new RuntimeException('Service PROD mapping is incomplete.');
    }
    if (!hash_equals($mapping['payload_sha256'], $payloadSha)) {
      throw new RuntimeException(
        'Issue already maps to a different Service payload hash.',
      );
    }

    $node = $this->loadMappedService($mapping['node_id']);
    $this->assertNodeContentMatchesPayload($node, $payload);
    $this->assertNodeAliasesMatchPayload($node, $payload);

    return $this->result(
      'IDEMPOTENT',
      'dry-run',
      $payload,
      $issueNumber,
      $payloadSha,
      $node,
    );
  }

  /**
   * Creates exactly one Service or returns the exact idempotent mapping.
   */
  public function apply(
    array $payload,
    int $issueNumber,
    string $payloadSha,
  ): array {
    $dryRun = $this->dryRun($payload, $issueNumber, $payloadSha);
    if (($dryRun['verdict'] ?? NULL) === 'IDEMPOTENT') {
      $dryRun['mode'] = 'apply';
      return $dryRun;
    }
    if (($dryRun['verdict'] ?? NULL) !== 'READY') {
      throw new RuntimeException('Service PROD candidate is not create-ready.');
    }

    $author = $this->loadAuthor();
    $node = NULL;
    $this->accountSwitcher->switchTo($author);
    try {
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => self::BUNDLE,
        'langcode' => self::SOURCE_LANGCODE,
        'uid' => self::AUTHOR_UID,
        'status' => TRUE,
        'title' => $payload['fr']['title'],
        'field_short_description' => [[
          'value' => $payload['fr']['short_description'],
          'format' => self::TEXT_FORMAT,
        ]],
        'field_detailed_description' => [[
          'value' => $payload['fr']['detailed_description_html'],
          'format' => self::TEXT_FORMAT,
        ]],
        'path' => [
          'alias' => $payload['stored_aliases']['fr'],
          'pathauto' => 0,
        ],
      ]);
      if (!$node instanceof NodeInterface) {
        throw new RuntimeException(
          'Drupal node storage did not create the exact Service candidate.',
        );
      }

      $node->addTranslation(self::TRANSLATION_LANGCODE, [
        'title' => $payload['en']['title'],
        'status' => TRUE,
        'field_short_description' => [[
          'value' => $payload['en']['short_description'],
          'format' => self::TEXT_FORMAT,
        ]],
        'field_detailed_description' => [[
          'value' => $payload['en']['detailed_description_html'],
          'format' => self::TEXT_FORMAT,
        ]],
        'path' => [
          'alias' => $payload['stored_aliases']['en'],
          'pathauto' => 0,
        ],
      ]);
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(self::AUTHOR_UID);
      $node->setRevisionLogMessage(sprintf(
        'Agency governed Service PROD issue #%d payload %s',
        $issueNumber,
        $payloadSha,
      ));
      $this->assertEntityValid($node);
      $node->save();
    }
    finally {
      $this->accountSwitcher->switchBack();
    }

    $nodeId = (int) $node->id();
    $this->state->set(self::STATE_PREFIX . $issueNumber, [
      'node_id' => $nodeId,
      'payload_sha256' => $payloadSha,
    ]);
    $this->entityTypeManager->getStorage('node')->resetCache([$nodeId]);
    $reloaded = $this->loadMappedService($nodeId);
    $this->assertNodeContentMatchesPayload($reloaded, $payload);
    $this->assertNodeAliasesMatchPayload($reloaded, $payload);

    return $this->result(
      'APPLIED',
      'apply',
      $payload,
      $issueNumber,
      $payloadSha,
      $reloaded,
    );
  }

  /**
   * Enforces the closed Service V2 payload and exact #1117 route identity.
   */
  public function validatePayload(
    array $payload,
    int $issueNumber,
    string $payloadSha,
  ): void {
    $this->assertIssueNumber($issueNumber);
    if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha)) {
      throw new InvalidArgumentException(
        'Service payload hash must be lowercase SHA-256.',
      );
    }
    if ($this->sortedKeys($payload) !== self::TOP_LEVEL_KEYS) {
      throw new InvalidArgumentException(
        'Service payload keys must be exactly the closed V2 schema.',
      );
    }
    if (($payload['schema_version'] ?? NULL) !== 2
      || ($payload['issue_number'] ?? NULL) !== self::SUPPORTED_ISSUE
      || ($payload['bundle'] ?? NULL) !== self::BUNDLE
      || ($payload['published'] ?? NULL) !== TRUE) {
      throw new InvalidArgumentException(
        'Service PROD requires the exact published #1117 Service payload.',
      );
    }
    if (($payload['public_routes'] ?? NULL) !== self::PUBLIC_ROUTES) {
      throw new InvalidArgumentException(
        'Service public routes do not match the exact #1117 contract.',
      );
    }
    if (($payload['stored_aliases'] ?? NULL) !== self::STORED_ALIASES) {
      throw new InvalidArgumentException(
        'Service stored aliases do not match the exact #1117 contract.',
      );
    }

    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      $language = $payload[$langcode] ?? NULL;
      if (!is_array($language)
        || $this->sortedKeys($language) !== self::LANGUAGE_KEYS) {
        throw new InvalidArgumentException(sprintf(
          'Service %s fields must be exactly title, short and detailed description.',
          $langcode,
        ));
      }
      foreach (self::LANGUAGE_KEYS as $key) {
        if (!is_string($language[$key] ?? NULL)
          || trim($language[$key]) === '') {
          throw new InvalidArgumentException(sprintf(
            'Service %s field %s cannot be empty.',
            $langcode,
            $key,
          ));
        }
      }
    }
  }

  /**
   * Verifies the fixed Service runtime prerequisites.
   */
  private function assertRuntimePrerequisites(): void {
    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      if (!$this->languageManager->getLanguage($langcode)) {
        throw new RuntimeException(sprintf(
          'Required Drupal language %s is unavailable.',
          $langcode,
        ));
      }
    }
    if ($this->entityTypeManager
      ->getStorage('node_type')
      ->load(self::BUNDLE) === NULL) {
      throw new RuntimeException('Required Service content type is unavailable.');
    }
    $fields = $this->entityFieldManager
      ->getFieldDefinitions('node', self::BUNDLE);
    foreach (
      ['field_short_description', 'field_detailed_description', 'path']
      as $fieldName
    ) {
      if (!isset($fields[$fieldName])) {
        throw new RuntimeException(sprintf(
          'Required Service field %s is unavailable.',
          $fieldName,
        ));
      }
    }
    if ($this->entityTypeManager
      ->getStorage('filter_format')
      ->load(self::TEXT_FORMAT) === NULL) {
      throw new RuntimeException('Required basic_html text format is unavailable.');
    }
    $this->loadAuthor();
  }

  /**
   * Refuses an untracked duplicate Service title or alias.
   */
  private function assertNoCollisions(array $payload): void {
    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      $titleIds = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', self::BUNDLE)
        ->condition('langcode', $langcode)
        ->condition('title', $payload[$langcode]['title'])
        ->accessCheck(FALSE)
        ->execute();
      if ($titleIds !== []) {
        throw new RuntimeException(sprintf(
          'Another Service already uses the exact %s candidate title.',
          strtoupper($langcode),
        ));
      }
      $aliases = $this->entityTypeManager
        ->getStorage('path_alias')
        ->loadByProperties([
          'alias' => $payload['stored_aliases'][$langcode],
          'langcode' => $langcode,
        ]);
      if ($aliases !== []) {
        throw new RuntimeException(sprintf(
          'Another route already uses the exact %s Service stored alias.',
          strtoupper($langcode),
        ));
      }
    }
  }

  /**
   * Refuses drift from the exact content approved for #1117.
   */
  private function assertNodeContentMatchesPayload(
    NodeInterface $node,
    array $payload,
  ): void {
    if ($node->bundle() !== self::BUNDLE
      || $node->language()->getId() !== self::SOURCE_LANGCODE
      || !$node->hasTranslation(self::TRANSLATION_LANGCODE)) {
      throw new RuntimeException(
        'Service mapping points to an incompatible node.',
      );
    }
    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      $translation = $langcode === self::SOURCE_LANGCODE
        ? $node
        : $node->getTranslation($langcode);
      if ($translation->label() !== $payload[$langcode]['title']
        || (string) $translation->get('field_short_description')->value
          !== $payload[$langcode]['short_description']
        || (string) $translation->get('field_detailed_description')->value
          !== $payload[$langcode]['detailed_description_html']
        || !$translation->isPublished()) {
        throw new RuntimeException(sprintf(
          'Service %s content drifted from the exact approved candidate.',
          strtoupper($langcode),
        ));
      }
    }
  }

  /**
   * Refuses route or stored-alias drift.
   */
  private function assertNodeAliasesMatchPayload(
    NodeInterface $node,
    array $payload,
  ): void {
    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      $aliases = $this->aliasesForNodeLanguage($node, $langcode);
      if (count($aliases) !== 1
        || $aliases[0] !== $payload['stored_aliases'][$langcode]) {
        throw new RuntimeException(sprintf(
          'Service %s stored alias drifted from the exact candidate.',
          strtoupper($langcode),
        ));
      }
    }
  }

  /**
   * Returns stored aliases for one mapped node language.
   *
   * @return string[]
   *   Stored aliases.
   */
  private function aliasesForNodeLanguage(
    NodeInterface $node,
    string $langcode,
  ): array {
    $entities = $this->entityTypeManager
      ->getStorage('path_alias')
      ->loadByProperties([
        'path' => '/node/' . $node->id(),
        'langcode' => $langcode,
      ]);
    $aliases = [];
    foreach ($entities as $entity) {
      $aliases[] = (string) $entity->getAlias();
    }
    sort($aliases);
    return $aliases;
  }

  /**
   * Validates the entity before the only allowed Service create.
   */
  private function assertEntityValid(NodeInterface $node): void {
    $violations = $node->validate();
    if ($violations->count() === 0) {
      return;
    }
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = $violation->getPropertyPath()
        . ': '
        . $violation->getMessage();
    }
    throw new RuntimeException(
      'PROD Service validation failed: ' . implode(' | ', $messages),
    );
  }

  /**
   * Loads only the mapped Service node.
   */
  private function loadMappedService(int $nodeId): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      throw new RuntimeException(
        'Service mapping does not point to a Service node.',
      );
    }
    return $node;
  }

  /**
   * Loads the fixed Drupal publication author.
   */
  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface || !$author->isActive()) {
      throw new RuntimeException('Required Drupal author uid=1 is unavailable.');
    }
    return $author;
  }

  /**
   * Builds metadata-only Service publication evidence.
   */
  private function result(
    string $verdict,
    string $mode,
    array $payload,
    int $issueNumber,
    string $payloadSha,
    ?NodeInterface $node,
  ): array {
    return [
      'status' => 'PASS',
      'verdict' => $verdict,
      'mode' => $mode,
      'target' => 'PROD',
      'candidate_kind' => 'service',
      'issue_number' => $issueNumber,
      'candidate_id' => self::CANDIDATE_ID,
      'candidate_store' => 'GIT_MAIN_FILE',
      'payload_sha256' => $payloadSha,
      'node' => $node instanceof NodeInterface ? [
        'id' => (int) $node->id(),
        'revision_id' => (int) $node->getRevisionId(),
        'public_routes' => $payload['public_routes'],
        'stored_aliases' => $payload['stored_aliases'],
      ] : [
        'id' => NULL,
        'revision_id' => NULL,
        'public_routes' => $payload['public_routes'],
        'stored_aliases' => $payload['stored_aliases'],
      ],
      'prod_write' => $mode === 'apply' ? 'SERVICE_ENTITY_API' : 'NONE',
      'content_sync' => 'NONE',
      'db_copy' => 'NONE',
      'service_image_profile_required' => 'NO',
    ];
  }

  /**
   * Enforces the single explicitly supported Service consumer.
   */
  private function assertIssueNumber(int $issueNumber): void {
    if ($issueNumber !== self::SUPPORTED_ISSUE) {
      throw new InvalidArgumentException(
        'Service PROD V1 supports only issue #1117.',
      );
    }
  }

  /**
   * Returns sorted array keys for closed-schema validation.
   *
   * @return string[]
   *   Sorted keys.
   */
  private function sortedKeys(array $value): array {
    $keys = array_keys($value);
    sort($keys);
    return $keys;
  }

}

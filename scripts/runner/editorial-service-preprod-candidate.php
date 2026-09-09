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
 * PREPROD-only service candidate materializer.
 *
 * This helper is deliberately closed to node:service, FR+EN, the two existing
 * service text fields and explicit localized aliases. It is not a generic
 * entity writer and has no production execution surface.
 */
final class AgencyEditorialServicePreprodCandidate {

  private const BUNDLE = 'service';
  private const SOURCE_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const TEXT_FORMAT = 'basic_html';
  private const AUTHOR_UID = 1;
  private const STATE_PREFIX = 'agency_editorial.service.issue.';

  private const TOP_LEVEL_KEYS = [
    'aliases',
    'bundle',
    'en',
    'fr',
    'issue_number',
    'published',
    'schema_version',
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
   * Inspects only the bounded service runtime and existing candidate mapping.
   */
  public function inspect(int $issueNumber): array {
    $this->assertIssueNumber($issueNumber);
    $this->assertRuntimePrerequisites();
    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if ($mapping !== NULL && !is_array($mapping)) {
      throw new RuntimeException('Service PREPROD candidate mapping has an invalid shape.');
    }

    return [
      'status' => 'PASS',
      'verdict' => 'READY',
      'mode' => 'inspect',
      'target' => 'PREPROD',
      'issue_number' => $issueNumber,
      'candidate_id' => $this->candidateId($issueNumber),
      'candidate_store' => 'GIT_MAIN_FILE',
      'runtime' => ['mapping' => $mapping],
      'prod_write' => 'NONE',
    ];
  }

  /**
   * Validates one exact service candidate without writing.
   */
  public function dryRun(array $payload, int $issueNumber, string $payloadSha): array {
    $this->validatePayload($payload, $issueNumber, $payloadSha);
    $this->assertRuntimePrerequisites();

    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if ($mapping === NULL) {
      $this->assertNoCollisions($payload);
      return $this->result('CREATE_READY', 'dry-run', $payload, $issueNumber, $payloadSha, NULL);
    }
    if (!is_array($mapping)
      || !is_int($mapping['node_id'] ?? NULL)
      || !is_string($mapping['payload_sha256'] ?? NULL)) {
      throw new RuntimeException('Service PREPROD candidate mapping is incomplete.');
    }
    if (!hash_equals($mapping['payload_sha256'], $payloadSha)) {
      throw new RuntimeException('Existing service PREPROD candidate has a different payload hash.');
    }

    $node = $this->loadMappedService($mapping['node_id']);
    $this->assertNodeMatchesPayload($node, $payload);
    return $this->result('IDEMPOTENT', 'dry-run', $payload, $issueNumber, $payloadSha, $node);
  }

  /**
   * Creates the exact service candidate or converges on its exact replay.
   */
  public function apply(array $payload, int $issueNumber, string $payloadSha): array {
    $dryRun = $this->dryRun($payload, $issueNumber, $payloadSha);
    if (($dryRun['verdict'] ?? NULL) === 'IDEMPOTENT') {
      $dryRun['mode'] = 'apply';
      return $dryRun;
    }
    if (($dryRun['verdict'] ?? NULL) !== 'CREATE_READY') {
      throw new RuntimeException('Service PREPROD candidate is not create-ready.');
    }

    $author = $this->loadAuthor();
    $node = NULL;
    $this->accountSwitcher->switchTo($author);
    try {
      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => self::BUNDLE,
        'langcode' => self::SOURCE_LANGCODE,
        'uid' => self::AUTHOR_UID,
        'status' => $payload['published'],
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
          'alias' => $payload['aliases']['fr'],
          'pathauto' => 0,
        ],
      ]);
      if (!$node instanceof NodeInterface) {
        throw new RuntimeException('Drupal node storage did not create a Service candidate.');
      }

      $node->addTranslation(self::TRANSLATION_LANGCODE, [
        'title' => $payload['en']['title'],
        'status' => $payload['published'],
        'field_short_description' => [[
          'value' => $payload['en']['short_description'],
          'format' => self::TEXT_FORMAT,
        ]],
        'field_detailed_description' => [[
          'value' => $payload['en']['detailed_description_html'],
          'format' => self::TEXT_FORMAT,
        ]],
        'path' => [
          'alias' => $payload['aliases']['en'],
          'pathauto' => 0,
        ],
      ]);
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(self::AUTHOR_UID);
      $node->setRevisionLogMessage(sprintf(
        'Agency PREPROD service candidate issue #%d payload %s',
        $issueNumber,
        $payloadSha,
      ));
      $this->assertEntityValid($node);
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
    $reloaded = $this->loadMappedService((int) $node->id());
    $this->assertNodeMatchesPayload($reloaded, $payload);
    return $this->result('APPLIED', 'apply', $payload, $issueNumber, $payloadSha, $reloaded);
  }

  /**
   * Enforces the closed service-only payload contract.
   */
  public function validatePayload(array $payload, int $issueNumber, string $payloadSha): void {
    $this->assertIssueNumber($issueNumber);
    if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha)) {
      throw new InvalidArgumentException('Service candidate payload hash must be lowercase SHA-256.');
    }
    if ($this->sortedKeys($payload) !== self::TOP_LEVEL_KEYS) {
      throw new InvalidArgumentException('Service candidate payload keys must be exactly the closed V1 schema.');
    }
    if (($payload['schema_version'] ?? NULL) !== 1) {
      throw new InvalidArgumentException('Service candidate schema_version must be 1.');
    }
    if (($payload['issue_number'] ?? NULL) !== $issueNumber) {
      throw new InvalidArgumentException('Service candidate issue_number does not match the triggering issue.');
    }
    if (($payload['bundle'] ?? NULL) !== self::BUNDLE) {
      throw new InvalidArgumentException('Only bundle=service is allowed by the service PREPROD candidate.');
    }
    if (($payload['published'] ?? NULL) !== TRUE) {
      throw new InvalidArgumentException('Service PREPROD review candidate must render published in protected PREPROD.');
    }

    $aliases = $payload['aliases'] ?? NULL;
    if (!is_array($aliases) || $this->sortedKeys($aliases) !== ['en', 'fr']) {
      throw new InvalidArgumentException('Service candidate aliases must contain exactly FR and EN.');
    }
    foreach (['fr', 'en'] as $langcode) {
      $alias = $aliases[$langcode] ?? NULL;
      if (!is_string($alias)
        || !str_starts_with($alias, '/' . $langcode . '/')
        || str_contains($alias, '?')
        || str_contains($alias, '#')
        || str_contains($alias, '//')) {
        throw new InvalidArgumentException(sprintf('Service %s alias must be one explicit localized path.', $langcode));
      }

      $language = $payload[$langcode] ?? NULL;
      if (!is_array($language) || $this->sortedKeys($language) !== self::LANGUAGE_KEYS) {
        throw new InvalidArgumentException(sprintf('Service %s fields must be exactly title, short and detailed description.', $langcode));
      }
      foreach (self::LANGUAGE_KEYS as $key) {
        if (!is_string($language[$key] ?? NULL) || trim($language[$key]) === '') {
          throw new InvalidArgumentException(sprintf('Service %s field %s cannot be empty.', $langcode, $key));
        }
      }
    }
    if ($aliases['fr'] === $aliases['en']) {
      throw new InvalidArgumentException('Service FR and EN aliases must differ.');
    }
  }

  private function assertRuntimePrerequisites(): void {
    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      if (!$this->languageManager->getLanguage($langcode)) {
        throw new RuntimeException(sprintf('Required Drupal language %s is unavailable.', $langcode));
      }
    }
    if ($this->entityTypeManager->getStorage('node_type')->load(self::BUNDLE) === NULL) {
      throw new RuntimeException('Required Service content type is unavailable.');
    }
    $fields = $this->entityFieldManager->getFieldDefinitions('node', self::BUNDLE);
    foreach (['field_short_description', 'field_detailed_description', 'path'] as $fieldName) {
      if (!isset($fields[$fieldName])) {
        throw new RuntimeException(sprintf('Required Service field %s is unavailable.', $fieldName));
      }
    }
    if ($this->entityTypeManager->getStorage('filter_format')->load(self::TEXT_FORMAT) === NULL) {
      throw new RuntimeException('Required basic_html text format is unavailable.');
    }
    $this->loadAuthor();
  }

  private function assertNoCollisions(array $payload): void {
    foreach (['fr', 'en'] as $langcode) {
      $titleIds = $this->entityTypeManager->getStorage('node')->getQuery()
        ->condition('type', self::BUNDLE)
        ->condition('langcode', $langcode)
        ->condition('title', $payload[$langcode]['title'])
        ->accessCheck(FALSE)
        ->execute();
      if ($titleIds !== []) {
        throw new RuntimeException(sprintf('Another Service already uses the exact %s candidate title.', strtoupper($langcode)));
      }
      $aliases = $this->entityTypeManager->getStorage('path_alias')->loadByProperties([
        'alias' => $payload['aliases'][$langcode],
        'langcode' => $langcode,
      ]);
      if ($aliases !== []) {
        throw new RuntimeException(sprintf('Another route already uses the exact %s service alias.', strtoupper($langcode)));
      }
    }
  }

  private function assertNodeMatchesPayload(NodeInterface $node, array $payload): void {
    if ($node->bundle() !== self::BUNDLE || $node->language()->getId() !== self::SOURCE_LANGCODE) {
      throw new RuntimeException('Service candidate mapping points to an incompatible node.');
    }
    if (!$node->hasTranslation(self::TRANSLATION_LANGCODE)) {
      throw new RuntimeException('Service candidate EN translation is missing.');
    }
    foreach (['fr', 'en'] as $langcode) {
      $translation = $langcode === 'fr' ? $node : $node->getTranslation($langcode);
      if ($translation->label() !== $payload[$langcode]['title']
        || (string) $translation->get('field_short_description')->value !== $payload[$langcode]['short_description']
        || (string) $translation->get('field_detailed_description')->value !== $payload[$langcode]['detailed_description_html']
        || (bool) $translation->isPublished() !== $payload['published']) {
        throw new RuntimeException(sprintf('Service %s translation does not match the exact candidate payload.', strtoupper($langcode)));
      }
      $path = '/node/' . $node->id();
      $aliases = $this->entityTypeManager->getStorage('path_alias')->loadByProperties([
        'path' => $path,
        'alias' => $payload['aliases'][$langcode],
        'langcode' => $langcode,
      ]);
      if (count($aliases) !== 1) {
        throw new RuntimeException(sprintf('Service %s localized alias does not match the exact candidate.', strtoupper($langcode)));
      }
    }
  }

  private function assertEntityValid(NodeInterface $node): void {
    $violations = $node->validate();
    if ($violations->count() === 0) {
      return;
    }
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    throw new RuntimeException('PREPROD Service validation failed: ' . implode(' | ', $messages));
  }

  private function loadMappedService(int $nodeId): NodeInterface {
    $node = $this->entityTypeManager->getStorage('node')->load($nodeId);
    if (!$node instanceof NodeInterface || $node->bundle() !== self::BUNDLE) {
      throw new RuntimeException('Service candidate mapping does not point to a Service node.');
    }
    return $node;
  }

  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface || !$author->isActive()) {
      throw new RuntimeException('Required Drupal author uid=1 is unavailable.');
    }
    return $author;
  }

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
      'target' => 'PREPROD',
      'issue_number' => $issueNumber,
      'candidate_id' => $this->candidateId($issueNumber),
      'candidate_store' => 'GIT_MAIN_FILE',
      'payload_sha256' => $payloadSha,
      'node' => $node instanceof NodeInterface ? [
        'id' => (int) $node->id(),
        'revision_id' => (int) $node->getRevisionId(),
        'aliases' => $payload['aliases'],
      ] : [
        'id' => NULL,
        'revision_id' => NULL,
        'aliases' => $payload['aliases'],
      ],
      'prod_write' => 'NONE',
    ];
  }

  private function candidateId(int $issueNumber): string {
    return 'agency-service-' . $issueNumber;
  }

  private function assertIssueNumber(int $issueNumber): void {
    if ($issueNumber <= 0) {
      throw new InvalidArgumentException('Service candidate issue number must be positive.');
    }
  }

  /**
   * @return string[]
   */
  private function sortedKeys(array $value): array {
    $keys = array_keys($value);
    sort($keys);
    return $keys;
  }

}

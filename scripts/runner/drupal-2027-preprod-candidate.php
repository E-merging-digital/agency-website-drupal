<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Closed PREPROD profile for the approved Drupal 2027 landing candidate.
 *
 * This is intentionally not a generic page/entity writer. Bundle, language,
 * alias, component sequence and link destinations are fixed by #1012.
 */
final class AgencyDrupal2027PreprodCandidate {

  private const PROFILE = 'drupal-2027-landing';
  private const CANDIDATE_ID = 'agency-drupal-2027-landing-1012';
  private const ISSUE_NUMBER = 1012;
  private const SOURCE_ISSUE = 1010;
  private const SOURCE_COMMENT_ID = 5545732650;
  private const SOURCE_UPDATED_AT = '2026-09-04T19:52:38Z';
  private const BUNDLE = 'page';
  private const LANGCODE = 'fr';
  private const PUBLIC_ALIAS = '/fr/drupal-2027';
  private const DRUPAL_ALIAS = '/drupal-2027';
  private const TEXT_FORMAT = 'basic_html';
  private const AUTHOR_UID = 1;
  private const STATE_KEY = 'agency_editorial.drupal_2027_landing';
  private const PRIMARY_URI = 'internal:/drupal-2027#block-emerging-digital-drupal-lifecycle-diagnostic';
  private const SECONDARY_URI = 'internal:/drupal-2027#points-a-verifier';

  /** @var string[] */
  private const TOP_LEVEL_KEYS = [
    'alias',
    'bundle',
    'candidate_id',
    'hero',
    'issue_number',
    'language',
    'profile',
    'published',
    'schema_version',
    'sections',
    'short_description',
    'source_comment_id',
    'source_issue',
    'source_updated_at',
    'target',
    'title',
  ];

  /** @var string[] */
  private const SECTION_KEYS = [
    'audit',
    'checks',
    'composer_callout',
    'diagnostic_context',
    'faq',
    'lifecycle',
    'method',
    'reassurance',
    'situations',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly StateInterface $state,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly AliasManagerInterface $aliasManager,
  ) {
  }

  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('state'),
      $container->get('account_switcher'),
      $container->get('path_alias.manager'),
    );
  }

  /**
   * Read-only profile inspection bound to the exact candidate payload hash.
   */
  public function inspect(array $payload, string $payloadSha): array {
    return $this->plan($payload, $payloadSha, 'inspect');
  }

  /**
   * Read-only creation/idempotence decision.
   */
  public function dryRun(array $payload, string $payloadSha): array {
    return $this->plan($payload, $payloadSha, 'dry-run');
  }

  /**
   * Creates the exact page once, or returns an exact idempotent replay.
   */
  public function apply(array $payload, string $payloadSha): array {
    $plan = $this->plan($payload, $payloadSha, 'apply');
    if ($plan['verdict'] === 'IDEMPOTENT') {
      return $plan;
    }
    if ($plan['verdict'] !== 'CREATE_READY') {
      throw new RuntimeException('Drupal 2027 candidate is not create-ready.');
    }

    $author = $this->loadAuthor();
    $paragraphs = [];
    $node = NULL;
    $this->accountSwitcher->switchTo($author);

    try {
      $paragraphs = $this->createParagraphs($payload);
      $references = array_map(
        static fn (ParagraphInterface $paragraph): array => [
          'target_id' => (int) $paragraph->id(),
          'target_revision_id' => (int) $paragraph->getRevisionId(),
        ],
        $paragraphs,
      );

      $node = $this->entityTypeManager->getStorage('node')->create([
        'type' => self::BUNDLE,
        'langcode' => self::LANGCODE,
        'title' => $payload['title'],
        'uid' => self::AUTHOR_UID,
        'status' => TRUE,
        'field_short_description' => [[
          'value' => $payload['short_description'],
          'format' => self::TEXT_FORMAT,
        ]],
        'field_home_components' => $references,
        'path' => [
          'alias' => self::DRUPAL_ALIAS,
          'pathauto' => 0,
        ],
      ]);
      if (!$node instanceof NodeInterface) {
        throw new RuntimeException('Drupal node storage did not create a Page entity.');
      }

      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(self::AUTHOR_UID);
      $node->setRevisionLogMessage($this->revisionMessage($payloadSha));
      $this->assertEntityValid($node, 'Drupal 2027 Page');
      $node->save();

      $this->state->set(self::STATE_KEY, [
        'candidate_id' => self::CANDIDATE_ID,
        'node_id' => (int) $node->id(),
        'payload_sha256' => $payloadSha,
      ]);
      $this->entityTypeManager->getStorage('node')->resetCache([(int) $node->id()]);
      $this->aliasManager->cacheClear('/node/' . $node->id());

      $verified = $this->loadCompatibleExisting($payload, $payloadSha);
      if (!$verified instanceof NodeInterface) {
        throw new RuntimeException('Drupal 2027 Page did not converge after apply.');
      }

      return $this->result('APPLIED', 'apply', $payloadSha, $verified);
    }
    catch (Throwable $exception) {
      $this->state->delete(self::STATE_KEY);
      if ($node instanceof NodeInterface && !$node->isNew()) {
        $node->delete();
      }
      foreach ($paragraphs as $paragraph) {
        if (!$paragraph instanceof ParagraphInterface || $paragraph->isNew()) {
          continue;
        }
        $loaded = $this->entityTypeManager->getStorage('paragraph')->load($paragraph->id());
        if ($loaded instanceof ParagraphInterface) {
          $loaded->delete();
        }
      }
      throw $exception;
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Validates the closed payload and decides CREATE_READY or IDEMPOTENT.
   */
  private function plan(array $payload, string $payloadSha, string $mode): array {
    $this->validatePayload($payload);
    if (!preg_match('/^[0-9a-f]{64}$/', $payloadSha)) {
      throw new InvalidArgumentException('Drupal 2027 candidate hash must be SHA-256.');
    }
    $this->assertRuntimePrerequisites();

    $node = $this->loadCompatibleExisting($payload, $payloadSha);
    if ($node instanceof NodeInterface) {
      return $this->result('IDEMPOTENT', $mode, $payloadSha, $node);
    }

    return $this->result('CREATE_READY', $mode, $payloadSha, NULL);
  }

  /**
   * Closed schema: no arbitrary target, bundle, alias, fields or components.
   */
  private function validatePayload(array $payload): void {
    $keys = array_keys($payload);
    sort($keys);
    $expected = self::TOP_LEVEL_KEYS;
    sort($expected);
    if ($keys !== $expected) {
      throw new InvalidArgumentException('Drupal 2027 payload keys must match the closed profile exactly.');
    }

    $fixed = [
      'schema_version' => 1,
      'profile' => self::PROFILE,
      'candidate_id' => self::CANDIDATE_ID,
      'issue_number' => self::ISSUE_NUMBER,
      'source_issue' => self::SOURCE_ISSUE,
      'source_comment_id' => self::SOURCE_COMMENT_ID,
      'source_updated_at' => self::SOURCE_UPDATED_AT,
      'target' => 'PREPROD',
      'bundle' => self::BUNDLE,
      'language' => self::LANGCODE,
      'alias' => self::PUBLIC_ALIAS,
      'published' => TRUE,
    ];
    foreach ($fixed as $key => $value) {
      if (($payload[$key] ?? NULL) !== $value) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 payload field %s is fixed.', $key));
      }
    }

    foreach (['title', 'short_description'] as $key) {
      if (!is_string($payload[$key]) || trim($payload[$key]) === '') {
        throw new InvalidArgumentException(sprintf('Drupal 2027 payload %s must be non-empty.', $key));
      }
    }

    $hero = $payload['hero'];
    if (!is_array($hero) || $this->sortedKeys($hero) !== [
      'primary_cta',
      'secondary_cta',
      'submessage',
    ]) {
      throw new InvalidArgumentException('Drupal 2027 hero schema is fixed.');
    }
    foreach ($hero as $value) {
      if (!is_string($value) || trim($value) === '') {
        throw new InvalidArgumentException('Drupal 2027 hero values must be non-empty strings.');
      }
    }

    $sections = $payload['sections'];
    if (!is_array($sections) || $this->sortedKeys($sections) !== self::SECTION_KEYS) {
      throw new InvalidArgumentException('Drupal 2027 section schema is fixed.');
    }
    foreach (['lifecycle', 'situations', 'checks', 'composer_callout', 'method', 'audit', 'faq'] as $key) {
      $section = $sections[$key] ?? NULL;
      if (!is_array($section) || $this->sortedKeys($section) !== ['body_html', 'heading']) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 section %s schema is fixed.', $key));
      }
      if (!$this->nonEmptyString($section['heading']) || !$this->nonEmptyString($section['body_html'])) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 section %s cannot be empty.', $key));
      }
    }

    $diagnostic = $sections['diagnostic_context'] ?? NULL;
    if (!is_array($diagnostic) || $this->sortedKeys($diagnostic) !== ['body_html'] || !$this->nonEmptyString($diagnostic['body_html'])) {
      throw new InvalidArgumentException('Drupal 2027 diagnostic context schema is fixed.');
    }

    $reassurance = $sections['reassurance'] ?? NULL;
    if (!is_array($reassurance) || $this->sortedKeys($reassurance) !== ['heading', 'items']) {
      throw new InvalidArgumentException('Drupal 2027 reassurance schema is fixed.');
    }
    if (!$this->nonEmptyString($reassurance['heading']) || !is_array($reassurance['items']) || count($reassurance['items']) !== 5) {
      throw new InvalidArgumentException('Drupal 2027 reassurance requires exactly five approved items.');
    }
    foreach ($reassurance['items'] as $item) {
      if (!$this->nonEmptyString($item)) {
        throw new InvalidArgumentException('Drupal 2027 reassurance items must be non-empty strings.');
      }
    }
  }

  /**
   * Proves the existing page/Paragraph model is present before any write.
   */
  private function assertRuntimePrerequisites(): void {
    $pageType = $this->entityTypeManager->getStorage('node_type')->load(self::BUNDLE);
    if ($pageType === NULL) {
      throw new RuntimeException('Required Page content type is unavailable in PREPROD.');
    }

    $pageFields = $this->entityFieldManager->getFieldDefinitions('node', self::BUNDLE);
    foreach (['field_short_description', 'field_home_components', 'path'] as $fieldName) {
      if (!isset($pageFields[$fieldName])) {
        throw new RuntimeException(sprintf('Required Page field %s is unavailable.', $fieldName));
      }
    }

    foreach (['hero', 'text_block', 'trust_list'] as $bundle) {
      if ($this->entityTypeManager->getStorage('paragraphs_type')->load($bundle) === NULL) {
        throw new RuntimeException(sprintf('Required Paragraph type %s is unavailable.', $bundle));
      }
    }

    $paragraphFields = [
      'hero' => ['field_heading', 'field_text', 'field_link', 'field_secondary_link'],
      'text_block' => ['field_heading', 'field_text'],
      'trust_list' => ['field_heading', 'field_items'],
    ];
    foreach ($paragraphFields as $bundle => $required) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('paragraph', $bundle);
      foreach ($required as $fieldName) {
        if (!isset($definitions[$fieldName])) {
          throw new RuntimeException(sprintf('Required %s field %s is unavailable.', $bundle, $fieldName));
        }
      }
    }

    if ($this->entityTypeManager->getStorage('filter_format')->load(self::TEXT_FORMAT) === NULL) {
      throw new RuntimeException('Required basic_html text format is unavailable.');
    }
    $this->loadAuthor();
  }

  /**
   * Returns the exact compatible page, or NULL when creation is safe.
   */
  private function loadCompatibleExisting(array $payload, string $payloadSha): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $mapping = $this->state->get(self::STATE_KEY);
    $mappedId = NULL;

    if ($mapping !== NULL) {
      if (!is_array($mapping)
        || ($mapping['candidate_id'] ?? NULL) !== self::CANDIDATE_ID
        || !is_int($mapping['node_id'] ?? NULL)
        || !is_string($mapping['payload_sha256'] ?? NULL)) {
        throw new RuntimeException('Drupal 2027 candidate state mapping is invalid.');
      }
      if (!hash_equals($mapping['payload_sha256'], $payloadSha)) {
        throw new RuntimeException('Drupal 2027 candidate state is bound to a different payload hash.');
      }
      $mappedId = $mapping['node_id'];
    }

    $aliasPath = $this->aliasManager->getPathByAlias(self::DRUPAL_ALIAS, self::LANGCODE);
    $aliasId = NULL;
    if ($aliasPath !== self::DRUPAL_ALIAS) {
      if (!preg_match('#^/node/([1-9][0-9]*)$#', $aliasPath, $matches)) {
        throw new RuntimeException('Drupal 2027 alias is already bound to an incompatible path.');
      }
      $aliasId = (int) $matches[1];
    }

    $titleIds = array_map(
      'intval',
      array_values($storage->getQuery()
        ->condition('type', self::BUNDLE)
        ->condition('langcode', self::LANGCODE)
        ->condition('title', $payload['title'])
        ->accessCheck(FALSE)
        ->execute()),
    );

    $ids = array_values(array_unique(array_filter([
      $mappedId,
      $aliasId,
      ...$titleIds,
    ], static fn ($value): bool => is_int($value) && $value > 0)));

    if ($ids === []) {
      return NULL;
    }
    if (count($ids) !== 1) {
      throw new RuntimeException('Drupal 2027 alias/title/state collision detected.');
    }

    $node = $storage->load($ids[0]);
    if (!$node instanceof NodeInterface) {
      throw new RuntimeException('Drupal 2027 candidate collision does not resolve to a node.');
    }
    if ($mappedId === NULL && $node->getRevisionLogMessage() !== $this->revisionMessage($payloadSha)) {
      throw new RuntimeException('Existing Drupal 2027 page is not bound to this exact candidate identity.');
    }

    $this->assertNodeMatchesPayload($node, $payload, $payloadSha);
    return $node;
  }

  /**
   * Creates the exact, fixed Paragraph sequence for this landing profile.
   *
   * @return \Drupal\paragraphs\ParagraphInterface[]
   */
  private function createParagraphs(array $payload): array {
    $sections = $payload['sections'];
    $paragraphs = [];

    $paragraphs[] = $this->createParagraph('hero', [
      'field_heading' => $payload['title'],
      'field_text' => $this->formatted($payload['hero']['submessage']),
      'field_link' => [
        'uri' => self::PRIMARY_URI,
        'title' => $payload['hero']['primary_cta'],
      ],
      'field_secondary_link' => [
        'uri' => self::SECONDARY_URI,
        'title' => $payload['hero']['secondary_cta'],
      ],
    ]);

    foreach (['lifecycle', 'situations', 'checks', 'composer_callout', 'method'] as $key) {
      $paragraphs[] = $this->createTextBlock($sections[$key]);
    }

    $paragraphs[] = $this->createParagraph('trust_list', [
      'field_heading' => $sections['reassurance']['heading'],
      'field_items' => array_map(
        fn (string $item): array => $this->formatted($item),
        $sections['reassurance']['items'],
      ),
    ]);

    $paragraphs[] = $this->createParagraph('text_block', [
      'field_text' => $this->formatted($sections['diagnostic_context']['body_html']),
    ]);
    $paragraphs[] = $this->createTextBlock($sections['audit']);
    $paragraphs[] = $this->createTextBlock($sections['faq']);

    return $paragraphs;
  }

  private function createTextBlock(array $section): ParagraphInterface {
    return $this->createParagraph('text_block', [
      'field_heading' => $section['heading'],
      'field_text' => $this->formatted($section['body_html']),
    ]);
  }

  private function createParagraph(string $bundle, array $values): ParagraphInterface {
    $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
      'type' => $bundle,
      'status' => TRUE,
    ]);
    if (!$paragraph instanceof ParagraphInterface) {
      throw new RuntimeException('Paragraph storage returned an invalid entity.');
    }
    foreach ($values as $fieldName => $value) {
      $paragraph->set($fieldName, $value);
    }
    $this->assertEntityValid($paragraph, sprintf('%s Paragraph', $bundle));
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Re-reads the Drupal entities and proves exact hash-bound idempotence.
   */
  private function assertNodeMatchesPayload(NodeInterface $node, array $payload, string $payloadSha): void {
    if ($node->bundle() !== self::BUNDLE || $node->language()->getId() !== self::LANGCODE) {
      throw new RuntimeException('Drupal 2027 collision has wrong bundle or language.');
    }
    if (!$node->isPublished() || $node->label() !== $payload['title']) {
      throw new RuntimeException('Drupal 2027 candidate Page identity/content does not match.');
    }
    if ($node->hasTranslation('en')) {
      throw new RuntimeException('Drupal 2027 FR-only profile unexpectedly has an EN translation.');
    }
    if ((string) $node->get('field_short_description')->value !== $payload['short_description']) {
      throw new RuntimeException('Drupal 2027 short description drift detected.');
    }

    $source = '/node/' . $node->id();
    $this->aliasManager->cacheClear($source);
    if ($this->aliasManager->getAliasByPath($source, self::LANGCODE) !== self::DRUPAL_ALIAS) {
      throw new RuntimeException('Drupal 2027 fixed FR alias is missing or changed.');
    }
    if ($node->getRevisionLogMessage() !== $this->revisionMessage($payloadSha)) {
      throw new RuntimeException('Drupal 2027 node revision is not bound to the exact payload hash.');
    }

    $components = $node->get('field_home_components')->referencedEntities();
    if (count($components) !== 10) {
      throw new RuntimeException('Drupal 2027 component count drift detected.');
    }

    $this->assertHero($components[0], $payload);
    foreach (['lifecycle', 'situations', 'checks', 'composer_callout', 'method'] as $offset => $key) {
      $this->assertTextBlock($components[$offset + 1], $payload['sections'][$key]);
    }
    $this->assertTrustList($components[6], $payload['sections']['reassurance']);
    $this->assertTextBlock($components[7], [
      'heading' => '',
      'body_html' => $payload['sections']['diagnostic_context']['body_html'],
    ]);
    $this->assertTextBlock($components[8], $payload['sections']['audit']);
    $this->assertTextBlock($components[9], $payload['sections']['faq']);
  }

  private function assertHero(mixed $paragraph, array $payload): void {
    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'hero') {
      throw new RuntimeException('Drupal 2027 hero component drift detected.');
    }
    if ((string) $paragraph->get('field_heading')->value !== $payload['title']
      || (string) $paragraph->get('field_text')->value !== $payload['hero']['submessage']) {
      throw new RuntimeException('Drupal 2027 hero copy drift detected.');
    }
    $primary = $paragraph->get('field_link')->first()?->getValue() ?? [];
    $secondary = $paragraph->get('field_secondary_link')->first()?->getValue() ?? [];
    if (($primary['uri'] ?? NULL) !== self::PRIMARY_URI
      || ($primary['title'] ?? NULL) !== $payload['hero']['primary_cta']
      || ($secondary['uri'] ?? NULL) !== self::SECONDARY_URI
      || ($secondary['title'] ?? NULL) !== $payload['hero']['secondary_cta']) {
      throw new RuntimeException('Drupal 2027 hero CTA drift detected.');
    }
  }

  private function assertTextBlock(mixed $paragraph, array $section): void {
    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'text_block') {
      throw new RuntimeException('Drupal 2027 text block sequence drift detected.');
    }
    if ((string) ($paragraph->get('field_heading')->value ?? '') !== ($section['heading'] ?? '')
      || (string) $paragraph->get('field_text')->value !== $section['body_html']) {
      throw new RuntimeException('Drupal 2027 text block content drift detected.');
    }
  }

  private function assertTrustList(mixed $paragraph, array $section): void {
    if (!$paragraph instanceof ParagraphInterface || $paragraph->bundle() !== 'trust_list') {
      throw new RuntimeException('Drupal 2027 reassurance component drift detected.');
    }
    if ((string) $paragraph->get('field_heading')->value !== $section['heading']) {
      throw new RuntimeException('Drupal 2027 reassurance heading drift detected.');
    }
    $items = array_map(
      static fn (array $item): string => (string) ($item['value'] ?? ''),
      $paragraph->get('field_items')->getValue(),
    );
    if ($items !== $section['items']) {
      throw new RuntimeException('Drupal 2027 reassurance items drift detected.');
    }
  }

  private function result(string $verdict, string $mode, string $payloadSha, ?NodeInterface $node): array {
    $result = [
      'status' => 'PASS',
      'verdict' => $verdict,
      'mode' => $mode,
      'profile' => self::PROFILE,
      'candidate_id' => self::CANDIDATE_ID,
      'payload_sha256' => $payloadSha,
      'target' => 'PREPROD',
      'bundle' => self::BUNDLE,
      'language' => self::LANGCODE,
      'alias' => self::PUBLIC_ALIAS,
      'collision_policy' => 'FAIL_CLOSED',
      'content_sync' => 'NONE',
      'prod_write' => 'NONE',
      'node' => NULL,
    ];
    if ($node instanceof NodeInterface) {
      $result['node'] = [
        'id' => (int) $node->id(),
        'uuid' => $node->uuid(),
        'revision_id' => (int) $node->getRevisionId(),
        'published' => $node->isPublished(),
        'alias' => self::PUBLIC_ALIAS,
      ];
    }
    return $result;
  }

  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface || !$author->isActive()) {
      throw new RuntimeException('Required PREPROD author uid=1 is unavailable.');
    }
    return $author;
  }

  private function assertEntityValid(object $entity, string $label): void {
    if (!method_exists($entity, 'validate')) {
      throw new RuntimeException(sprintf('%s cannot be validated.', $label));
    }
    $violations = $entity->validate();
    if ($violations->count() === 0) {
      return;
    }
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
    }
    throw new RuntimeException($label . ' validation failed: ' . implode(' | ', $messages));
  }

  private function formatted(string $value): array {
    return [
      'value' => $value,
      'format' => self::TEXT_FORMAT,
    ];
  }

  private function revisionMessage(string $payloadSha): string {
    return sprintf(
      'Agency PREPROD Drupal 2027 candidate %s payload %s',
      self::CANDIDATE_ID,
      $payloadSha,
    );
  }

  private function nonEmptyString(mixed $value): bool {
    return is_string($value) && trim($value) !== '';
  }

  /** @return string[] */
  private function sortedKeys(array $value): array {
    $keys = array_keys($value);
    sort($keys);
    return $keys;
  }

}

<?php

declare(strict_types=1);

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Closed FR+EN materializer for the exact #1046 Drupal 2027 candidate.
 *
 * This remains intentionally bounded to one Page, two languages, one neutral
 * Drupal alias and one fixed Paragraph sequence. It is not a generic writer.
 */
final class AgencyDrupal2027PreprodCandidate {

  private const PROFILE = 'drupal-2027-landing';
  private const CANDIDATE_ID = 'agency-drupal-2027-landing-1046';
  private const ISSUE_NUMBER = 1046;
  private const SOURCE_ISSUE = 1010;
  private const SOURCE_CANDIDATE_REVISION = 5553858896;
  private const SOURCE_CANDIDATE_SHA256 = '07fb10ab4a54371d877fbfc6b3f185eda41085ae3bd5080de2d695843c9d049e';
  private const EXPECTED_PAYLOAD_SHA256 = 'ac96465c5717f78af76e368d8598399cbe997ed63d7cc753d575337c9321af83';
  private const LEGACY_CANDIDATE_ID = 'agency-drupal-2027-landing-1012';
  private const BUNDLE = 'page';
  private const DEFAULT_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const DRUPAL_ALIAS = '/drupal-2027';
  private const TEXT_FORMAT = 'basic_html';
  private const AUTHOR_UID = 1;
  private const STATE_KEY = 'agency_editorial.drupal_2027_landing';
  private const PRIMARY_URI = 'internal:/drupal-2027#block-emerging-digital-drupal-lifecycle-diagnostic';
  private const SECONDARY_URI = 'internal:/drupal-2027#points-a-verifier-socle';

  /** @var array<string, string> */
  private const PUBLIC_ALIASES = [
    'fr' => '/fr/drupal-2027',
    'en' => '/en/drupal-2027',
  ];

  /** @var string[] */
  private const TOP_LEVEL_KEYS = [
    'aliases',
    'bundle',
    'candidate_id',
    'en',
    'fr',
    'issue_number',
    'language_mode',
    'profile',
    'published',
    'schema_version',
    'source_candidate_revision',
    'source_candidate_sha256',
    'source_issue',
    'target',
  ];

  /** @var string[] */
  private const LANGUAGE_KEYS = [
    'hero',
    'sections',
    'short_description',
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
    private readonly LanguageManagerInterface $languageManager,
  ) {
  }

  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('state'),
      $container->get('account_switcher'),
      $container->get('path_alias.manager'),
      $container->get('language_manager'),
    );
  }

  public function inspect(array $payload, string $payloadSha): array {
    return $this->plan($payload, $payloadSha, 'inspect');
  }

  public function dryRun(array $payload, string $payloadSha): array {
    return $this->plan($payload, $payloadSha, 'dry-run');
  }

  public function apply(array $payload, string $payloadSha): array {
    $plan = $this->plan($payload, $payloadSha, 'apply');
    if ($plan['verdict'] === 'IDEMPOTENT') {
      return $plan;
    }
    if (!in_array($plan['verdict'], ['CREATE_READY', 'UPDATE_READY'], TRUE)) {
      throw new RuntimeException('Drupal 2027 #1046 candidate is not write-ready.');
    }

    $author = $this->loadAuthor();
    $createdParagraphs = [];
    $node = NULL;
    $createdNode = FALSE;
    $this->accountSwitcher->switchTo($author);

    try {
      $node = $this->loadCompatibleExisting($payload, $payloadSha, TRUE);
      $createdParagraphs = $this->createParagraphs($payload);
      $references = array_map(
        static fn (ParagraphInterface $paragraph): array => [
          'target_id' => (int) $paragraph->id(),
          'target_revision_id' => (int) $paragraph->getRevisionId(),
        ],
        $createdParagraphs,
      );

      if (!$node instanceof NodeInterface) {
        $node = $this->entityTypeManager->getStorage('node')->create([
          'type' => self::BUNDLE,
          'langcode' => self::DEFAULT_LANGCODE,
          'uid' => self::AUTHOR_UID,
          'status' => TRUE,
        ]);
        if (!$node instanceof NodeInterface) {
          throw new RuntimeException('Drupal node storage did not create the #1046 Page.');
        }
        $createdNode = TRUE;
      }

      $this->applyNodeLanguage($node, self::DEFAULT_LANGCODE, $payload['fr'], $references);
      $this->applyNodeLanguage($node, self::TRANSLATION_LANGCODE, $payload['en'], $references);
      $node->setNewRevision(TRUE);
      $node->setRevisionUserId(self::AUTHOR_UID);
      $node->setRevisionLogMessage($this->revisionMessage($payloadSha));
      $this->assertEntityValid($node, 'Drupal 2027 #1046 Page');
      $node->save();

      $this->entityTypeManager->getStorage('node')->resetCache([(int) $node->id()]);
      $this->aliasManager->cacheClear('/node/' . $node->id());
      $reloaded = $this->entityTypeManager->getStorage('node')->load((int) $node->id());
      if (!$reloaded instanceof NodeInterface) {
        throw new RuntimeException('Drupal 2027 #1046 Page could not be reloaded after apply.');
      }
      $this->assertNodeMatchesPayload($reloaded, $payload, $payloadSha);

      $this->state->set(self::STATE_KEY, [
        'candidate_id' => self::CANDIDATE_ID,
        'node_id' => (int) $reloaded->id(),
        'payload_sha256' => $payloadSha,
      ]);

      return $this->result('APPLIED', 'apply', $payloadSha, $reloaded);
    }
    catch (Throwable $exception) {
      if ($createdNode && $node instanceof NodeInterface && !$node->isNew()) {
        $node->delete();
      }
      foreach ($createdParagraphs as $paragraph) {
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

  private function plan(array $payload, string $payloadSha, string $mode): array {
    $this->validatePayload($payload, $payloadSha);
    $this->assertRuntimePrerequisites();

    $node = $this->loadCompatibleExisting($payload, $payloadSha, FALSE);
    if (!$node instanceof NodeInterface) {
      return $this->result('CREATE_READY', $mode, $payloadSha, NULL);
    }

    if ($this->isExactCurrentCandidate($node, $payload, $payloadSha)) {
      return $this->result('IDEMPOTENT', $mode, $payloadSha, $node);
    }

    return $this->result('UPDATE_READY', $mode, $payloadSha, $node);
  }

  private function validatePayload(array $payload, string $payloadSha): void {
    if (!hash_equals(self::EXPECTED_PAYLOAD_SHA256, $payloadSha)) {
      throw new InvalidArgumentException('Drupal 2027 #1046 payload hash is not the frozen candidate SHA-256.');
    }
    if ($this->sortedKeys($payload) !== self::TOP_LEVEL_KEYS) {
      throw new InvalidArgumentException('Drupal 2027 #1046 top-level payload schema mismatch.');
    }

    $fixed = [
      'schema_version' => 2,
      'profile' => self::PROFILE,
      'candidate_id' => self::CANDIDATE_ID,
      'issue_number' => self::ISSUE_NUMBER,
      'source_issue' => self::SOURCE_ISSUE,
      'source_candidate_revision' => self::SOURCE_CANDIDATE_REVISION,
      'source_candidate_sha256' => self::SOURCE_CANDIDATE_SHA256,
      'target' => 'PREPROD',
      'bundle' => self::BUNDLE,
      'language_mode' => 'FR_EN',
      'published' => TRUE,
      'aliases' => self::PUBLIC_ALIASES,
    ];
    foreach ($fixed as $key => $value) {
      if (($payload[$key] ?? NULL) !== $value) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 payload field %s is fixed.', $key));
      }
    }

    foreach (['fr', 'en'] as $langcode) {
      $this->validateLanguagePayload($payload[$langcode] ?? NULL, $langcode);
    }
  }

  private function validateLanguagePayload(mixed $language, string $langcode): void {
    if (!is_array($language) || $this->sortedKeys($language) !== self::LANGUAGE_KEYS) {
      throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s schema mismatch.', $langcode));
    }
    foreach (['title', 'short_description'] as $key) {
      if (!$this->nonEmptyString($language[$key] ?? NULL)) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s %s cannot be empty.', $langcode, $key));
      }
    }

    $hero = $language['hero'] ?? NULL;
    if (!is_array($hero) || $this->sortedKeys($hero) !== ['primary_cta', 'secondary_cta', 'submessage']) {
      throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s hero schema mismatch.', $langcode));
    }
    foreach ($hero as $value) {
      if (!$this->nonEmptyString($value)) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s hero cannot contain empty values.', $langcode));
      }
    }

    $sections = $language['sections'] ?? NULL;
    if (!is_array($sections) || $this->sortedKeys($sections) !== self::SECTION_KEYS) {
      throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s sections schema mismatch.', $langcode));
    }
    foreach (['lifecycle', 'situations', 'checks', 'composer_callout', 'method', 'audit', 'faq'] as $key) {
      $section = $sections[$key] ?? NULL;
      if (!is_array($section) || $this->sortedKeys($section) !== ['body_html', 'heading']) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s section %s schema mismatch.', $langcode, $key));
      }
      if (!$this->nonEmptyString($section['heading']) || !$this->nonEmptyString($section['body_html'])) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s section %s cannot be empty.', $langcode, $key));
      }
    }

    $diagnostic = $sections['diagnostic_context'] ?? NULL;
    if (!is_array($diagnostic) || $this->sortedKeys($diagnostic) !== ['body_html'] || !$this->nonEmptyString($diagnostic['body_html'])) {
      throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s diagnostic context schema mismatch.', $langcode));
    }

    $reassurance = $sections['reassurance'] ?? NULL;
    if (!is_array($reassurance) || $this->sortedKeys($reassurance) !== ['heading', 'items']) {
      throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s reassurance schema mismatch.', $langcode));
    }
    if (!$this->nonEmptyString($reassurance['heading']) || !is_array($reassurance['items']) || count($reassurance['items']) !== 5) {
      throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s reassurance requires five items.', $langcode));
    }
    foreach ($reassurance['items'] as $item) {
      if (!$this->nonEmptyString($item)) {
        throw new InvalidArgumentException(sprintf('Drupal 2027 #1046 %s reassurance contains an empty item.', $langcode));
      }
    }
  }

  private function assertRuntimePrerequisites(): void {
    foreach ([self::DEFAULT_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      if (!$this->languageManager->getLanguage($langcode)) {
        throw new RuntimeException(sprintf('Required Drupal language %s is unavailable.', $langcode));
      }
    }

    if ($this->entityTypeManager->getStorage('node_type')->load(self::BUNDLE) === NULL) {
      throw new RuntimeException('Required Page content type is unavailable.');
    }
    $pageFields = $this->entityFieldManager->getFieldDefinitions('node', self::BUNDLE);
    foreach (['field_short_description', 'field_home_components', 'path'] as $fieldName) {
      if (!isset($pageFields[$fieldName])) {
        throw new RuntimeException(sprintf('Required Page field %s is unavailable.', $fieldName));
      }
    }

    $paragraphFields = [
      'hero' => ['field_heading', 'field_text', 'field_link', 'field_secondary_link'],
      'text_block' => ['field_heading', 'field_text'],
      'trust_list' => ['field_heading', 'field_items'],
    ];
    foreach ($paragraphFields as $bundle => $required) {
      if ($this->entityTypeManager->getStorage('paragraphs_type')->load($bundle) === NULL) {
        throw new RuntimeException(sprintf('Required Paragraph type %s is unavailable.', $bundle));
      }
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

  private function loadCompatibleExisting(array $payload, string $payloadSha, bool $forWrite): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    $mapping = $this->state->get(self::STATE_KEY);
    $mappedId = NULL;
    $mappingKind = 'NONE';

    if ($mapping !== NULL) {
      if (!is_array($mapping)
        || !is_string($mapping['candidate_id'] ?? NULL)
        || !is_int($mapping['node_id'] ?? NULL)
        || !is_string($mapping['payload_sha256'] ?? NULL)) {
        throw new RuntimeException('Drupal 2027 candidate state mapping is invalid.');
      }
      if (($mapping['candidate_id'] === self::CANDIDATE_ID)
        && hash_equals($mapping['payload_sha256'], $payloadSha)) {
        $mappingKind = 'CURRENT';
      }
      elseif (($mapping['candidate_id'] === self::LEGACY_CANDIDATE_ID)
        && hash_equals($mapping['payload_sha256'], self::SOURCE_CANDIDATE_SHA256)) {
        $mappingKind = 'LEGACY';
      }
      else {
        throw new RuntimeException('Drupal 2027 state is bound to an incompatible candidate.');
      }
      $mappedId = $mapping['node_id'];
    }

    $ids = array_values(array_unique(array_filter([
      $mappedId,
      $this->aliasNodeId(self::DEFAULT_LANGCODE),
      $this->aliasNodeId(self::TRANSLATION_LANGCODE),
      ...array_map(
        'intval',
        array_values($storage->getQuery()
          ->condition('type', self::BUNDLE)
          ->condition('langcode', self::DEFAULT_LANGCODE)
          ->condition('title', $payload['fr']['title'])
          ->accessCheck(FALSE)
          ->execute()),
      ),
    ], static fn ($value): bool => is_int($value) && $value > 0)));

    if ($ids === []) {
      return NULL;
    }
    if (count($ids) !== 1) {
      throw new RuntimeException('Drupal 2027 #1046 alias/title/state collision detected.');
    }

    $node = $storage->load($ids[0]);
    if (!$node instanceof NodeInterface) {
      throw new RuntimeException('Drupal 2027 #1046 collision does not resolve to a node.');
    }

    if ($mappingKind === 'NONE') {
      $revisionLog = (string) $node->getRevisionLogMessage();
      $currentLog = $this->revisionMessage($payloadSha);
      $legacyLog = $this->legacyRevisionMessage();
      if ($revisionLog !== $currentLog && $revisionLog !== $legacyLog) {
        throw new RuntimeException('Drupal 2027 #1046 existing page is not a recognized candidate revision.');
      }
      $mappingKind = $revisionLog === $currentLog ? 'CURRENT' : 'LEGACY';
    }

    if ($mappingKind === 'CURRENT') {
      $this->assertNodeMatchesPayload($node, $payload, $payloadSha);
      return $node;
    }

    $this->assertLanguageMatchesPayload($node, self::DEFAULT_LANGCODE, $payload['fr'], FALSE);
    if ($node->hasTranslation(self::TRANSLATION_LANGCODE)) {
      $this->assertLanguageMatchesPayload($node, self::TRANSLATION_LANGCODE, $payload['en'], TRUE);
    }
    if ((string) $node->getRevisionLogMessage() !== $this->legacyRevisionMessage()) {
      throw new RuntimeException('Drupal 2027 legacy candidate revision identity drift detected.');
    }

    if (!$forWrite && $mappingKind === 'LEGACY') {
      return $node;
    }
    return $node;
  }

  private function aliasNodeId(string $langcode): ?int {
    $path = $this->aliasManager->getPathByAlias(self::DRUPAL_ALIAS, $langcode);
    if ($path === self::DRUPAL_ALIAS) {
      return NULL;
    }
    if (!preg_match('#^/node/([1-9][0-9]*)$#', $path, $matches)) {
      throw new RuntimeException(sprintf('Drupal 2027 alias for %s is bound to an incompatible path.', $langcode));
    }
    return (int) $matches[1];
  }

  /** @return \Drupal\paragraphs\ParagraphInterface[] */
  private function createParagraphs(array $payload): array {
    $fr = $payload['fr'];
    $en = $payload['en'];
    $paragraphs = [];

    $paragraphs[] = $this->createParagraph('hero', [
      'field_heading' => $fr['title'],
      'field_text' => $this->formatted($fr['hero']['submessage']),
      'field_link' => ['uri' => self::PRIMARY_URI, 'title' => $fr['hero']['primary_cta']],
      'field_secondary_link' => ['uri' => self::SECONDARY_URI, 'title' => $fr['hero']['secondary_cta']],
    ], [
      'field_heading' => $en['title'],
      'field_text' => $this->formatted($en['hero']['submessage']),
      'field_link' => ['uri' => self::PRIMARY_URI, 'title' => $en['hero']['primary_cta']],
      'field_secondary_link' => ['uri' => self::SECONDARY_URI, 'title' => $en['hero']['secondary_cta']],
    ]);

    foreach (['lifecycle', 'situations', 'checks', 'composer_callout', 'method'] as $key) {
      $paragraphs[] = $this->createTextBlock($fr['sections'][$key], $en['sections'][$key]);
    }

    $paragraphs[] = $this->createParagraph('trust_list', [
      'field_heading' => $fr['sections']['reassurance']['heading'],
      'field_items' => array_map(fn (string $item): array => $this->formatted($item), $fr['sections']['reassurance']['items']),
    ], [
      'field_heading' => $en['sections']['reassurance']['heading'],
      'field_items' => array_map(fn (string $item): array => $this->formatted($item), $en['sections']['reassurance']['items']),
    ]);

    $paragraphs[] = $this->createParagraph('text_block', [
      'field_text' => $this->formatted($fr['sections']['diagnostic_context']['body_html']),
    ], [
      'field_text' => $this->formatted($en['sections']['diagnostic_context']['body_html']),
    ]);
    $paragraphs[] = $this->createTextBlock($fr['sections']['audit'], $en['sections']['audit']);
    $paragraphs[] = $this->createTextBlock($fr['sections']['faq'], $en['sections']['faq']);

    return $paragraphs;
  }

  private function createTextBlock(array $fr, array $en): ParagraphInterface {
    return $this->createParagraph('text_block', [
      'field_heading' => $fr['heading'],
      'field_text' => $this->formatted($fr['body_html']),
    ], [
      'field_heading' => $en['heading'],
      'field_text' => $this->formatted($en['body_html']),
    ]);
  }

  private function createParagraph(string $bundle, array $frValues, array $enValues): ParagraphInterface {
    $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
      'type' => $bundle,
      'langcode' => self::DEFAULT_LANGCODE,
      'status' => TRUE,
    ]);
    if (!$paragraph instanceof ParagraphInterface) {
      throw new RuntimeException('Paragraph storage returned an invalid entity.');
    }
    foreach ($frValues as $fieldName => $value) {
      $paragraph->set($fieldName, $value);
    }
    $translation = $paragraph->addTranslation(self::TRANSLATION_LANGCODE);
    foreach ($enValues as $fieldName => $value) {
      $translation->set($fieldName, $value);
    }
    if ($translation->hasField('status')) {
      $translation->set('status', TRUE);
    }
    $this->assertEntityValid($paragraph, sprintf('%s bilingual Paragraph', $bundle));
    $paragraph->save();
    return $paragraph;
  }

  private function applyNodeLanguage(NodeInterface $node, string $langcode, array $language, array $references): void {
    $translation = $langcode === self::DEFAULT_LANGCODE
      ? $node
      : ($node->hasTranslation($langcode)
        ? $node->getTranslation($langcode)
        : $node->addTranslation($langcode));

    $translation->setTitle($language['title']);
    $translation->set('status', TRUE);
    $translation->set('uid', self::AUTHOR_UID);
    $translation->set('field_short_description', [[
      'value' => $language['short_description'],
      'format' => self::TEXT_FORMAT,
    ]]);
    $translation->set('field_home_components', $references);
    $translation->set('path', [
      'alias' => self::DRUPAL_ALIAS,
      'pathauto' => 0,
    ]);
  }

  private function isExactCurrentCandidate(NodeInterface $node, array $payload, string $payloadSha): bool {
    try {
      $this->assertNodeMatchesPayload($node, $payload, $payloadSha);
      return TRUE;
    }
    catch (Throwable) {
      return FALSE;
    }
  }

  private function assertNodeMatchesPayload(NodeInterface $node, array $payload, string $payloadSha): void {
    if ($node->bundle() !== self::BUNDLE || $node->language()->getId() !== self::DEFAULT_LANGCODE) {
      throw new RuntimeException('Drupal 2027 #1046 node has wrong bundle or default language.');
    }
    if (!$node->hasTranslation(self::TRANSLATION_LANGCODE)) {
      throw new RuntimeException('Drupal 2027 #1046 EN translation is missing.');
    }
    if ((string) $node->getRevisionLogMessage() !== $this->revisionMessage($payloadSha)) {
      throw new RuntimeException('Drupal 2027 #1046 node revision is not bound to the frozen payload.');
    }

    foreach ([self::DEFAULT_LANGCODE => 'fr', self::TRANSLATION_LANGCODE => 'en'] as $langcode => $payloadKey) {
      $this->assertLanguageMatchesPayload($node, $langcode, $payload[$payloadKey], TRUE);
      $this->aliasManager->cacheClear('/node/' . $node->id());
      if ($this->aliasManager->getAliasByPath('/node/' . $node->id(), $langcode) !== self::DRUPAL_ALIAS) {
        throw new RuntimeException(sprintf('Drupal 2027 #1046 neutral alias is missing for %s.', $langcode));
      }
    }
  }

  private function assertLanguageMatchesPayload(NodeInterface $node, string $langcode, array $language, bool $requireTranslation): void {
    if ($langcode !== self::DEFAULT_LANGCODE && !$node->hasTranslation($langcode)) {
      if ($requireTranslation) {
        throw new RuntimeException(sprintf('Drupal 2027 translation %s is missing.', $langcode));
      }
      return;
    }
    $translation = $langcode === self::DEFAULT_LANGCODE ? $node : $node->getTranslation($langcode);
    if (!$translation->isPublished() || $translation->label() !== $language['title']) {
      throw new RuntimeException(sprintf('Drupal 2027 %s title/publication drift detected.', $langcode));
    }
    if ((string) $translation->get('field_short_description')->value !== $language['short_description']) {
      throw new RuntimeException(sprintf('Drupal 2027 %s short description drift detected.', $langcode));
    }

    $components = $translation->get('field_home_components')->referencedEntities();
    if (count($components) !== 10) {
      throw new RuntimeException(sprintf('Drupal 2027 %s component count drift detected.', $langcode));
    }
    $localized = array_map(
      static function (ParagraphInterface $paragraph) use ($langcode): ParagraphInterface {
        if ($paragraph->language()->getId() === $langcode) {
          return $paragraph;
        }
        if (!$paragraph->hasTranslation($langcode)) {
          throw new RuntimeException(sprintf('Drupal 2027 paragraph translation %s is missing.', $langcode));
        }
        return $paragraph->getTranslation($langcode);
      },
      $components,
    );

    $this->assertHero($localized[0], $language);
    foreach (['lifecycle', 'situations', 'checks', 'composer_callout', 'method'] as $offset => $key) {
      $this->assertTextBlock($localized[$offset + 1], $language['sections'][$key]);
    }
    $this->assertTrustList($localized[6], $language['sections']['reassurance']);
    $this->assertTextBlock($localized[7], [
      'heading' => '',
      'body_html' => $language['sections']['diagnostic_context']['body_html'],
    ]);
    $this->assertTextBlock($localized[8], $language['sections']['audit']);
    $this->assertTextBlock($localized[9], $language['sections']['faq']);
  }

  private function assertHero(ParagraphInterface $paragraph, array $language): void {
    if ($paragraph->bundle() !== 'hero') {
      throw new RuntimeException('Drupal 2027 hero component drift detected.');
    }
    if ((string) $paragraph->get('field_heading')->value !== $language['title']
      || (string) $paragraph->get('field_text')->value !== $language['hero']['submessage']) {
      throw new RuntimeException('Drupal 2027 hero copy drift detected.');
    }
    $primary = $paragraph->get('field_link')->first()?->getValue() ?? [];
    $secondary = $paragraph->get('field_secondary_link')->first()?->getValue() ?? [];
    if (($primary['uri'] ?? NULL) !== self::PRIMARY_URI
      || ($primary['title'] ?? NULL) !== $language['hero']['primary_cta']
      || ($secondary['uri'] ?? NULL) !== self::SECONDARY_URI
      || ($secondary['title'] ?? NULL) !== $language['hero']['secondary_cta']) {
      throw new RuntimeException('Drupal 2027 hero CTA drift detected.');
    }
  }

  private function assertTextBlock(ParagraphInterface $paragraph, array $section): void {
    if ($paragraph->bundle() !== 'text_block') {
      throw new RuntimeException('Drupal 2027 text block sequence drift detected.');
    }
    if ((string) ($paragraph->get('field_heading')->value ?? '') !== ($section['heading'] ?? '')
      || (string) $paragraph->get('field_text')->value !== $section['body_html']) {
      throw new RuntimeException('Drupal 2027 text block content drift detected.');
    }
  }

  private function assertTrustList(ParagraphInterface $paragraph, array $section): void {
    if ($paragraph->bundle() !== 'trust_list') {
      throw new RuntimeException('Drupal 2027 reassurance component drift detected.');
    }
    $items = array_map(
      static fn (array $item): string => (string) ($item['value'] ?? ''),
      $paragraph->get('field_items')->getValue(),
    );
    if ((string) $paragraph->get('field_heading')->value !== $section['heading'] || $items !== $section['items']) {
      throw new RuntimeException('Drupal 2027 reassurance content drift detected.');
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
      'language_mode' => 'FR_EN',
      'aliases' => self::PUBLIC_ALIASES,
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
        'aliases' => self::PUBLIC_ALIASES,
      ];
    }
    return $result;
  }

  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface || !$author->isActive()) {
      throw new RuntimeException('Required Drupal author uid=1 is unavailable.');
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
    return ['value' => $value, 'format' => self::TEXT_FORMAT];
  }

  private function revisionMessage(string $payloadSha): string {
    return sprintf('Agency Drupal 2027 candidate %s payload %s', self::CANDIDATE_ID, $payloadSha);
  }

  private function legacyRevisionMessage(): string {
    return sprintf(
      'Agency PREPROD Drupal 2027 candidate %s payload %s',
      self::LEGACY_CANDIDATE_ID,
      self::SOURCE_CANDIDATE_SHA256,
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

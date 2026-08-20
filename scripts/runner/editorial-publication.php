<?php

declare(strict_types=1);

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Bounded helper for governed publication of editor-owned Drupal Articles.
 *
 * This file is intentionally not a Drupal runtime service. It is executed only
 * through the trusted production route versioned in the repository.
 */
final class AgencyEditorialPublication {

  private const BUNDLE = 'article';
  private const SOURCE_LANGCODE = 'fr';
  private const TRANSLATION_LANGCODE = 'en';
  private const TEXT_FORMAT = 'basic_html';
  private const CATEGORY_VOCABULARY = 'blog_categories';
  private const AUTHOR_UID = 1;
  private const STATE_PREFIX = 'agency_editorial.issue.';

  /**
   * Tags deliberately narrower than Drupal's complete basic_html definition.
   */
  private const ALLOWED_BODY_TAGS = [
    'p',
    'h2',
    'h3',
    'h4',
    'ul',
    'ol',
    'li',
    'strong',
    'em',
    'a',
    'blockquote',
    'dl',
    'dt',
    'dd',
    'code',
    'br',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly AccountSwitcherInterface $accountSwitcher,
    private readonly AliasManagerInterface $aliasManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  public static function fromContainer(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('state'),
      $container->get('account_switcher'),
      $container->get('path_alias.manager'),
      $container->get('language_manager'),
    );
  }

  /**
   * Returns a read-only view of the runtime prerequisites for one issue.
   */
  public function inspect(int $issueNumber): array {
    $fields = $this->entityTypeManager
      ->getStorage('field_config')
      ->loadByProperties(['entity_type' => 'node', 'bundle' => self::BUNDLE]);
    $fieldNames = [];
    foreach ($fields as $field) {
      $fieldNames[] = $field->getName();
    }
    sort($fieldNames);

    $articleType = $this->entityTypeManager
      ->getStorage('node_type')
      ->load(self::BUNDLE);
    $format = $this->entityTypeManager
      ->getStorage('filter_format')
      ->load(self::TEXT_FORMAT);
    $author = $this->loadAuthor();

    $requiredFields = ['body', 'field_blog_category', 'field_short_description'];
    $missingFields = array_values(array_diff($requiredFields, $fieldNames));
    $languagesReady = $this->languageManager->getLanguage(self::SOURCE_LANGCODE) !== NULL
      && $this->languageManager->getLanguage(self::TRANSLATION_LANGCODE) !== NULL;

    $categories = [];
    $termStorage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $termStorage->getQuery()
      ->condition('vid', self::CATEGORY_VOCABULARY)
      ->accessCheck(FALSE)
      ->sort('weight')
      ->sort('name')
      ->execute();
    foreach ($termStorage->loadMultiple($ids) as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }
      $item = [
        'tid' => (int) $term->id(),
        'fr' => $term->label(),
        'en' => NULL,
      ];
      if ($term->hasTranslation(self::TRANSLATION_LANGCODE)) {
        $item['en'] = $term->getTranslation(self::TRANSLATION_LANGCODE)->label();
      }
      $categories[] = $item;
    }

    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);
    if (!is_array($mapping)) {
      $mapping = NULL;
    }

    return [
      'status' => 'PASS',
      'verdict' => (
        $articleType !== NULL
        && $format !== NULL
        && $author->isActive()
        && $languagesReady
        && $missingFields === []
      ) ? 'READY' : 'NOT_READY',
      'mode' => 'inspect',
      'issue_number' => $issueNumber,
      'contract' => [
        'bundle' => self::BUNDLE,
        'source_langcode' => self::SOURCE_LANGCODE,
        'translation_langcode' => self::TRANSLATION_LANGCODE,
        'text_format' => self::TEXT_FORMAT,
        'category_vocabulary' => self::CATEGORY_VOCABULARY,
        'author_uid' => self::AUTHOR_UID,
      ],
      'runtime' => [
        'article_type' => $articleType !== NULL,
        'basic_html' => $format !== NULL,
        'languages' => $languagesReady,
        'missing_fields' => $missingFields,
        'author' => [
          'uid' => (int) $author->id(),
          'active' => $author->isActive(),
        ],
        'categories' => $categories,
        'mapping' => $mapping,
      ],
    ];
  }

  /**
   * Performs all validation without saving content.
   */
  public function dryRun(array $payload, int $issueNumber, string $payloadSha): array {
    $this->assertRuntimeReady($issueNumber);
    $this->validatePayload($payload, $issueNumber);
    $category = $this->resolveCategory($payload['category']);
    $duplicate = $this->classifyDuplicate($payload, $issueNumber, $payloadSha);

    if ($duplicate['verdict'] === 'IDEMPOTENT') {
      $node = $this->entityTypeManager
        ->getStorage('node')
        ->load((int) $duplicate['node_id']);
      if (!$node instanceof NodeInterface) {
        throw new RuntimeException('Idempotence mapping points to a missing node.');
      }

      return [
        'status' => 'PASS',
        'verdict' => 'IDEMPOTENT',
        'mode' => 'dry-run',
        'issue_number' => $issueNumber,
        'payload_sha256' => $payloadSha,
        'category' => $this->categoryResult($category),
        'node' => $this->nodeResult($node),
      ];
    }

    return [
      'status' => 'PASS',
      'verdict' => 'READY',
      'mode' => 'dry-run',
      'issue_number' => $issueNumber,
      'payload_sha256' => $payloadSha,
      'category' => $this->categoryResult($category),
      'planned_change' => [
        'operation' => 'create',
        'bundle' => self::BUNDLE,
        'source_langcode' => self::SOURCE_LANGCODE,
        'translation_langcode' => self::TRANSLATION_LANGCODE,
        'published' => $payload['published'],
        'title_fr' => $payload['fr']['title'],
        'title_en' => $payload['en']['title'],
      ],
    ];
  }

  /**
   * Creates exactly one Article or returns the existing idempotent result.
   */
  public function apply(array $payload, int $issueNumber, string $payloadSha): array {
    $dryRun = $this->dryRun($payload, $issueNumber, $payloadSha);
    if ($dryRun['verdict'] === 'IDEMPOTENT') {
      $dryRun['mode'] = 'apply';
      return $dryRun;
    }

    $category = $this->resolveCategory($payload['category']);
    $author = $this->loadAuthor();
    $this->accountSwitcher->switchTo($author);

    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $node = $storage->create([
        'type' => self::BUNDLE,
        'langcode' => self::SOURCE_LANGCODE,
        'title' => $payload['fr']['title'],
        'uid' => self::AUTHOR_UID,
        'status' => $payload['published'],
        'field_short_description' => [[
          'value' => $payload['fr']['short_description'],
          'format' => self::TEXT_FORMAT,
        ]],
        'body' => [[
          'value' => $payload['fr']['body_html'],
          'format' => self::TEXT_FORMAT,
        ]],
        'field_blog_category' => [[
          'target_id' => (int) $category->id(),
        ]],
      ]);
      if (!$node instanceof NodeInterface) {
        throw new RuntimeException('Node storage did not create a node entity.');
      }

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
        'field_blog_category' => [[
          'target_id' => (int) $category->id(),
        ]],
      ]);

      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage(sprintf(
        'Agency editorial issue #%d payload %s',
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
        throw new RuntimeException('Article validation failed: ' . implode(' | ', $messages));
      }

      $node->save();
      $nodeId = (int) $node->id();
      $this->state->set(self::STATE_PREFIX . $issueNumber, [
        'node_id' => $nodeId,
        'payload_sha256' => $payloadSha,
      ]);

      $storage->resetCache([$nodeId]);
      $reloaded = $storage->load($nodeId);
      if (!$reloaded instanceof NodeInterface) {
        throw new RuntimeException('Created Article could not be reloaded.');
      }
      if (!$reloaded->hasTranslation(self::TRANSLATION_LANGCODE)) {
        throw new RuntimeException('Created Article is missing the EN translation.');
      }

      return [
        'status' => 'PASS',
        'verdict' => 'APPLIED',
        'mode' => 'apply',
        'issue_number' => $issueNumber,
        'payload_sha256' => $payloadSha,
        'category' => $this->categoryResult($category),
        'node' => $this->nodeResult($reloaded),
      ];
    }
    finally {
      $this->accountSwitcher->switchBack();
    }
  }

  /**
   * Enforces the closed payload schema and conservative text contract.
   */
  public function validatePayload(array $payload, int $issueNumber): void {
    $this->assertExactKeys($payload, [
      'schema_version',
      'issue_number',
      'bundle',
      'published',
      'category',
      'fr',
      'en',
    ], 'payload');

    if (($payload['schema_version'] ?? NULL) !== 1) {
      throw new InvalidArgumentException('schema_version must be 1.');
    }
    if (($payload['issue_number'] ?? NULL) !== $issueNumber) {
      throw new InvalidArgumentException('Payload issue_number mismatch.');
    }
    if (($payload['bundle'] ?? NULL) !== self::BUNDLE) {
      throw new InvalidArgumentException('Only bundle=article is allowed.');
    }
    if (!is_bool($payload['published'] ?? NULL)) {
      throw new InvalidArgumentException('published must be boolean.');
    }

    if (!is_array($payload['category'] ?? NULL)) {
      throw new InvalidArgumentException('category must be an object.');
    }
    $this->assertExactKeys($payload['category'], ['tid', 'name'], 'category');
    if (!is_int($payload['category']['tid']) || $payload['category']['tid'] <= 0) {
      throw new InvalidArgumentException('category.tid must be a positive integer.');
    }
    $this->assertPlainText($payload['category']['name'], 'category.name', 255);

    foreach ([self::SOURCE_LANGCODE, self::TRANSLATION_LANGCODE] as $langcode) {
      if (!is_array($payload[$langcode] ?? NULL)) {
        throw new InvalidArgumentException("$langcode must be an object.");
      }
      $this->assertExactKeys(
        $payload[$langcode],
        ['title', 'short_description', 'body_html'],
        $langcode,
      );
      $this->assertPlainText($payload[$langcode]['title'], "$langcode.title", 255);
      $this->assertPlainText(
        $payload[$langcode]['short_description'],
        "$langcode.short_description",
        1200,
      );
      $this->assertBodyHtml($payload[$langcode]['body_html'], "$langcode.body_html");
    }
  }

  private function assertRuntimeReady(int $issueNumber): void {
    $inspect = $this->inspect($issueNumber);
    if ($inspect['verdict'] !== 'READY') {
      throw new RuntimeException('Editorial runtime prerequisites are not ready.');
    }
  }

  private function loadAuthor(): UserInterface {
    $author = $this->entityTypeManager->getStorage('user')->load(self::AUTHOR_UID);
    if (!$author instanceof UserInterface) {
      throw new RuntimeException('Required Drupal author uid=1 does not exist.');
    }
    if (!$author->isActive()) {
      throw new RuntimeException('Required Drupal author uid=1 is blocked.');
    }
    return $author;
  }

  private function resolveCategory(array $category): TermInterface {
    $term = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->load((int) $category['tid']);
    if (!$term instanceof TermInterface) {
      throw new InvalidArgumentException('Selected Blog category does not exist.');
    }
    if ($term->bundle() !== self::CATEGORY_VOCABULARY) {
      throw new InvalidArgumentException('Selected taxonomy term is not a Blog category.');
    }
    if ($this->normalizePlainText($term->label()) !== $this->normalizePlainText($category['name'])) {
      throw new InvalidArgumentException('Selected Blog category name/TID pair is stale or mismatched.');
    }
    return $term;
  }

  private function classifyDuplicate(
    array $payload,
    int $issueNumber,
    string $payloadSha,
  ): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $mapping = $this->state->get(self::STATE_PREFIX . $issueNumber);

    if ($mapping !== NULL && !is_array($mapping)) {
      throw new RuntimeException('Editorial idempotence mapping has an invalid shape.');
    }

    if (is_array($mapping)) {
      $mappedId = $mapping['node_id'] ?? NULL;
      $mappedHash = $mapping['payload_sha256'] ?? NULL;
      if (!is_int($mappedId) || !is_string($mappedHash)) {
        throw new RuntimeException('Editorial idempotence mapping is incomplete.');
      }
      if ($mappedHash !== $payloadSha) {
        throw new RuntimeException('Issue already maps to a different editorial payload hash.');
      }
      $mapped = $storage->load($mappedId);
      if (!$mapped instanceof NodeInterface || $mapped->bundle() !== self::BUNDLE) {
        throw new RuntimeException('Editorial idempotence mapping points to an invalid node.');
      }
      if ($mapped->label() !== $payload[self::SOURCE_LANGCODE]['title']) {
        throw new RuntimeException('Mapped Article title no longer matches the authorized payload.');
      }
      if (!$mapped->hasTranslation(self::TRANSLATION_LANGCODE)) {
        throw new RuntimeException('Mapped Article no longer has the required EN translation.');
      }
      return ['verdict' => 'IDEMPOTENT', 'node_id' => $mappedId];
    }

    $candidateIds = $storage->getQuery()
      ->condition('type', self::BUNDLE)
      ->condition('langcode', self::SOURCE_LANGCODE)
      ->condition('title', $payload[self::SOURCE_LANGCODE]['title'])
      ->accessCheck(FALSE)
      ->execute();

    if ($candidateIds !== []) {
      throw new RuntimeException(
        'An Article with the exact FR title already exists without an issue mapping; refusing ambiguous creation.',
      );
    }

    return ['verdict' => 'READY', 'node_id' => NULL];
  }

  private function nodeResult(NodeInterface $node): array {
    $source = '/node/' . $node->id();
    $this->aliasManager->cacheClear($source);
    $en = $node->getTranslation(self::TRANSLATION_LANGCODE);

    return [
      'id' => (int) $node->id(),
      'uuid' => $node->uuid(),
      'revision_id' => (int) $node->getRevisionId(),
      'published' => $node->isPublished(),
      'title_fr' => $node->label(),
      'title_en' => $en->label(),
      'category_tid' => (int) $node->get('field_blog_category')->target_id,
      'aliases' => [
        'fr' => $this->aliasManager->getAliasByPath($source, self::SOURCE_LANGCODE),
        'en' => $this->aliasManager->getAliasByPath($source, self::TRANSLATION_LANGCODE),
      ],
    ];
  }

  private function categoryResult(TermInterface $term): array {
    return [
      'tid' => (int) $term->id(),
      'name' => $term->label(),
    ];
  }

  private function assertExactKeys(array $value, array $expected, string $label): void {
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    if ($actual !== $expected) {
      throw new InvalidArgumentException(
        sprintf('%s keys must be exactly: %s.', $label, implode(', ', $expected)),
      );
    }
  }

  private function assertPlainText(mixed $value, string $label, int $maxLength): void {
    if (!is_string($value)) {
      throw new InvalidArgumentException("$label must be a string.");
    }
    $normalized = $this->normalizePlainText($value);
    if ($normalized === '') {
      throw new InvalidArgumentException("$label must not be empty.");
    }
    if ($normalized !== $value) {
      throw new InvalidArgumentException("$label must be trimmed plain text without repeated whitespace.");
    }
    if (mb_strlen($value) > $maxLength) {
      throw new InvalidArgumentException("$label is too long.");
    }
    if (strip_tags($value) !== $value || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $value)) {
      throw new InvalidArgumentException("$label contains forbidden markup or control characters.");
    }
  }

  private function normalizePlainText(string $value): string {
    return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
  }

  private function assertBodyHtml(mixed $value, string $label): void {
    if (!is_string($value) || trim($value) === '') {
      throw new InvalidArgumentException("$label must be non-empty HTML.");
    }
    if (strlen($value) > 100000) {
      throw new InvalidArgumentException("$label is too large.");
    }

    $document = Html::load($value);
    foreach ($document->getElementsByTagName('*') as $element) {
      $tag = strtolower($element->tagName);
      if (in_array($tag, ['html', 'body'], TRUE)) {
        continue;
      }
      if (!in_array($tag, self::ALLOWED_BODY_TAGS, TRUE)) {
        throw new InvalidArgumentException("$label contains forbidden tag <$tag>.");
      }

      $allowedAttributes = $tag === 'a' ? ['href'] : [];
      foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
        if (!in_array(strtolower($attribute->name), $allowedAttributes, TRUE)) {
          throw new InvalidArgumentException(
            "$label contains forbidden attribute {$attribute->name} on <$tag>.",
          );
        }
      }

      if ($tag === 'a') {
        $href = $element->getAttribute('href');
        $allowedHref = str_starts_with($href, '/')
          && !str_starts_with($href, '//');
        $allowedHref = $allowedHref || str_starts_with(
          $href,
          'https://emergingdigital.be/',
        );
        if (!$allowedHref) {
          throw new InvalidArgumentException(
            "$label contains an external or unsafe link outside the bounded Agency contract.",
          );
        }
      }
    }
  }

}

if (!defined('AGENCY_EDITORIAL_LIBRARY_ONLY')) {
  $mode = getenv('AGENCY_EDITORIAL_MODE') ?: '';
  $issueRaw = getenv('AGENCY_EDITORIAL_ISSUE') ?: '';
  $payloadSha = getenv('AGENCY_EDITORIAL_PAYLOAD_SHA') ?: '';
  $payloadPath = getenv('AGENCY_EDITORIAL_PAYLOAD_PATH') ?: '';
  $resultPath = getenv('AGENCY_EDITORIAL_RESULT_PATH') ?: '';

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

    $publisher = AgencyEditorialPublication::fromContainer(\Drupal::getContainer());
    if ($mode === 'inspect') {
      $result = $publisher->inspect($issueNumber);
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
      $result = $mode === 'dry-run'
        ? $publisher->dryRun($payload, $issueNumber, $payloadSha)
        : $publisher->apply($payload, $issueNumber, $payloadSha);
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

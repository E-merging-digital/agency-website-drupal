<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the bounded Article and Service PREPROD candidate extensions.
 *
 * @group agency_project_tests
 * @group governed_editorial_preprod_candidate
 */
#[Group('governed_editorial_preprod_candidate')]
final class GovernedEditorialPreprodCandidateKernelTest extends KernelTestBase {

  /**
   * Drupal modules required by the bounded candidate Kernel tests.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'taxonomy',
    'language',
    'content_translation',
    'path',
    'path_alias',
  ];

  /**
   * Article candidate under test.
   */
  private object $candidate;

  /**
   * Service candidate under test.
   */
  private object $serviceCandidate;

  /**
   * Existing Blog category fixture identifier.
   */
  private int $categoryTid;

  /**
   * Builds the minimal Drupal Article + Service runtime.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter']);

    foreach (['fr', 'en'] as $langcode) {
      if (ConfigurableLanguage::load($langcode) === NULL) {
        ConfigurableLanguage::createFromLangcode($langcode)->save();
      }
    }

    NodeType::create([
      'type' => 'article',
      'name' => 'Article',
      'new_revision' => TRUE,
    ])->save();
    NodeType::create([
      'type' => 'service',
      'name' => 'Service',
      'new_revision' => TRUE,
    ])->save();
    Vocabulary::create([
      'vid' => 'blog_categories',
      'name' => 'Blog categories',
    ])->save();
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => [],
    ])->save();
    User::create([
      'uid' => 1,
      'name' => 'agency-editorial-preprod-test-admin',
      'status' => 1,
    ])->save();

    $this->createArticleFields();
    $this->createServiceFields();
    $translationManager = $this->container->get('content_translation.manager');
    $translationManager->setEnabled('node', 'article', TRUE);
    $translationManager->setEnabled('node', 'service', TRUE);

    $category = Term::create([
      'vid' => 'blog_categories',
      'langcode' => 'fr',
      'name' => 'Drupal',
    ]);
    $category->addTranslation('en', ['name' => 'Drupal']);
    $category->save();
    $this->categoryTid = (int) $category->id();

    if (!class_exists('AgencyEditorialPublication', FALSE)) {
      if (!defined('AGENCY_EDITORIAL_LIBRARY_ONLY')) {
        define('AGENCY_EDITORIAL_LIBRARY_ONLY', TRUE);
      }
      require_once dirname(DRUPAL_ROOT)
        . '/scripts/runner/editorial-publication.php';
    }
    if (!class_exists('AgencyEditorialPreprodCandidate', FALSE)) {
      require_once dirname(DRUPAL_ROOT)
        . '/scripts/runner/editorial-preprod-candidate.php';
    }
    if (!class_exists('AgencyEditorialServicePreprodCandidate', FALSE)) {
      require_once dirname(DRUPAL_ROOT)
        . '/scripts/runner/editorial-service-preprod-candidate.php';
    }

    $articleFactory = ['AgencyEditorialPreprodCandidate', 'fromContainer'];
    $serviceFactory = [
      'AgencyEditorialServicePreprodCandidate',
      'fromContainer',
    ];
    if (!is_callable($articleFactory) || !is_callable($serviceFactory)) {
      throw new \RuntimeException(
        'PREPROD editorial candidate helper factory did not load.',
      );
    }
    $this->candidate = $articleFactory($this->container);
    $this->serviceCandidate = $serviceFactory($this->container);
  }

  /**
   * First Article create reuses #576 and exact replay creates no revision.
   */
  public function testCreateAndExactReplayAreIdempotent(): void {
    $payload = $this->validPayload();
    $hash = str_repeat('a', 64);

    $dryRun = $this->candidate->dryRun($payload, 958, $hash);
    self::assertSame('READY', $dryRun['verdict']);
    self::assertSame('PREPROD', $dryRun['target']);
    self::assertSame('NONE', $dryRun['prod_write']);

    $applied = $this->candidate->apply($payload, 958, $hash);
    self::assertSame('APPLIED', $applied['verdict']);
    self::assertSame('agency-article-958', $applied['candidate_id']);
    $node = Node::load($applied['node']['id']);
    self::assertNotNull($node);
    self::assertTrue($node->hasTranslation('en'));
    $revision = (int) $node->getRevisionId();

    $replay = $this->candidate->apply($payload, 958, $hash);
    self::assertSame('IDEMPOTENT', $replay['verdict']);
    $reloaded = Node::load($applied['node']['id']);
    self::assertNotNull($reloaded);
    self::assertSame($revision, (int) $reloaded->getRevisionId());
  }

  /**
   * Changed Article payload updates the same PREPROD node with a new revision.
   */
  public function testChangedPayloadUpdatesSameCandidateWithNewRevision(): void {
    $first = $this->validPayload();
    $firstHash = str_repeat('b', 64);
    $created = $this->candidate->apply($first, 958, $firstHash);
    $nodeId = (int) $created['node']['id'];
    $firstRevision = (int) $created['node']['revision_id'];

    $changed = $first;
    $changed['fr']['title'] = 'Drupal 10 : préparer Drupal 11';
    $changed['fr']['body_html'] = '<h2>Préparer</h2><p>Nouveau contenu relu.</p>';
    $changed['en']['title'] = 'Drupal 10: prepare for Drupal 11';
    $changed['en']['body_html'] = '<h2>Prepare</h2><p>New reviewed content.</p>';
    $changedHash = str_repeat('c', 64);

    $dryRun = $this->candidate->dryRun($changed, 958, $changedHash);
    self::assertSame('UPDATE_READY', $dryRun['verdict']);
    self::assertSame(
      $firstHash,
      $dryRun['previous_payload_sha256'],
    );
    self::assertSame($nodeId, $dryRun['node']['id']);

    $updated = $this->candidate->apply($changed, 958, $changedHash);
    self::assertSame('UPDATED', $updated['verdict']);
    self::assertSame($nodeId, $updated['node']['id']);
    self::assertSame(
      $firstHash,
      $updated['previous_payload_sha256'],
    );
    self::assertGreaterThan(
      $firstRevision,
      (int) $updated['node']['revision_id'],
    );

    $node = Node::load($nodeId);
    self::assertNotNull($node);
    self::assertSame('Drupal 10 : préparer Drupal 11', $node->label());
    self::assertSame(
      'Drupal 10: prepare for Drupal 11',
      $node->getTranslation('en')->label(),
    );
    $mapping = $this->container
      ->get('state')
      ->get('agency_editorial.issue.958');
    self::assertSame($nodeId, $mapping['node_id']);
    self::assertSame($changedHash, $mapping['payload_sha256']);
  }

  /**
   * The reused #576 schema still refuses other bundles and arbitrary fields.
   */
  public function testClosedArticleContractIsStillAuthoritative(): void {
    $wrongBundle = $this->validPayload();
    $wrongBundle['bundle'] = 'page';
    try {
      $this->candidate->dryRun(
        $wrongBundle,
        958,
        str_repeat('d', 64),
      );
      self::fail('A non-Article bundle was accepted.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString(
        'Only bundle=article',
        $exception->getMessage(),
      );
    }

    $extra = $this->validPayload();
    $extra['arbitrary_field'] = 'forbidden';
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('payload keys must be exactly');
    $this->candidate->dryRun($extra, 958, str_repeat('e', 64));
  }

  /**
   * Candidate update may reference only an existing Blog category pair.
   */
  public function testChangedCandidateStillRequiresExistingCategory(): void {
    $first = $this->validPayload();
    $this->candidate->apply($first, 958, str_repeat('f', 64));

    $changed = $first;
    $changed['fr']['body_html'] = '<p>Changed.</p>';
    $changed['category'] = ['tid' => 999999, 'name' => 'Missing'];
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage(
      'Selected Blog category does not exist in PREPROD.',
    );
    $this->candidate->dryRun($changed, 958, str_repeat('1', 64));
  }

  /**
   * Service V2 creates exactly one FR+EN Service with Drupal stored aliases.
   */
  public function testServiceCandidateCreatesFrEnAndReplayIsIdempotent(): void {
    $payload = $this->validServicePayload();
    $hash = str_repeat('2', 64);

    $dryRun = $this->serviceCandidate->dryRun($payload, 1117, $hash);
    self::assertSame('CREATE_READY', $dryRun['verdict']);
    self::assertSame('agency-service-1117', $dryRun['candidate_id']);
    self::assertSame('GIT_MAIN_FILE', $dryRun['candidate_store']);
    self::assertSame('NONE', $dryRun['prod_write']);

    $applied = $this->serviceCandidate->apply($payload, 1117, $hash);
    self::assertSame('APPLIED', $applied['verdict']);
    self::assertSame(
      $payload['public_routes'],
      $applied['node']['public_routes'],
    );
    self::assertSame(
      $payload['stored_aliases'],
      $applied['node']['stored_aliases'],
    );
    $node = Node::load($applied['node']['id']);
    self::assertNotNull($node);
    self::assertSame('service', $node->bundle());
    self::assertSame('Audit de site web', $node->label());
    self::assertTrue($node->hasTranslation('en'));
    self::assertSame(
      'Website audit',
      $node->getTranslation('en')->label(),
    );
    $revision = (int) $node->getRevisionId();

    $actual = $this->serviceAliases((int) $node->id());
    self::assertSame('/audit-site-web', $actual['fr'] ?? NULL);
    self::assertSame('/website-audit', $actual['en'] ?? NULL);
    self::assertNotContains('/fr/audit-site-web', $actual);
    self::assertNotContains('/en/website-audit', $actual);

    $replay = $this->serviceCandidate->apply($payload, 1117, $hash);
    self::assertSame('IDEMPOTENT', $replay['verdict']);
    $reloaded = Node::load($node->id());
    self::assertNotNull($reloaded);
    self::assertSame($revision, (int) $reloaded->getRevisionId());
  }

  /**
   * Repairs the #1117 legacy prefixed aliases without replacing its node.
   */
  public function testServiceCandidateRepairsLegacyAliasesOnly(): void {
    $payload = $this->validServicePayload();
    $legacyHash = str_repeat('6', 64);
    $fixedHash = str_repeat('7', 64);

    $node = Node::create([
      'type' => 'service',
      'langcode' => 'fr',
      'uid' => 1,
      'status' => TRUE,
      'title' => $payload['fr']['title'],
      'field_short_description' => [
        [
          'value' => $payload['fr']['short_description'],
          'format' => 'basic_html',
        ],
      ],
      'field_detailed_description' => [
        [
          'value' => $payload['fr']['detailed_description_html'],
          'format' => 'basic_html',
        ],
      ],
      'path' => [
        'alias' => $payload['public_routes']['fr'],
        'pathauto' => 0,
      ],
    ]);
    $node->addTranslation('en', [
      'title' => $payload['en']['title'],
      'status' => TRUE,
      'field_short_description' => [
        [
          'value' => $payload['en']['short_description'],
          'format' => 'basic_html',
        ],
      ],
      'field_detailed_description' => [
        [
          'value' => $payload['en']['detailed_description_html'],
          'format' => 'basic_html',
        ],
      ],
      'path' => [
        'alias' => $payload['public_routes']['en'],
        'pathauto' => 0,
      ],
    ]);
    $node->setNewRevision(TRUE);
    $node->save();

    $nodeId = (int) $node->id();
    $revisionId = (int) $node->getRevisionId();
    $contentBefore = $this->serviceContent($node);
    $this->container->get('state')->set(
      'agency_editorial.service.issue.1117',
      [
        'node_id' => $nodeId,
        'payload_sha256' => $legacyHash,
      ],
    );

    self::assertEquals(
      $payload['public_routes'],
      $this->serviceAliases($nodeId),
    );

    $dryRun = $this->serviceCandidate->dryRun(
      $payload,
      1117,
      $fixedHash,
    );
    self::assertSame('ALIAS_REPAIR_READY', $dryRun['verdict']);
    self::assertSame($nodeId, $dryRun['node']['id']);
    self::assertSame($revisionId, $dryRun['node']['revision_id']);

    $repaired = $this->serviceCandidate->apply(
      $payload,
      1117,
      $fixedHash,
    );
    self::assertSame('REPAIRED', $repaired['verdict']);
    self::assertSame($nodeId, $repaired['node']['id']);
    self::assertSame($revisionId, $repaired['node']['revision_id']);

    $reloaded = Node::load($nodeId);
    self::assertNotNull($reloaded);
    self::assertSame($contentBefore, $this->serviceContent($reloaded));
    self::assertEquals(
      $payload['stored_aliases'],
      $this->serviceAliases($nodeId),
    );
    self::assertNotContains(
      $payload['public_routes']['fr'],
      $this->serviceAliases($nodeId),
    );
    self::assertNotContains(
      $payload['public_routes']['en'],
      $this->serviceAliases($nodeId),
    );

    $mapping = $this->container
      ->get('state')
      ->get('agency_editorial.service.issue.1117');
    self::assertSame($nodeId, $mapping['node_id']);
    self::assertSame($fixedHash, $mapping['payload_sha256']);
  }

  /**
   * Service V2 rejects non-service bundles, missing fields and route aliases.
   */
  public function testServiceCandidateContractIsClosedAndRequiresFrEn(): void {
    $wrongBundle = $this->validServicePayload();
    $wrongBundle['bundle'] = 'page';
    $this->assertServiceRejected($wrongBundle, 'Only bundle=service');

    $missingEnglish = $this->validServicePayload();
    unset($missingEnglish['en']);
    $this->assertServiceRejected(
      $missingEnglish,
      'closed V2 schema',
    );

    $missingField = $this->validServicePayload();
    unset($missingField['fr']['detailed_description_html']);
    $this->assertServiceRejected(
      $missingField,
      'fields must be exactly',
    );

    $missingAlias = $this->validServicePayload();
    unset($missingAlias['stored_aliases']['en']);
    $this->assertServiceRejected(
      $missingAlias,
      'stored_aliases must contain exactly FR and EN',
    );

    $doublePrefix = $this->validServicePayload();
    $doublePrefix['stored_aliases']['fr'] = '/fr/audit-site-web';
    $this->assertServiceRejected(
      $doublePrefix,
      'stored alias must omit the Drupal language prefix',
    );
  }

  /**
   * Service V2 fails closed rather than mutating a different candidate hash.
   */
  public function testServiceCandidateDifferentHashFailsClosed(): void {
    $payload = $this->validServicePayload();
    $this->serviceCandidate->apply(
      $payload,
      1117,
      str_repeat('3', 64),
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('different payload hash');
    $this->serviceCandidate->dryRun(
      $payload,
      1117,
      str_repeat('4', 64),
    );
  }

  /**
   * Creates the Article fields required by the reused #576 contract.
   */
  private function createArticleFields(): void {
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Content',
      'translatable' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_short_description',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_short_description',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Short description',
      'translatable' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_blog_category',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'taxonomy_term'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_blog_category',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Blog category',
      'translatable' => TRUE,
      'settings' => [
        'handler' => 'default:taxonomy_term',
        'handler_settings' => [
          'target_bundles' => [
            'blog_categories' => 'blog_categories',
          ],
        ],
      ],
    ])->save();
  }

  /**
   * Creates the Service fields required by #1120.
   */
  private function createServiceFields(): void {
    FieldConfig::create([
      'field_name' => 'field_short_description',
      'entity_type' => 'node',
      'bundle' => 'service',
      'label' => 'Short description',
      'translatable' => TRUE,
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_detailed_description',
      'entity_type' => 'node',
      'type' => 'text_long',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_detailed_description',
      'entity_type' => 'node',
      'bundle' => 'service',
      'label' => 'Detailed description',
      'translatable' => TRUE,
    ])->save();
  }

  /**
   * Returns one valid FR/EN Article payload fixture.
   */
  private function validPayload(): array {
    return [
      'schema_version' => 1,
      'issue_number' => 958,
      'bundle' => 'article',
      'published' => TRUE,
      'category' => [
        'tid' => $this->categoryTid,
        'name' => 'Drupal',
      ],
      'fr' => [
        'title' => 'Drupal 10 arrive en fin de support',
        'short_description' => 'Préparer le passage vers Drupal 11.',
        'body_html' => '<h2>Préparer</h2><p>Auditer avant de mettre à niveau.</p>',
      ],
      'en' => [
        'title' => 'Drupal 10 reaches end of life',
        'short_description' => 'Prepare the upgrade to Drupal 11.',
        'body_html' => '<h2>Prepare</h2><p>Audit before upgrading.</p>',
      ],
    ];
  }

  /**
   * Returns one valid FR/EN Service payload fixture.
   */
  private function validServicePayload(): array {
    return [
      'schema_version' => 2,
      'issue_number' => 1117,
      'bundle' => 'service',
      'published' => TRUE,
      'public_routes' => [
        'fr' => '/fr/audit-site-web',
        'en' => '/en/website-audit',
      ],
      'stored_aliases' => [
        'fr' => '/audit-site-web',
        'en' => '/website-audit',
      ],
      'fr' => [
        'title' => 'Audit de site web',
        'short_description' => 'Clarifier les priorités.',
        'detailed_description_html' => '<h2>Décider</h2><p>Auditer avant d’investir.</p>',
      ],
      'en' => [
        'title' => 'Website audit',
        'short_description' => 'Clarify priorities.',
        'detailed_description_html' => '<h2>Decide</h2><p>Audit before investing.</p>',
      ],
    ];
  }

  /**
   * Returns one Service's aliases keyed by language.
   *
   * @return string[]
   *   Alias by langcode.
   */
  private function serviceAliases(int $nodeId): array {
    $aliases = $this->container
      ->get('entity_type.manager')
      ->getStorage('path_alias')
      ->loadByProperties(['path' => '/node/' . $nodeId]);
    $actual = [];
    foreach ($aliases as $alias) {
      $actual[$alias->language()->getId()] = $alias->getAlias();
    }
    ksort($actual);
    return $actual;
  }

  /**
   * Returns the commercial Service fields to prove an alias-only repair.
   */
  private function serviceContent(Node $node): array {
    $en = $node->getTranslation('en');
    return [
      'fr' => [
        'title' => $node->label(),
        'short_description' => (string) $node
          ->get('field_short_description')->value,
        'detailed_description_html' => (string) $node
          ->get('field_detailed_description')->value,
        'published' => $node->isPublished(),
      ],
      'en' => [
        'title' => $en->label(),
        'short_description' => (string) $en
          ->get('field_short_description')->value,
        'detailed_description_html' => (string) $en
          ->get('field_detailed_description')->value,
        'published' => $en->isPublished(),
      ],
    ];
  }

  /**
   * Asserts a malformed Service payload fails before any write.
   */
  private function assertServiceRejected(
    array $payload,
    string $message,
  ): void {
    try {
      $this->serviceCandidate->dryRun(
        $payload,
        1117,
        str_repeat('5', 64),
      );
      self::fail('Invalid Service candidate payload was accepted.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString(
        $message,
        $exception->getMessage(),
      );
    }
  }

}

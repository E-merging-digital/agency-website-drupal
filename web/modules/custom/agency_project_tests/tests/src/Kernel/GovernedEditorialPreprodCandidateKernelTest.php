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
 * Proves the Article-only PREPROD candidate extension around #576.
 *
 * @group agency_project_tests
 * @group governed_editorial_preprod_candidate
 */
#[Group('governed_editorial_preprod_candidate')]
final class GovernedEditorialPreprodCandidateKernelTest extends KernelTestBase {

  /**
   * Drupal modules required by the bounded Article candidate Kernel tests.
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
   * PREPROD candidate helper under test.
   */
  private object $candidate;

  /**
   * Existing Blog category fixture identifier.
   */
  private int $categoryTid;

  /**
   * Builds the minimal Drupal Article runtime reused by #576 and #959.
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
    $this->container
      ->get('content_translation.manager')
      ->setEnabled('node', 'article', TRUE);

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
      require_once dirname(DRUPAL_ROOT) . '/scripts/runner/editorial-publication.php';
    }
    if (!class_exists('AgencyEditorialPreprodCandidate', FALSE)) {
      require_once dirname(DRUPAL_ROOT) . '/scripts/runner/editorial-preprod-candidate.php';
    }
    $factory = ['AgencyEditorialPreprodCandidate', 'fromContainer'];
    if (!is_callable($factory)) {
      throw new \RuntimeException(
        'PREPROD editorial candidate helper factory did not load.',
      );
    }
    $this->candidate = $factory($this->container);
  }

  /**
   * First create reuses #576 and exact replay creates no extra revision.
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
   * Changed editorial content changes the hash and updates the same PREPROD
   * node.
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
    self::assertSame($firstHash, $dryRun['previous_payload_sha256']);
    self::assertSame($nodeId, $dryRun['node']['id']);

    $updated = $this->candidate->apply($changed, 958, $changedHash);
    self::assertSame('UPDATED', $updated['verdict']);
    self::assertSame($nodeId, $updated['node']['id']);
    self::assertSame($firstHash, $updated['previous_payload_sha256']);
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
    $mapping = $this->container->get('state')->get('agency_editorial.issue.958');
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
      $this->candidate->dryRun($wrongBundle, 958, str_repeat('d', 64));
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
          'target_bundles' => ['blog_categories' => 'blog_categories'],
        ],
      ],
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

}

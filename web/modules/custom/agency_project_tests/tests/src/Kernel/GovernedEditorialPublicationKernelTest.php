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
 * Exercises the trusted Article mutation helper against a real Drupal kernel.
 *
 * @group agency_project_tests
 * @group governed_editorial_publication
 */
#[Group('governed_editorial_publication')]
final class GovernedEditorialPublicationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
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
   * Trusted editorial publisher under test.
   */
  private object $publisher;

  /**
   * Existing Blog category term ID used by the payload.
   */
  private int $categoryTid;

  /**
   * Sentinel node ID used to prove no unrelated content changes.
   */
  private int $sentinelNid;

  /**
   * Sentinel revision ID captured before publication.
   */
  private int $sentinelRevisionId;

  /**
   * {@inheritdoc}
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
      'name' => 'agency-editorial-test-admin',
      'status' => 1,
    ])->save();

    $this->createArticleFields();
    $this->container
      ->get('content_translation.manager')
      ->setEnabled('node', 'article', TRUE);

    $category = Term::create([
      'vid' => 'blog_categories',
      'langcode' => 'fr',
      'name' => 'Conseil',
    ]);
    $category->addTranslation('en', ['name' => 'Consulting']);
    $category->save();
    $this->categoryTid = (int) $category->id();

    $sentinel = Node::create([
      'type' => 'article',
      'langcode' => 'fr',
      'title' => 'Sentinel',
      'uid' => 1,
      'status' => 1,
      'field_short_description' => [
        [
          'value' => 'Unchanged sentinel',
          'format' => 'basic_html',
        ],
      ],
      'body' => [
        [
          'value' => '<p>Sentinel body.</p>',
          'format' => 'basic_html',
        ],
      ],
      'field_blog_category' => [['target_id' => $this->categoryTid]],
    ]);
    $sentinel->save();
    $this->sentinelNid = (int) $sentinel->id();
    $this->sentinelRevisionId = (int) $sentinel->getRevisionId();

    $class = 'AgencyEditorialPublication';
    if (!class_exists($class, FALSE)) {
      if (!defined('AGENCY_EDITORIAL_LIBRARY_ONLY')) {
        define('AGENCY_EDITORIAL_LIBRARY_ONLY', TRUE);
      }
      require_once dirname(DRUPAL_ROOT) . '/scripts/runner/editorial-publication.php';
    }
    $method = (new \ReflectionClass($class))->getMethod('fromContainer');
    $publisher = $method->invoke(NULL, $this->container);
    self::assertIsObject($publisher);
    $this->publisher = $publisher;
  }

  /**
   * Creates FR+EN once and becomes idempotent for the same issue/hash.
   */
  public function testApplyCreatesTranslatedArticleAndIsIdempotent(): void {
    $payload = $this->validPayload();
    $hash = str_repeat('a', 64);

    $dryRun = $this->publisher->dryRun($payload, 401, $hash);
    self::assertSame('PASS', $dryRun['status']);
    self::assertSame('READY', $dryRun['verdict']);

    $applied = $this->publisher->apply($payload, 401, $hash);
    self::assertSame('PASS', $applied['status']);
    self::assertSame('APPLIED', $applied['verdict']);
    self::assertSame($this->categoryTid, $applied['node']['category_tid']);
    self::assertGreaterThan(0, $applied['node']['revision_id']);

    $node = Node::load($applied['node']['id']);
    self::assertNotNull($node);
    self::assertSame('Checklist avant une refonte', $node->label());
    self::assertTrue($node->hasTranslation('en'));
    self::assertSame(
      'Website redesign checklist',
      $node->getTranslation('en')->label(),
    );
    self::assertSame('basic_html', $node->get('body')->format);
    self::assertSame(
      'basic_html',
      $node->getTranslation('en')->get('body')->format,
    );

    $mapping = $this->container->get('state')->get('agency_editorial.issue.401');
    self::assertSame($applied['node']['id'], $mapping['node_id']);
    self::assertSame($hash, $mapping['payload_sha256']);

    $idempotent = $this->publisher->apply($payload, 401, $hash);
    self::assertSame('PASS', $idempotent['status']);
    self::assertSame('IDEMPOTENT', $idempotent['verdict']);
    self::assertSame($applied['node']['id'], $idempotent['node']['id']);

    $sentinel = Node::load($this->sentinelNid);
    self::assertNotNull($sentinel);
    self::assertSame('Sentinel', $sentinel->label());
    self::assertSame($this->sentinelRevisionId, (int) $sentinel->getRevisionId());
  }

  /**
   * Category selection is restricted to an existing Blog taxonomy term.
   */
  public function testMissingCategoryFailsClosed(): void {
    $payload = $this->validPayload();
    $payload['category'] = ['tid' => 999999, 'name' => 'Missing'];

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Selected Blog category does not exist.');
    $this->publisher->dryRun($payload, 401, str_repeat('b', 64));
  }

  /**
   * The schema refuses another bundle or an injected text-format field.
   */
  public function testPayloadCannotWidenBundleOrTextFormat(): void {
    $payload = $this->validPayload();
    $payload['bundle'] = 'page';

    try {
      $this->publisher->dryRun($payload, 401, str_repeat('c', 64));
      self::fail('A non-Article bundle was accepted.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('Only bundle=article', $exception->getMessage());
    }

    $payload = $this->validPayload();
    $payload['body_format'] = 'full_html';
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('payload keys must be exactly');
    $this->publisher->dryRun($payload, 401, str_repeat('d', 64));
  }

  /**
   * A title collision without the technical mapping must never create a clone.
   */
  public function testUnmappedExactTitleCollisionFailsClosed(): void {
    $payload = $this->validPayload();
    Node::create([
      'type' => 'article',
      'langcode' => 'fr',
      'title' => $payload['fr']['title'],
      'uid' => 1,
      'status' => 1,
    ])->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('exact FR title already exists');
    $this->publisher->dryRun($payload, 401, str_repeat('e', 64));
  }

  /**
   * Creates the exact fields the trusted helper may mutate.
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
   * Returns a payload that conforms to the v1 closed schema.
   */
  private function validPayload(): array {
    return [
      'schema_version' => 1,
      'issue_number' => 401,
      'bundle' => 'article',
      'published' => TRUE,
      'category' => [
        'tid' => $this->categoryTid,
        'name' => 'Conseil',
      ],
      'fr' => [
        'title' => 'Checklist avant une refonte',
        'short_description' => 'Préparer une refonte avant la maquette.',
        'body_html' => '<h2>Préparer le projet</h2><p>Conserver ce qui fonctionne.</p><dl><dt>Audit</dt><dd>Prioriser les risques.</dd></dl><p><a href="/contact">Contact</a></p>',
      ],
      'en' => [
        'title' => 'Website redesign checklist',
        'short_description' => 'Prepare a redesign before the mockups.',
        'body_html' => '<h2>Prepare the project</h2><p>Keep what already works.</p><dl><dt>Audit</dt><dd>Prioritise risks.</dd></dl><p><a href="/contact">Contact</a></p>',
      ],
    ];
  }

}

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
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the closed #1012 Page candidate through real Drupal Entity APIs.
 *
 * @group agency_project_tests
 * @group drupal_2027_preprod_candidate
 */
#[Group('drupal_2027_preprod_candidate')]
final class Drupal2027PreprodCandidateKernelTest extends KernelTestBase {

  /**
   * Drupal modules required by the closed Drupal 2027 candidate Kernel test.
   *
   * @var string[]
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'node',
    'language',
    'path',
    'path_alias',
    'link',
    'entity_reference_revisions',
    'paragraphs',
  ];

  /**
   * Closed PREPROD candidate helper under test.
   */
  private object $candidate;

  /**
   * Builds the minimal Page and Paragraph runtime used by the closed profile.
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter']);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    NodeType::create([
      'type' => 'page',
      'name' => 'Page',
      'new_revision' => TRUE,
    ])->save();
    foreach (['hero', 'text_block', 'trust_list'] as $bundle) {
      ParagraphsType::create([
        'id' => $bundle,
        'label' => $bundle,
      ])->save();
    }
    FilterFormat::create([
      'format' => 'basic_html',
      'name' => 'Basic HTML',
      'filters' => [],
    ])->save();
    User::create([
      'uid' => 1,
      'name' => 'drupal-2027-preprod-test-admin',
      'status' => 1,
    ])->save();

    $this->createFields();

    if (!class_exists('AgencyDrupal2027PreprodCandidate', FALSE)) {
      require_once dirname(DRUPAL_ROOT) . '/scripts/runner/drupal-2027-preprod-candidate.php';
    }
    $factory = ['AgencyDrupal2027PreprodCandidate', 'fromContainer'];
    self::assertIsCallable($factory);
    $this->candidate = $factory($this->container);
  }

  /**
   * First apply creates one Page; exact replay creates no new revision.
   */
  public function testCreateAndExactReplayAreIdempotent(): void {
    $payload = $this->validPayload();
    $hash = str_repeat('a', 64);

    $dryRun = $this->candidate->dryRun($payload, $hash);
    self::assertSame('CREATE_READY', $dryRun['verdict']);
    self::assertSame('PREPROD', $dryRun['target']);
    self::assertSame('page', $dryRun['bundle']);
    self::assertSame('fr', $dryRun['language']);
    self::assertSame('/fr/drupal-2027', $dryRun['alias']);
    self::assertSame('NONE', $dryRun['prod_write']);

    $applied = $this->candidate->apply($payload, $hash);
    self::assertSame('APPLIED', $applied['verdict']);
    self::assertSame('agency-drupal-2027-landing-1012', $applied['candidate_id']);
    self::assertIsArray($applied['node']);

    $node = Node::load($applied['node']['id']);
    self::assertNotNull($node);
    self::assertSame('page', $node->bundle());
    self::assertSame('fr', $node->language()->getId());
    self::assertFalse($node->hasTranslation('en'));
    self::assertSame($payload['title'], $node->label());
    self::assertSame($payload['short_description'], $node->get('field_short_description')->value);
    $components = $node->get('field_home_components')->referencedEntities();
    self::assertCount(10, $components);
    $secondary = $components[0]->get('field_secondary_link')->first()?->getValue() ?? [];
    self::assertSame(
      'internal:/drupal-2027#points-a-verifier-socle',
      $secondary['uri'] ?? NULL,
    );
    self::assertSame(
      '/drupal-2027',
      $this->container->get('path_alias.manager')->getAliasByPath(
        '/node/' . $node->id(),
        'fr',
      ),
    );
    $revision = (int) $node->getRevisionId();

    $replay = $this->candidate->apply($payload, $hash);
    self::assertSame('IDEMPOTENT', $replay['verdict']);
    $reloaded = Node::load($node->id());
    self::assertNotNull($reloaded);
    self::assertSame($revision, (int) $reloaded->getRevisionId());
  }

  /**
   * The exact alias is fail-closed when another Page already owns it.
   */
  public function testAliasCollisionFailsClosed(): void {
    $collision = Node::create([
      'type' => 'page',
      'langcode' => 'fr',
      'title' => 'Existing incompatible page',
      'uid' => 1,
      'status' => TRUE,
      'path' => [
        'alias' => '/drupal-2027',
        'pathauto' => 0,
      ],
    ]);
    $collision->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('collision');
    $this->candidate->dryRun($this->validPayload(), str_repeat('b', 64));
  }

  /**
   * A changed hash cannot silently update an already materialized candidate.
   */
  public function testChangedCandidateHashFailsClosed(): void {
    $payload = $this->validPayload();
    $this->candidate->apply($payload, str_repeat('c', 64));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('different payload hash');
    $this->candidate->dryRun($payload, str_repeat('d', 64));
  }

  /**
   * Arbitrary profile values remain rejected.
   */
  public function testGenericPageParametersAreRejected(): void {
    $payload = $this->validPayload();
    $payload['alias'] = '/fr/arbitrary-page';

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('field alias is fixed');
    $this->candidate->dryRun($payload, str_repeat('e', 64));
  }

  /**
   * Creates the fields required by the bounded Page and Paragraph contract.
   */
  private function createFields(): void {
    $this->createStorage('field_short_description', 'node', 'text_long', 1);
    $this->createConfig('field_short_description', 'node', 'page', 'Short description');

    $this->createStorage(
      'field_home_components',
      'node',
      'entity_reference_revisions',
      -1,
      ['target_type' => 'paragraph'],
    );
    $this->createConfig(
      'field_home_components',
      'node',
      'page',
      'Components',
      [
        'handler' => 'default:paragraph',
        'handler_settings' => [
          'target_bundles' => [
            'hero' => 'hero',
            'text_block' => 'text_block',
            'trust_list' => 'trust_list',
          ],
        ],
      ],
    );

    $this->createStorage('field_heading', 'paragraph', 'string', 1);
    foreach (['hero', 'text_block', 'trust_list'] as $bundle) {
      $this->createConfig('field_heading', 'paragraph', $bundle, 'Heading');
    }

    $this->createStorage('field_text', 'paragraph', 'text_long', 1);
    foreach (['hero', 'text_block'] as $bundle) {
      $this->createConfig('field_text', 'paragraph', $bundle, 'Text');
    }

    $this->createStorage('field_link', 'paragraph', 'link', 1);
    $this->createConfig('field_link', 'paragraph', 'hero', 'Primary link');
    $this->createStorage('field_secondary_link', 'paragraph', 'link', 1);
    $this->createConfig('field_secondary_link', 'paragraph', 'hero', 'Secondary link');

    $this->createStorage('field_items', 'paragraph', 'text_long', -1);
    $this->createConfig('field_items', 'paragraph', 'trust_list', 'Items');
  }

  /**
   * Creates one field storage definition for the Kernel fixture.
   *
   * @param string $name
   *   Field storage name.
   * @param string $entityType
   *   Entity type receiving the field storage.
   * @param string $type
   *   Drupal field type identifier.
   * @param int $cardinality
   *   Field storage cardinality.
   * @param array<string, mixed> $settings
   *   Optional field storage settings.
   */
  private function createStorage(
    string $name,
    string $entityType,
    string $type,
    int $cardinality,
    array $settings = [],
  ): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $settings,
    ])->save();
  }

  /**
   * Creates one bundle field definition for the Kernel fixture.
   *
   * @param string $name
   *   Field name.
   * @param string $entityType
   *   Entity type receiving the bundle field.
   * @param string $bundle
   *   Bundle receiving the field definition.
   * @param string $label
   *   Human-readable field label.
   * @param array<string, mixed> $settings
   *   Optional field handler settings.
   */
  private function createConfig(
    string $name,
    string $entityType,
    string $bundle,
    string $label,
    array $settings = [],
  ): void {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $label,
      'translatable' => TRUE,
      'settings' => $settings,
    ])->save();
  }

  /**
   * Returns one valid closed Drupal 2027 payload fixture.
   *
   * @return array<string, mixed>
   *   Candidate payload fixture.
   */
  private function validPayload(): array {
    return [
      'schema_version' => 1,
      'profile' => 'drupal-2027-landing',
      'candidate_id' => 'agency-drupal-2027-landing-1012',
      'issue_number' => 1012,
      'source_issue' => 1010,
      'source_comment_id' => 5553624200,
      'source_updated_at' => '2026-09-05T17:43:14Z',
      'target' => 'PREPROD',
      'bundle' => 'page',
      'language' => 'fr',
      'alias' => '/fr/drupal-2027',
      'published' => TRUE,
      'title' => 'Votre plateforme Drupal est-elle prête pour 2027 ?',
      'short_description' => 'Description approuvée.',
      'hero' => [
        'submessage' => 'Sous-message approuvé.',
        'primary_cta' => 'Faire le point sur ma plateforme',
        'secondary_cta' => 'Voir ce qu’il faut vérifier',
      ],
      'sections' => [
        'lifecycle' => $this->section('Pourquoi 2027 ?', '<p>Jalons.</p>'),
        'situations' => $this->section('Situations', '<p>Situations.</p>'),
        'checks' => $this->section(
          'Les points à vérifier',
          '<h3 id="points-a-verifier-socle">Socle</h3>',
        ),
        'composer_callout' => $this->section('Composer', '<p>Vérifier.</p>'),
        'method' => $this->section('Méthode', '<h3>1. COMPRENDRE</h3>'),
        'reassurance' => [
          'heading' => 'Diagnostic humain',
          'items' => ['Un', 'Deux', 'Trois', 'Quatre', 'Cinq'],
        ],
        'diagnostic_context' => [
          'body_html' => '<p>Webform existant.</p>',
        ],
        'audit' => $this->section('Audit', '<p>Audit.</p>'),
        'faq' => $this->section('FAQ', '<h3>Question</h3><p>Réponse.</p>'),
      ],
    ];
  }

  /**
   * Builds one text section fixture.
   *
   * @return array{heading: string, body_html: string}
   *   Section fixture.
   */
  private function section(string $heading, string $body): array {
    return [
      'heading' => $heading,
      'body_html' => $body,
    ];
  }

}
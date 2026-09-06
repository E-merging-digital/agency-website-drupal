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
 * Proves the closed #1046 FR+EN Page candidate through Drupal Entity APIs.
 *
 * @group agency_project_tests
 * @group drupal_2027_preprod_candidate
 */
#[Group('drupal_2027_preprod_candidate')]
final class Drupal2027PreprodCandidateKernelTest extends KernelTestBase {

  private const HASH = 'ac96465c5717f78af76e368d8598399cbe997ed63d7cc753d575337c9321af83';

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

  private object $candidate;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'filter']);

    ConfigurableLanguage::createFromLangcode('fr')->save();
    NodeType::create(['type' => 'page', 'name' => 'Page', 'new_revision' => TRUE])->save();
    foreach (['hero', 'text_block', 'trust_list'] as $bundle) {
      ParagraphsType::create(['id' => $bundle, 'label' => $bundle])->save();
    }
    FilterFormat::create(['format' => 'basic_html', 'name' => 'Basic HTML', 'filters' => []])->save();
    User::create(['uid' => 1, 'name' => 'drupal-2027-1046-admin', 'status' => 1])->save();
    $this->createFields();

    require_once dirname(DRUPAL_ROOT) . '/scripts/runner/drupal-2027-preprod-candidate.php';
    $factory = ['AgencyDrupal2027PreprodCandidate', 'fromContainer'];
    self::assertIsCallable($factory);
    $this->candidate = $factory($this->container);
  }

  public function testCreateAndExactReplayAreBilingualAndIdempotent(): void {
    $payload = $this->validPayload();

    $dryRun = $this->candidate->dryRun($payload, self::HASH);
    self::assertSame('CREATE_READY', $dryRun['verdict']);
    self::assertSame('FR_EN', $dryRun['language_mode']);
    self::assertSame('/fr/drupal-2027', $dryRun['aliases']['fr']);
    self::assertSame('/en/drupal-2027', $dryRun['aliases']['en']);

    $applied = $this->candidate->apply($payload, self::HASH);
    self::assertSame('APPLIED', $applied['verdict']);
    self::assertSame('agency-drupal-2027-landing-1046', $applied['candidate_id']);

    $node = Node::load($applied['node']['id']);
    self::assertNotNull($node);
    self::assertSame('fr', $node->language()->getId());
    self::assertSame($payload['fr']['title'], $node->label());
    self::assertTrue($node->hasTranslation('en'));
    self::assertSame($payload['en']['title'], $node->getTranslation('en')->label());

    $components = $node->get('field_home_components')->referencedEntities();
    self::assertCount(10, $components);
    foreach ($components as $paragraph) {
      self::assertTrue($paragraph->hasTranslation('en'));
    }

    $aliasManager = $this->container->get('path_alias.manager');
    $aliasManager->cacheClear('/node/' . $node->id());
    self::assertSame('/drupal-2027', $aliasManager->getAliasByPath('/node/' . $node->id(), 'fr'));
    self::assertSame('/drupal-2027', $aliasManager->getAliasByPath('/node/' . $node->id(), 'en'));

    $revision = (int) $node->getRevisionId();
    $replay = $this->candidate->apply($payload, self::HASH);
    self::assertSame('IDEMPOTENT', $replay['verdict']);
    self::assertSame($revision, (int) Node::load($node->id())?->getRevisionId());
  }

  public function testCandidateIdentityAndSchemaRemainFailClosed(): void {
    $payload = $this->validPayload();
    $payload['language_mode'] = 'FR_ONLY_EXCEPTION_APPROVED';

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('field language_mode is fixed');
    $this->candidate->dryRun($payload, self::HASH);
  }

  private function createFields(): void {
    $this->createStorage('field_short_description', 'node', 'text_long', 1);
    $this->createConfig('field_short_description', 'node', 'page', 'Short description');
    $this->createStorage('field_home_components', 'node', 'entity_reference_revisions', -1, ['target_type' => 'paragraph']);
    $this->createConfig('field_home_components', 'node', 'page', 'Components', [
      'handler' => 'default:paragraph',
      'handler_settings' => ['target_bundles' => ['hero' => 'hero', 'text_block' => 'text_block', 'trust_list' => 'trust_list']],
    ]);
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

  private function createStorage(string $name, string $entityType, string $type, int $cardinality, array $settings = []): void {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'type' => $type,
      'cardinality' => $cardinality,
      'settings' => $settings,
    ])->save();
  }

  private function createConfig(string $name, string $entityType, string $bundle, string $label, array $settings = []): void {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entityType,
      'bundle' => $bundle,
      'label' => $label,
      'translatable' => TRUE,
      'settings' => $settings,
    ])->save();
  }

  private function validPayload(): array {
    return [
      'schema_version' => 2,
      'profile' => 'drupal-2027-landing',
      'candidate_id' => 'agency-drupal-2027-landing-1046',
      'issue_number' => 1046,
      'source_issue' => 1010,
      'source_candidate_revision' => 5553858896,
      'source_candidate_sha256' => '07fb10ab4a54371d877fbfc6b3f185eda41085ae3bd5080de2d695843c9d049e',
      'target' => 'PREPROD',
      'bundle' => 'page',
      'language_mode' => 'FR_EN',
      'aliases' => ['fr' => '/fr/drupal-2027', 'en' => '/en/drupal-2027'],
      'published' => TRUE,
      'fr' => $this->language('Votre plateforme Drupal est-elle prête pour 2027 ?', 'FR'),
      'en' => $this->language('Is your Drupal platform ready for 2027?', 'EN'),
    ];
  }

  private function language(string $title, string $prefix): array {
    $section = static fn (string $heading, string $body): array => ['heading' => $heading, 'body_html' => '<p>' . $body . '</p>'];
    return [
      'title' => $title,
      'short_description' => $prefix . ' description.',
      'hero' => [
        'submessage' => $prefix . ' submessage.',
        'primary_cta' => $prefix . ' primary',
        'secondary_cta' => $prefix . ' secondary',
      ],
      'sections' => [
        'lifecycle' => $section($prefix . ' lifecycle', 'Lifecycle'),
        'situations' => $section($prefix . ' situations', 'Situations'),
        'checks' => ['heading' => $prefix . ' checks', 'body_html' => '<h3 id="points-a-verifier-socle">' . $prefix . ' foundation</h3>'],
        'composer_callout' => $section($prefix . ' composer', 'Composer'),
        'method' => $section($prefix . ' method', 'Method'),
        'reassurance' => [
          'heading' => $prefix . ' reassurance',
          'items' => [$prefix . ' one', $prefix . ' two', $prefix . ' three', $prefix . ' four', $prefix . ' five'],
        ],
        'diagnostic_context' => ['body_html' => '<p>' . $prefix . ' diagnostic</p>'],
        'audit' => $section($prefix . ' audit', 'Audit'),
        'faq' => $section($prefix . ' FAQ', 'FAQ'),
      ],
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers alias-only repair for an already mapped editorial Article.
 *
 * @group agency_project_tests
 * @group governed_editorial_publication
 */
#[Group('governed_editorial_publication')]
final class GovernedEditorialPathautoKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'node',
    'taxonomy',
    'language',
    'content_translation',
    'path',
    'path_alias',
  ];

  /**
   * Missing EN alias is repaired without touching the Article revision.
   */
  public function testMissingEnglishAliasIsRepairedWithoutNodeRevision(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);

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
    $this->container
      ->get('content_translation.manager')
      ->setEnabled('node', 'article', TRUE);

    Vocabulary::create([
      'vid' => 'blog_categories',
      'name' => 'Blog categories',
    ])->save();
    $category = Term::create([
      'vid' => 'blog_categories',
      'langcode' => 'fr',
      'name' => 'Qualité web / SEO / accessibilité',
    ]);
    $category->save();

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

    User::create([
      'uid' => 1,
      'name' => 'agency-editorial-test-admin',
      'status' => 1,
    ])->save();

    $node = Node::create([
      'type' => 'article',
      'langcode' => 'fr',
      'title' => 'Checklist avant une refonte',
      'uid' => 1,
      'status' => 1,
      'field_blog_category' => [['target_id' => (int) $category->id()]],
    ]);
    $node->addTranslation('en', [
      'title' => 'Website redesign checklist',
      'status' => 1,
      'field_blog_category' => [['target_id' => (int) $category->id()]],
    ]);
    $node->save();
    $nodeId = (int) $node->id();
    $revisionId = (int) $node->getRevisionId();

    PathAlias::create([
      'path' => '/node/' . $nodeId,
      'alias' => '/blog/checklist-avant-une-refonte',
      'langcode' => 'fr',
    ])->save();

    $hash = str_repeat('a', 64);
    $this->container->get('state')->set('agency_editorial.issue.401', [
      'node_id' => $nodeId,
      'payload_sha256' => $hash,
    ]);

    if (!defined('AGENCY_EDITORIAL_PATHAUTO_LIBRARY_ONLY')) {
      define('AGENCY_EDITORIAL_PATHAUTO_LIBRARY_ONLY', TRUE);
    }
    require_once dirname(DRUPAL_ROOT) . '/scripts/runner/editorial-publication-pathauto.php';

    $generator = new class {

      /**
       * Languages for which alias generation was requested.
       *
       * @var string[]
       */
      public array $langcodes = [];

      /**
       * Creates the deterministic test alias for the requested translation.
       */
      public function updateEntityAlias(object $entity, string $op, array $options = []): void {
        unset($op, $options);
        $langcode = $entity->language()->getId();
        $this->langcodes[] = $langcode;
        $nodeId = (int) $entity->id();
        $alias = $langcode === 'en'
          ? '/blog/website-redesign-checklist'
          : '/blog/checklist-avant-une-refonte';

        PathAlias::create([
          'path' => '/node/' . $nodeId,
          'alias' => $alias,
          'langcode' => $langcode,
        ])->save();
      }

    };

    $finalizer = new \AgencyEditorialPathautoFinalizer(
      $this->container->get('entity_type.manager'),
      $this->container->get('state'),
      $this->container->get('path_alias.manager'),
      $generator,
    );

    $dryRun = $finalizer->inspect(401, $hash);
    self::assertSame('REPAIR_REQUIRED', $dryRun['verdict']);
    self::assertSame(['en'], $dryRun['aliases_to_repair']);

    $applied = $finalizer->apply(401, $hash);
    self::assertSame('REPAIRED', $applied['verdict']);
    self::assertSame('/blog/checklist-avant-une-refonte', $applied['node']['aliases']['fr']);
    self::assertSame('/blog/website-redesign-checklist', $applied['node']['aliases']['en']);
    self::assertSame(['en'], $generator->langcodes);

    $reloaded = Node::load($nodeId);
    self::assertNotNull($reloaded);
    self::assertSame($revisionId, (int) $reloaded->getRevisionId());
    self::assertSame('Checklist avant une refonte', $reloaded->label());
    self::assertSame('Website redesign checklist', $reloaded->getTranslation('en')->label());

    $idempotent = $finalizer->inspect(401, $hash);
    self::assertSame('IDEMPOTENT', $idempotent['verdict']);
    self::assertSame([], $idempotent['aliases_to_repair']);
  }

}

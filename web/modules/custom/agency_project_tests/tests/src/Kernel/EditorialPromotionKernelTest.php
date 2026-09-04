<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\Core\File\FileExists;
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
 * Proves that an Article stays unpublished until its exact image is complete.
 *
 * @group agency_project_tests
 * @group editorial_promotion_governance
 */
#[Group('editorial_promotion_governance')]
final class EditorialPromotionKernelTest extends KernelTestBase {

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
    'file',
    'image',
  ];

  /**
   * Trusted Article publisher under test.
   */
  private object $publisher;

  /**
   * PREPROD-first finalizer under test.
   */
  private object $promotion;

  /**
   * Existing category term ID.
   */
  private int $categoryTid;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('file');
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

    if (!defined('AGENCY_EDITORIAL_LIBRARY_ONLY')) {
      define('AGENCY_EDITORIAL_LIBRARY_ONLY', TRUE);
    }
    $root = dirname(DRUPAL_ROOT);
    require_once $root . '/scripts/runner/editorial-publication.php';
    require_once $root . '/scripts/runner/editorial-promotion.php';

    $publisherFactory = ['AgencyEditorialPublication', 'fromContainer'];
    $promotionFactory = ['AgencyEditorialPromotion', 'fromContainer'];
    self::assertIsCallable($publisherFactory);
    self::assertIsCallable($promotionFactory);
    $this->publisher = $publisherFactory($this->container);
    $this->promotion = $promotionFactory($this->container);
  }

  /**
   * Missing image blocks publication; the exact image then unlocks promotion.
   */
  public function testPromotionRequiresExactImageBeforePublicState(): void {
    $payload = $this->validPayload();
    $hash = str_repeat('e', 64);
    $staged = $payload;
    $staged['published'] = FALSE;

    $stageResult = $this->publisher->apply($staged, 999, $hash);
    self::assertSame('APPLIED', $stageResult['verdict']);
    $node = Node::load($stageResult['node']['id']);
    self::assertNotNull($node);
    self::assertFalse($node->isPublished());
    self::assertFalse($node->getTranslation('en')->isPublished());

    $bytes = (string) base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlKsAAAAASUVORK5CYII=',
      TRUE,
    );
    $profile = $this->imageProfile($hash, hash('sha256', $bytes));

    try {
      $this->promotion->finalize($payload, $profile, 999, $hash);
      self::fail('A public Article without its approved image was accepted.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('requires one exact FR/EN feature image', $exception->getMessage());
    }

    $fileSystem = $this->container->get('file_system');
    self::assertTrue($fileSystem->prepareDirectory('public://articles', 3));
    $file = $this->container->get('file.repository')->writeData(
      $bytes,
      'public://articles/promotion-test.png',
      FileExists::Replace,
    );
    $file->setPermanent();
    $file->save();

    $node = Node::load($stageResult['node']['id']);
    self::assertNotNull($node);
    $node->set('field_feature_image', [[
      'target_id' => (int) $file->id(),
      'alt' => $profile['alt']['fr'],
    ]]);
    $node->getTranslation('en')->set('field_feature_image', [[
      'target_id' => (int) $file->id(),
      'alt' => $profile['alt']['en'],
    ]]);
    $node->save();

    $promoted = $this->promotion->finalize($payload, $profile, 999, $hash);
    self::assertSame('PASS', $promoted['status']);
    self::assertSame('PROMOTED', $promoted['verdict']);

    $reloaded = Node::load($stageResult['node']['id']);
    self::assertNotNull($reloaded);
    self::assertTrue($reloaded->isPublished());
    self::assertTrue($reloaded->getTranslation('en')->isPublished());

    $idempotent = $this->promotion->finalize($payload, $profile, 999, $hash);
    self::assertSame('IDEMPOTENT', $idempotent['verdict']);
  }

  /**
   * Content drift after approval is refused even when the Article is mapped.
   */
  public function testCandidateDriftIsRefused(): void {
    $payload = $this->validPayload();
    $hash = str_repeat('f', 64);
    $staged = $payload;
    $staged['published'] = FALSE;
    $result = $this->publisher->apply($staged, 999, $hash);

    $node = Node::load($result['node']['id']);
    self::assertNotNull($node);
    $node->set('body', [[
      'value' => '<p>Drifted after approval.</p>',
      'format' => 'basic_html',
    ]]);
    $node->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('content drifted from the exact approved candidate');
    $this->promotion->finalize(
      $payload,
      $this->imageProfile($hash, str_repeat('a', 64)),
      999,
      $hash,
    );
  }

  /**
   * Creates the exact Article fields used by the promotion helpers.
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

    FieldStorageConfig::create([
      'field_name' => 'field_feature_image',
      'entity_type' => 'node',
      'type' => 'image',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_feature_image',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Feature image',
      'translatable' => TRUE,
      'settings' => [
        'file_extensions' => 'png',
        'alt_field' => TRUE,
        'alt_field_required' => TRUE,
      ],
    ])->save();
  }

  /**
   * Returns an exact Article payload with public intent.
   *
   * @return array<string, mixed>
   *   Closed v1 payload.
   */
  private function validPayload(): array {
    return [
      'schema_version' => 1,
      'issue_number' => 999,
      'bundle' => 'article',
      'published' => TRUE,
      'category' => [
        'tid' => $this->categoryTid,
        'name' => 'Conseil',
      ],
      'fr' => [
        'title' => 'Candidat éditorial gouverné',
        'short_description' => 'Description FR exacte.',
        'body_html' => '<h2>Preuve</h2><p>Contenu exact approuvé.</p>',
      ],
      'en' => [
        'title' => 'Governed editorial candidate',
        'short_description' => 'Exact EN description.',
        'body_html' => '<h2>Proof</h2><p>Exact approved content.</p>',
      ],
    ];
  }

  /**
   * Returns the exact bounded feature-image profile.
   *
   * @return array<string, mixed>
   *   Image profile.
   */
  private function imageProfile(string $payloadSha, string $assetSha): array {
    return [
      'issue_number' => 999,
      'bundle' => 'article',
      'article_payload_sha256' => $payloadSha,
      'field_name' => 'field_feature_image',
      'asset' => [
        'path' => 'assets/editorial/promotion-test.png',
        'filename' => 'promotion-test.png',
        'sha256' => $assetSha,
        'mime' => 'image/png',
        'width' => 1,
        'height' => 1,
        'max_bytes' => 2000000,
      ],
      'alt' => [
        'fr' => 'Image approuvée du candidat',
        'en' => 'Approved candidate image',
      ],
    ];
  }

}

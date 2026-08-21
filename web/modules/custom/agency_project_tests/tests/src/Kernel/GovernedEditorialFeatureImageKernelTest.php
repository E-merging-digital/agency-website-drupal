<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Exercises the governed feature-image helper against a real Drupal kernel.
 *
 * @group agency_project_tests
 * @group governed_editorial_feature_image
 */
#[Group('governed_editorial_feature_image')]
final class GovernedEditorialFeatureImageKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'node',
    'language',
    'content_translation',
  ];

  /**
   * Helper under test.
   */
  private object $helper;

  /**
   * Article node ID.
   */
  private int $nodeId;

  /**
   * Original Article payload hash.
   */
  private string $payloadHash;

  /**
   * Temporary exact PNG path.
   */
  private string $assetPath;

  /**
   * Exact PNG hash.
   */
  private string $assetHash;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installConfig(['system']);

    $publicDirectory = 'public://articles';
    $prepared = $this->container->get('file_system')->prepareDirectory(
      $publicDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );
    self::assertTrue($prepared, 'The governed public Article image directory must be writable.');

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

    User::create([
      'uid' => 1,
      'name' => 'agency-image-test-admin',
      'status' => 1,
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_feature_image',
      'entity_type' => 'node',
      'type' => 'image',
      'cardinality' => 1,
      'translatable' => TRUE,
      'settings' => [
        'target_type' => 'file',
        'display_field' => FALSE,
        'display_default' => TRUE,
        'uri_scheme' => 'public',
        'default_image' => [
          'uuid' => NULL,
          'alt' => '',
          'title' => '',
          'width' => NULL,
          'height' => NULL,
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_feature_image',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Image principale',
      'required' => FALSE,
      'translatable' => TRUE,
      'settings' => [
        'file_directory' => 'articles',
        'file_extensions' => 'png',
        'max_filesize' => '',
        'max_resolution' => '',
        'min_resolution' => '',
        'alt_field' => TRUE,
        'alt_field_required' => TRUE,
        'title_field' => FALSE,
        'title_field_required' => FALSE,
      ],
    ])->save();

    $this->container
      ->get('content_translation.manager')
      ->setEnabled('node', 'article', TRUE);

    $node = Node::create([
      'type' => 'article',
      'langcode' => 'fr',
      'title' => 'Checklist avant une refonte',
      'uid' => 1,
      'status' => 1,
    ]);
    $node->addTranslation('en', [
      'title' => 'Website redesign checklist',
      'uid' => 1,
      'status' => 1,
    ]);
    $node->save();
    $this->nodeId = (int) $node->id();
    $this->payloadHash = str_repeat('a', 64);
    $this->container->get('state')->set('agency_editorial.issue.401', [
      'node_id' => $this->nodeId,
      'payload_sha256' => $this->payloadHash,
    ]);

    $png = base64_decode(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl1cdoAAAAASUVORK5CYII=',
      TRUE,
    );
    self::assertIsString($png);
    $this->assetPath = (string) tempnam(sys_get_temp_dir(), 'agency-image-');
    file_put_contents($this->assetPath, $png);
    $hash = hash_file('sha256', $this->assetPath);
    self::assertIsString($hash);
    $this->assetHash = $hash;

    $class = 'AgencyEditorialFeatureImage';
    if (!class_exists($class, FALSE)) {
      if (!defined('AGENCY_EDITORIAL_FEATURE_IMAGE_LIBRARY_ONLY')) {
        define('AGENCY_EDITORIAL_FEATURE_IMAGE_LIBRARY_ONLY', TRUE);
      }
      require_once dirname(DRUPAL_ROOT) . '/scripts/runner/editorial-feature-image.php';
    }
    $factory = [$class, 'fromContainer'];
    if (!is_callable($factory)) {
      throw new \RuntimeException('Trusted feature image helper factory did not load.');
    }
    $helper = $factory($this->container);
    self::assertIsObject($helper);
    $this->helper = $helper;
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->assetPath) && is_file($this->assetPath)) {
      unlink($this->assetPath);
    }
    parent::tearDown();
  }

  /**
   * One exact file is shared while FR/EN ALTs stay independently translated.
   */
  public function testApplyCreatesSharedFileWithTranslatedAltAndIsIdempotent(): void {
    $profile = $this->profile();
    $before = Node::load($this->nodeId);
    self::assertNotNull($before);
    $beforeRevision = (int) $before->getRevisionId();
    $beforeTitleFr = $before->label();
    $beforeTitleEn = $before->getTranslation('en')->label();

    $dryRun = $this->helper->dryRun($profile, 401, $this->assetPath);
    self::assertSame('READY', $dryRun['verdict']);

    $applied = $this->helper->apply($profile, 401, $this->assetPath);
    self::assertSame('PASS', $applied['status']);
    self::assertSame('APPLIED', $applied['verdict']);
    self::assertGreaterThan($beforeRevision, $applied['node']['revision_id']);
    self::assertGreaterThan(0, $applied['node']['image']['fid']);

    $node = Node::load($this->nodeId);
    self::assertNotNull($node);
    $fr = $node->get('field_feature_image')->first();
    $en = $node->getTranslation('en')->get('field_feature_image')->first();
    self::assertNotNull($fr);
    self::assertNotNull($en);
    self::assertSame((int) $fr->get('target_id')->getValue(), (int) $en->get('target_id')->getValue());
    self::assertSame($profile['alt']['fr'], $fr->get('alt')->getValue());
    self::assertSame($profile['alt']['en'], $en->get('alt')->getValue());
    self::assertSame($beforeTitleFr, $node->label());
    self::assertSame($beforeTitleEn, $node->getTranslation('en')->label());

    $revision = (int) $node->getRevisionId();
    $second = $this->helper->apply($profile, 401, $this->assetPath);
    self::assertSame('IDEMPOTENT', $second['verdict']);
    $reloaded = Node::load($this->nodeId);
    self::assertNotNull($reloaded);
    self::assertSame($revision, (int) $reloaded->getRevisionId());
  }

  /**
   * Same exact asset with a stale ALT is a bounded repair, not replacement.
   */
  public function testDryRunReportsAltRepairAndApplyRepairsIt(): void {
    $profile = $this->profile();
    $this->helper->apply($profile, 401, $this->assetPath);
    $node = Node::load($this->nodeId);
    self::assertNotNull($node);
    $node->getTranslation('en')->set('field_feature_image', [
      [
        'target_id' => (int) $node->get('field_feature_image')->target_id,
        'alt' => 'Stale ALT',
      ],
    ]);
    $node->save();
    $staleRevision = (int) $node->getRevisionId();

    $dryRun = $this->helper->dryRun($profile, 401, $this->assetPath);
    self::assertSame('REPAIR_REQUIRED', $dryRun['verdict']);
    $repaired = $this->helper->apply($profile, 401, $this->assetPath);
    self::assertSame('REPAIRED', $repaired['verdict']);
    self::assertGreaterThan($staleRevision, $repaired['node']['revision_id']);
    self::assertSame($profile['alt']['en'], $repaired['node']['image']['alt_en']);
  }

  /**
   * Hash, mapping and profile widening are fail-closed.
   */
  public function testHashMappingAndFieldWideningFailClosed(): void {
    $profile = $this->profile();
    $profile['asset']['sha256'] = str_repeat('b', 64);
    try {
      $this->helper->dryRun($profile, 401, $this->assetPath);
      self::fail('Wrong asset hash was accepted.');
    }
    catch (\RuntimeException $exception) {
      self::assertStringContainsString('asset hash mismatch', $exception->getMessage());
    }

    $profile = $this->profile();
    $profile['field_name'] = 'body';
    try {
      $this->helper->dryRun($profile, 401, $this->assetPath);
      self::fail('Another field was accepted.');
    }
    catch (\InvalidArgumentException $exception) {
      self::assertStringContainsString('field_feature_image', $exception->getMessage());
    }

    $profile = $this->profile();
    $profile['article_payload_sha256'] = str_repeat('c', 64);
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('mapped Article payload hash');
    $this->helper->dryRun($profile, 401, $this->assetPath);
  }

  /**
   * An already present different image is never silently replaced.
   */
  public function testDifferentExistingAssetFailsClosed(): void {
    $profile = $this->profile();
    $this->helper->apply($profile, 401, $this->assetPath);
    $node = Node::load($this->nodeId);
    self::assertNotNull($node);
    $fid = (int) $node->get('field_feature_image')->target_id;
    $file = $this->container->get('entity_type.manager')->getStorage('file')->load($fid);
    self::assertNotNull($file);
    file_put_contents($file->getFileUri(), 'different-bytes');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('different feature image asset');
    $this->helper->dryRun($profile, 401, $this->assetPath);
  }

  /**
   * Builds the exact closed test profile.
   *
   * @return array<string, mixed>
   *   Test profile.
   */
  private function profile(): array {
    return [
      'issue_number' => 401,
      'bundle' => 'article',
      'article_payload_sha256' => $this->payloadHash,
      'field_name' => 'field_feature_image',
      'asset' => [
        'path' => 'assets/editorial/test.png',
        'filename' => 'test-' . substr($this->assetHash, 0, 12) . '.png',
        'sha256' => $this->assetHash,
        'mime' => 'image/png',
        'width' => 1,
        'height' => 1,
        'max_bytes' => 100000,
      ],
      'alt' => [
        'fr' => 'ALT français de test',
        'en' => 'English test ALT',
      ],
    ];
  }

}

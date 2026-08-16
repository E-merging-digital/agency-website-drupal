<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\emerging_digital_content\ContentSync\Policy\GovernedContentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Guards the first controlled release from legacy Content Sync governance.
 */
final class GovernedContentPilotReleaseTest extends TestCase {

  /**
   * The three released case studies must stay outside the Git catalogue.
   */
  public function testPilotCaseStudiesAreReleasedFromCatalog(): void {
    $released = [
      'cas-client-refonte-drupal-institutionnelle' => 'cas-client-refonte-drupal-institutionnelle.yml',
      'cas-client-migration-drupal-11' => 'cas-client-migration-drupal-11.yml',
      'cas-client-integration-ia-editoriale' => 'cas-client-integration-ia-editoriale.yml',
    ];

    $module_root = dirname(__DIR__, 3);
    $catalogue_path = $module_root . '/content_sync/catalog.yml';
    $decoded = Yaml::decode((string) file_get_contents($catalogue_path));

    self::assertIsArray($decoded);
    self::assertArrayHasKey('contents', $decoded);
    self::assertIsArray($decoded['contents']);
    self::assertCount(33, $decoded['contents']);
    self::assertCount(30, GovernedContentPolicy::LEGACY_RELEASE_PENDING_IDS);

    $catalogue_ids = array_column($decoded['contents'], 'id');

    foreach ($released as $content_id => $payload) {
      self::assertNotContains($content_id, $catalogue_ids);
      self::assertFalse(GovernedContentPolicy::isReleasePending($content_id));
      self::assertFileDoesNotExist($module_root . '/content_sync/node/' . $payload);
    }

    foreach (GovernedContentPolicy::GOVERNED_CONTENT_IDS as $content_id) {
      self::assertContains($content_id, $catalogue_ids);
    }
  }

}

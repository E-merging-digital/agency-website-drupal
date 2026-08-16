<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\emerging_digital_content\ContentSync\Policy\GovernedContentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Guards completed controlled releases from legacy Content Sync governance.
 */
final class GovernedContentPilotReleaseTest extends TestCase {

  /**
   * Released batches must stay outside the Git catalogue and policy.
   */
  public function testReleasedContentStaysOutsideCatalog(): void {
    $released = [
      'cas-client-refonte-drupal-institutionnelle' => 'cas-client-refonte-drupal-institutionnelle.yml',
      'cas-client-migration-drupal-11' => 'cas-client-migration-drupal-11.yml',
      'cas-client-integration-ia-editoriale' => 'cas-client-integration-ia-editoriale.yml',
      'ai-automatisation-contenu-drupal' => 'ai-automatisation-contenu-drupal.yml',
      'ai-generation-multilingue' => 'ai-generation-multilingue.yml',
      'ai-chatbot-qualification' => 'ai-chatbot-qualification.yml',
      'ai-audit-intelligent' => 'ai-audit-intelligent.yml',
      'ai-redaction-assistee' => 'ai-redaction-assistee.yml',
      'ai-correction-editoriale' => 'ai-correction-editoriale.yml',
      'ai-traduction-fr-en' => 'ai-traduction-fr-en.yml',
      'ai-resumes-tags-structure' => 'ai-resumes-tags-structure.yml',
      'ai-seo-liens-internes' => 'ai-seo-liens-internes.yml',
      'ai-gouvernance-validation' => 'ai-gouvernance-validation.yml',
    ];

    $module_root = dirname(__DIR__, 3);
    $catalogue_path = $module_root . '/content_sync/catalog.yml';
    $decoded = Yaml::decode((string) file_get_contents($catalogue_path));

    self::assertIsArray($decoded);
    self::assertArrayHasKey('contents', $decoded);
    self::assertIsArray($decoded['contents']);
    self::assertCount(23, $decoded['contents']);
    self::assertCount(20, GovernedContentPolicy::LEGACY_RELEASE_PENDING_IDS);

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

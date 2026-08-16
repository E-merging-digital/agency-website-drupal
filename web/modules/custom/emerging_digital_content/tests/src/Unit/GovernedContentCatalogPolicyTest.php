<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Unit;

use Drupal\Component\Serialization\Yaml;
use Drupal\emerging_digital_content\ContentSync\Policy\GovernedContentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Guards the transitional admission policy for the Content Sync catalogue.
 */
final class GovernedContentCatalogPolicyTest extends TestCase {

  /**
   * Any catalogue admission or release must update the explicit policy.
   */
  public function testCatalogueAdmissionAndReleasePolicyIsExplicit(): void {
    $moduleRoot = dirname(__DIR__, 3);
    $cataloguePath = $moduleRoot . '/content_sync/catalog.yml';
    $decoded = Yaml::decode((string) file_get_contents($cataloguePath));

    self::assertIsArray($decoded);
    self::assertArrayHasKey('contents', $decoded);
    self::assertIsArray($decoded['contents']);

    $entriesById = [];
    foreach ($decoded['contents'] as $entry) {
      self::assertIsArray($entry);
      self::assertArrayHasKey('id', $entry);
      $entriesById[$entry['id']] = $entry;
    }

    self::assertCount(
      count($decoded['contents']),
      $entriesById,
      'Content Sync source IDs must remain unique.',
    );

    $actualIds = array_keys($entriesById);
    sort($actualIds);

    $expectedIds = array_merge(
      GovernedContentPolicy::GOVERNED_CONTENT_IDS,
      GovernedContentPolicy::LEGACY_RELEASE_PENDING_IDS,
    );
    sort($expectedIds);

    self::assertSame(
      $expectedIds,
      $actualIds,
      'Any Content Sync catalogue admission or release requires an explicit Governed Content policy change.',
    );

    self::assertSame(
      [],
      array_values(array_intersect(
        GovernedContentPolicy::GOVERNED_CONTENT_IDS,
        GovernedContentPolicy::LEGACY_RELEASE_PENDING_IDS,
      )),
      'Governed and grandfathered ordinary content sets must remain disjoint.',
    );

    foreach (GovernedContentPolicy::GOVERNED_CONTENT_IDS as $sourceId) {
      $entry = $entriesById[$sourceId];
      self::assertSame('page', $entry['bundle'] ?? NULL, sprintf('%s must remain a governed page.', $sourceId));
      self::assertNotEmpty($entry['legacy_uuid'] ?? NULL, sprintf('%s must retain its migration UUID.', $sourceId));
      self::assertArrayHasKey('fr', $entry['translations'], sprintf('%s must retain its FR translation.', $sourceId));
      self::assertArrayHasKey('en', $entry['translations'], sprintf('%s must retain its EN translation.', $sourceId));
    }

    $bundles = array_column($decoded['contents'], 'bundle');
    self::assertNotContains(
      'article',
      $bundles,
      'Ordinary editorial Article content must never be admitted to the Governed Content transition catalogue.',
    );
  }

}

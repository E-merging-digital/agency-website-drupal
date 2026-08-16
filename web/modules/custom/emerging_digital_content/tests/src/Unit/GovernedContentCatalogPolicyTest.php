<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_content\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Guards the transitional admission policy for the Content Sync catalogue.
 */
final class GovernedContentCatalogPolicyTest extends TestCase {

  /**
   * Content intentionally kept under Git governance.
   */
  private const GOVERNED_CONTENT_IDS = [
    'mentions-legales',
    'politique-confidentialite',
    'politique-cookies',
  ];

  /**
   * Ordinary content grandfathered only until controlled release is proven.
   */
  private const LEGACY_ORDINARY_CONTENT_IDS = [
    'ai-features/agent-workflows',
    'ai-features/brief-wizard',
    'ai-features/chatbot',
    'ai-features/content-assistant',
    'ai-features/document-search',
    'ai-features/observability',
    'ai-features/privacy-guardrails',
    'ai-features/rewrite-blocks',
    'ai-features/semantic-search',
    'ai-features/seo-assistant',
    'cas-clients/industrie-site-haute-disponibilite',
    'cas-clients/plateforme-contenus-api-first',
    'cas-clients/refonte-drupal-b2b',
    'cas-clients',
    'contact',
    'equipe',
    'homepage',
    'ia-drupal',
    'services/architecture',
    'services/communication',
    'services/contenus',
    'services/drupal',
    'services/hebergement',
    'services/ia-drupal',
    'services/infogerance',
    'services/migration',
    'services/sauvegardes',
    'services/securite',
    'services/seo',
    'services/support',
    'services/formation',
    'services/web',
    'services',
  ];

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

    $actualIds = array_keys($entriesById);
    sort($actualIds);

    $expectedIds = array_merge(
      self::GOVERNED_CONTENT_IDS,
      self::LEGACY_ORDINARY_CONTENT_IDS,
    );
    sort($expectedIds);

    self::assertSame(
      $expectedIds,
      $actualIds,
      'Any Content Sync catalogue admission or release requires an explicit Governed Content policy change.',
    );

    self::assertSame(
      [],
      array_values(array_intersect(self::GOVERNED_CONTENT_IDS, self::LEGACY_ORDINARY_CONTENT_IDS)),
      'Governed and grandfathered ordinary content sets must remain disjoint.',
    );

    foreach (self::GOVERNED_CONTENT_IDS as $sourceId) {
      $entry = $entriesById[$sourceId];
      self::assertSame('page', $entry['bundle'] ?? NULL, sprintf('%s must remain a governed page.', $sourceId));
      self::assertNotEmpty($entry['legacy_uuid'] ?? NULL, sprintf('%s must retain its migration UUID.', $sourceId));
      self::assertArrayHasKey('fr', $entry['translations'] ?? [], sprintf('%s must retain its FR translation.', $sourceId));
      self::assertArrayHasKey('en', $entry['translations'] ?? [], sprintf('%s must retain its EN translation.', $sourceId));
    }

    $bundles = array_column($decoded['contents'], 'bundle');
    self::assertNotContains(
      'article',
      $bundles,
      'Ordinary editorial Article content must never be admitted to the Governed Content transition catalogue.',
    );
  }

}

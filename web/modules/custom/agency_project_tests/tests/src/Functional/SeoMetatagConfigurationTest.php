<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\UnitTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Vérifie les defaults SEO Metatag + Schema.org attendus.
 *
 * @group agency_project_tests
 */
final class SeoMetatagConfigurationTest extends UnitTestCase {

  /**
   * Fichiers Metatag exportés attendus.
   */
  private const REQUIRED_METATAG_FILES = [
    'config/sync/metatag.metatag_defaults.front.yml',
    'config/sync/metatag.metatag_defaults.node.yml',
    'config/sync/metatag.metatag_defaults.node__article.yml',
    'config/sync/metatag.metatag_defaults.node__page.yml',
    'config/sync/metatag.metatag_defaults.node__service.yml',
    'config/sync/metatag.metatag_defaults.node__case_client.yml',
    'config/sync/metatag.metatag_defaults.node__ai_feature.yml',
  ];

  /**
   * Vérifie les defaults SEO dans les fichiers exportés config/sync.
   */
  public function testSeoMetatagDefaults(): void {
    $repositoryRoot = dirname(__DIR__, 7);

    foreach (self::REQUIRED_METATAG_FILES as $relativePath) {
      $absolutePath = $repositoryRoot . '/' . $relativePath;
      $this->assertFileExists($absolutePath);
      $parsed = Yaml::parseFile($absolutePath);
      $this->assertIsArray($parsed);
      $tags = $parsed['tags'] ?? NULL;
      $this->assertIsArray($tags);

      foreach ([
        'title',
        'description',
        'canonical_url',
        'og_title',
        'og_description',
        'twitter_cards_title',
        'twitter_cards_description',
      ] as $requiredTag) {
        $this->assertArrayHasKey($requiredTag, $tags, sprintf('Missing "%s" in %s', $requiredTag, $relativePath));
      }
    }

    $frontTags = (Yaml::parseFile($repositoryRoot . '/config/sync/metatag.metatag_defaults.front.yml'))['tags'];
    $this->assertArrayHasKey('schema_web_site_type', $frontTags);
    $this->assertArrayHasKey('schema_organization_type', $frontTags);

    $nodeTags = (Yaml::parseFile($repositoryRoot . '/config/sync/metatag.metatag_defaults.node.yml'))['tags'];
    $this->assertArrayHasKey('schema_web_page_type', $nodeTags);

    $articleTags = (Yaml::parseFile($repositoryRoot . '/config/sync/metatag.metatag_defaults.node__article.yml'))['tags'];
    $this->assertArrayHasKey('schema_article_type', $articleTags);
  }

}

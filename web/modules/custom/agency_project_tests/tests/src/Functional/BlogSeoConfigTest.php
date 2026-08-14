<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Verifies the versioned technical SEO contract for Blog Articles.
 *
 * @group agency_project_tests
 * @group blog
 */
#[Group('blog')]
final class BlogSeoConfigTest extends TestCase {

  /**
   * Verifies neutral Blog aliases and language-specific Pathauto criteria.
   */
  public function testArticlePathautoPatternsUseNeutralBlogAlias(): void {
    $patterns = [
      'pathauto.pattern.content_path_pattern_article.yml' => 'fr',
      'pathauto.pattern.content_path_pattern_article_en.yml' => 'en',
      'pathauto.pattern.node_article.yml' => NULL,
    ];

    foreach ($patterns as $filename => $expected_language) {
      $configuration = $this->loadConfiguration($filename);

      self::assertSame('canonical_entities:node', $configuration['type'] ?? NULL);
      self::assertSame('/blog/[node:title]', $configuration['pattern'] ?? NULL);

      $criteria = $configuration['selection_criteria'] ?? [];
      self::assertIsArray($criteria);
      self::assertTrue($this->hasArticleBundleCriterion($criteria));

      if ($expected_language === NULL) {
        self::assertFalse($this->hasLanguageCriterion($criteria));
      }
      else {
        self::assertTrue($this->hasLanguageCriterion($criteria, $expected_language));
      }
    }
  }

  /**
   * Verifies canonical and social metadata for Blog Articles.
   */
  public function testArticleMetatagUsesCanonicalUrlAndFeatureImage(): void {
    $configuration = $this->loadConfiguration('metatag.metatag_defaults.node__article.yml');
    $tags = $configuration['tags'] ?? [];

    self::assertIsArray($tags);
    self::assertSame('[node:url]', $tags['canonical_url'] ?? NULL);
    self::assertSame('[node:url]', $tags['og_url'] ?? NULL);
    self::assertSame('[node:url]', $tags['schema_article_id'] ?? NULL);
    self::assertSame('[node:url]', $tags['schema_article_main_entity_of_page'] ?? NULL);
    self::assertSame('[node:field_short_description:value]', $tags['description'] ?? NULL);
    self::assertSame('[node:field_feature_image:entity:url]', $tags['og_image'] ?? NULL);
    self::assertSame('[node:field_feature_image:entity:url]', $tags['twitter_cards_image'] ?? NULL);
    self::assertSame('summary_large_image', $tags['twitter_cards_type'] ?? NULL);
  }

  /**
   * Loads one configuration file from the repository sync directory.
   */
  private function loadConfiguration(string $filename): array {
    $path = dirname(DRUPAL_ROOT) . '/config/sync/' . $filename;
    self::assertFileExists($path);

    $configuration = Yaml::parseFile($path);
    self::assertIsArray($configuration);

    return $configuration;
  }

  /**
   * Determines whether the criteria restrict the pattern to Article nodes.
   */
  private function hasArticleBundleCriterion(array $criteria): bool {
    foreach ($criteria as $criterion) {
      if (($criterion['id'] ?? NULL) === 'entity_bundle:node'
        && ($criterion['bundles']['article'] ?? NULL) === 'article') {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Determines whether a language criterion exists, optionally for one locale.
   */
  private function hasLanguageCriterion(array $criteria, ?string $language = NULL): bool {
    foreach ($criteria as $criterion) {
      if (($criterion['id'] ?? NULL) !== 'language') {
        continue;
      }

      if ($language === NULL) {
        return TRUE;
      }

      return ($criterion['langcodes'][$language] ?? NULL) === $language;
    }

    return FALSE;
  }

}

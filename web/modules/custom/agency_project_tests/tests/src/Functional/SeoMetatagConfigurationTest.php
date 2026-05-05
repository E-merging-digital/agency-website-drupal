<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Vérifie les defaults SEO Metatag + Schema.org attendus.
 *
 * @group agency_project_tests
 */
final class SeoMetatagConfigurationTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Vérifie les defaults globaux et par type de contenu.
   */
  public function testSeoMetatagDefaults(): void {
    $front = $this->config('metatag.metatag_defaults.front')->get('tags');
    $this->assertIsArray($front);
    $this->assertArrayHasKey('og_type', $front);
    $this->assertArrayHasKey('twitter_cards_type', $front);
    $this->assertArrayHasKey('schema_web_site_type', $front);
    $this->assertArrayHasKey('schema_organization_type', $front);

    $content = $this->config('metatag.metatag_defaults.node')->get('tags');
    $this->assertIsArray($content);
    $this->assertArrayHasKey('schema_web_page_type', $content);

    $article = $this->config('metatag.metatag_defaults.node__article')->get('tags');
    $this->assertIsArray($article);
    $this->assertSame('Article', $article['schema_article_type'] ?? NULL);

    foreach (['page', 'service', 'case_client', 'ai_feature'] as $bundle) {
      $tags = $this->config('metatag.metatag_defaults.node__' . $bundle)->get('tags');
      $this->assertIsArray($tags);
      $this->assertSame('WebPage', $tags['schema_web_page_type'] ?? NULL);
    }
  }

}

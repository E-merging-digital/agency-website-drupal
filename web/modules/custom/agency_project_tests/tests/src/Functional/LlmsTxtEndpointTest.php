<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the public llms.txt endpoint integration.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class LlmsTxtEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'llms_txt',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Verifies that llms.txt is public and renders configured Markdown.
   */
  public function testLlmsTxtEndpointRendersConfiguredContent(): void {
    $this->config('system.site')
      ->set('name', 'E-MERGING DIGITAL')
      ->save();

    $this->config('llms_txt.settings')
      ->set('content', implode("\n", [
        '# [site:name]',
        '',
        'Strategy and sustainable digital solutions.',
        '',
        '## Important pages',
        '- Services: https://example.com/services',
        '',
        '## Sitemap',
        '- https://example.com/sitemap.xml',
        '',
        '## AI guidance',
        '- Use public pages as the primary source.',
      ]))
      ->save();

    $this->getSession()->visit($this->baseUrl . '/llms.txt');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/markdown');

    $content = $this->getSession()->getPage()->getContent();
    self::assertStringContainsString('# E-MERGING DIGITAL', $content);
    self::assertStringContainsString('## Important pages', $content);
    self::assertStringContainsString('https://example.com/sitemap.xml', $content);
    self::assertStringContainsString('Use public pages as the primary source.', $content);
  }

}

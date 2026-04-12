<?php

declare(strict_types=1);

namespace Drupal\Tests\homepage_smoke_test\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Basic smoke test for homepage rendering.
 *
 * @group homepage_smoke_test
 */
#[RunTestsInSeparateProcesses]
final class HomepageRenderTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Ensures the homepage loads without a runtime rendering error.
   */
  public function testHomepageLoads(): void {
    $this->drupalGet('<front>');
    /** @phpstan-ignore-next-line */
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error.');
  }

}

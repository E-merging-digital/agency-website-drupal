<?php

declare(strict_types=1);

namespace Drupal\Tests\homepage_smoke_test\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Basic smoke test for homepage rendering.
 *
 * @group homepage_smoke_test
 */
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
    $this->assertSame(200, $this->getSession()->getStatusCode());
  }

}

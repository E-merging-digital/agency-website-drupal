<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Smoke test de la homepage.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class HomepageRenderTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Vérifie que la homepage se charge sans erreur runtime.
   */
  public function testHomepageLoads(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('The website encountered an unexpected error.');
  }

}

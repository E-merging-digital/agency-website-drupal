<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers fail-closed readiness over a real Drupal HTTP request.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class AgencyHealthReadinessFailureTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_health',
    'agency_health_test_failure',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A failed required dependency returns only the unavailable contract.
   */
  public function testReadinessRequiredDependencyFailureReturns503(): void {
    $this->drupalGet('/health/ready');

    $this->assertSession()->statusCodeEquals(503);
    $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');

    $body = trim($this->getSession()->getPage()->getContent());
    self::assertSame('{"status":"unavailable"}', $body);

    foreach (['version', 'database', 'host', 'trace', 'exception', 'token', 'password'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, strtolower($body));
    }

    // The fault is readiness-only: Drupal/runtime liveness must remain healthy.
    $this->drupalGet('/health/live');
    $this->assertSession()->statusCodeEquals(200);
    self::assertSame(
      '{"status":"ok"}',
      trim($this->getSession()->getPage()->getContent()),
    );
  }

}

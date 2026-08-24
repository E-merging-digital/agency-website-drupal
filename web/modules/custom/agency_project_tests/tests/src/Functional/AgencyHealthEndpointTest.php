<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the public Agency health endpoints through a real Drupal request.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class AgencyHealthEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_health',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Liveness and readiness return the exact minimal healthy contract.
   */
  public function testPublicHealthEndpointsAreHealthyAndMinimal(): void {
    foreach (['/health/live', '/health/ready'] as $path) {
      $this->getSession()->visit($this->baseUrl . $path);

      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->responseHeaderContains('Content-Type', 'application/json');
      $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');

      $body = trim($this->getSession()->getPage()->getContent());
      self::assertSame('{"status":"ok"}', $body);

      foreach (['version', 'database', 'host', 'trace', 'exception', 'token', 'password'] as $forbidden) {
        self::assertStringNotContainsString($forbidden, strtolower($body));
      }
    }
  }

  /**
   * Unsupported methods are rejected by the route contract.
   */
  public function testHealthEndpointsRejectPost(): void {
    foreach (['/health/live', '/health/ready'] as $path) {
      $client = $this->getSession()->getDriver()->getClient();
      $client->request('POST', $this->baseUrl . $path);
      self::assertSame(405, $client->getResponse()->getStatusCode());
    }
  }

}

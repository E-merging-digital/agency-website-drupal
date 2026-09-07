<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers access control and the deliberate absence of execution routes.
 *
 * @group agency_operations
 */
#[RunTestsInSeparateProcesses]
final class AgencyOperationsAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_operations',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Proves V3 is human-first while preserving read-only technical truth.
   */
  public function testPermissionProtectsReadOnlyCockpit(): void {
    $this->drupalGet('/admin/agency/operations');
    $this->assertSession()->statusCodeEquals(403);

    $account = $this->drupalCreateUser(['access agency operations']);
    $this->drupalLogin($account);

    $this->drupalGet('/admin/agency/operations');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Agency Operations');
    $this->assertSession()->pageTextContains('Daily operational overview');
    $this->assertSession()->pageTextContains('Current runtime');
    $this->assertSession()->pageTextContains('Environments');
    $this->assertSession()->pageTextContains('PROD');
    $this->assertSession()->pageTextContains('PREPROD');
    $this->assertSession()->pageTextContains('DEVELOPMENT');
    $this->assertSession()->pageTextContains('Content');
    $this->assertSession()->pageTextContains('PREPROD data');
    $this->assertSession()->pageTextContains('Development data');
    $this->assertSession()->pageTextContains('Recent evidence');
    $this->assertSession()->pageTextContains('Operational');
    $this->assertSession()->pageTextNotContains('Opérationnel');
    $this->assertSession()->pageTextContains('PLAN is preparation and analysis only');
    $this->assertSession()->pageTextContains('APPLY is not available from this cockpit');
    $this->assertSession()->pageTextContains('Technical details');
    $this->assertSession()->elementNotExists('css', 'details[open]');
    $this->assertSession()->elementTextContains('css', 'details', 'CODE_CONFIG');
    $this->assertSession()->elementTextContains('css', 'details', 'Technical status');
    $this->assertSession()->elementTextContains('css', 'details', 'SOURCE_IMPLEMENTED');
    $this->assertSession()->elementTextContains('css', 'details', 'Manual GitHub authorization is required');
    $this->assertSession()->pageTextContains('View the governed PLAN procedure');
    $this->assertSession()->elementNotExists('css', 'input[type="submit"]');

    $this->drupalGet('/admin/agency/operations/editorial');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('overall: BLOCKED');
  }

  /**
   * Proves the cockpit route still rejects POST execution requests.
   */
  public function testPostIsRejectedBecauseNoExecutionRouteExists(): void {
    $account = $this->drupalCreateUser(['access agency operations']);
    $this->drupalLogin($account);

    $driver = $this->getSession()->getDriver();
    self::assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->followRedirects(FALSE);
    $client->request('POST', $this->baseUrl . '/admin/agency/operations');

    self::assertSame(405, $client->getResponse()->getStatusCode());
  }

}

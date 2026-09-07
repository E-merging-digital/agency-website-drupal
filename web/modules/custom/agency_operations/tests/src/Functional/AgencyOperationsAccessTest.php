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
   * Proves V2 remains protected and presents manual governed handoff only.
   */
  public function testPermissionProtectsReadOnlyCockpit(): void {
    $this->drupalGet('/admin/agency/operations');
    $this->assertSession()->statusCodeEquals(403);

    $account = $this->drupalCreateUser(['access agency operations']);
    $this->drupalLogin($account);

    $this->drupalGet('/admin/agency/operations');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Agency Operations');
    $this->assertSession()->pageTextContains('CODE_CONFIG');
    $this->assertSession()->pageTextContains('DATA_REFRESH');
    $this->assertSession()->pageTextContains('EDITORIAL');
    $this->assertSession()->pageTextContains('DEVELOPMENT_DATA');
    $this->assertSession()->pageTextContains('Environments — authoritative metadata only');
    $this->assertSession()->pageTextContains('Technical status');
    $this->assertSession()->pageTextContains('Refresh PREPROD');
    $this->assertSession()->pageTextContains('Manual GitHub authorization is required');
    $this->assertSession()->pageTextContains('Not available from this cockpit.');
    $this->assertSession()->pageTextContains('View the governed PLAN procedure');
    $this->assertSession()->pageTextContains('History / evidence');
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

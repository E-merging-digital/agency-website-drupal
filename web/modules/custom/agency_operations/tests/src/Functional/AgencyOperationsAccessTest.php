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

  protected static $modules = [
    'agency_operations',
  ];

  protected $defaultTheme = 'stark';

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
    $this->assertSession()->pageTextContains('REAL_REFRESH_TRIGGER = NO');
    $this->assertSession()->pageTextContains('No PROD or PREPROD operation can be triggered from this page.');

    $this->drupalGet('/admin/agency/operations/editorial');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('overall: BLOCKED');
  }

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

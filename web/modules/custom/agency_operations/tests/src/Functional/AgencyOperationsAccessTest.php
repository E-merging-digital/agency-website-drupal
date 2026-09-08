<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\agency_operations\Service\CockpitPlanReceiptReaderInterface;
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->set(
      'agency_operations.cockpit_plan_receipt',
      new StaticCockpitPlanReceiptReader(TRUE),
    );
  }

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
    $this->assertSession()->pageTextContains('Last PREPROD PLAN');
    $this->assertSession()->pageTextContains('Passed');
    $this->assertSession()->pageTextContains('Authority: #1094');
    $this->assertSession()->pageTextContains('Evaluated main: bef02e9…');
    $this->assertSession()->pageTextContains('Observed PROD release: 7541369…');
    $this->assertSession()->pageTextContains('Mutation: None');
    $this->assertSession()->pageTextContains('APPLY: Not authorized');
    $this->assertSession()->pageTextContains('Open evidence');
    $this->assertSession()->pageTextContains('Technical details');
    $this->assertSession()->elementNotExists('css', 'details[open]');
    $this->assertSession()->elementTextContains('css', 'details', 'CODE_CONFIG');
    $this->assertSession()->elementTextContains('css', 'details', 'Technical status');
    $this->assertSession()->elementTextContains('css', 'details', 'SOURCE_IMPLEMENTED');
    $this->assertSession()->elementTextContains(
      'css',
      'details',
      'Human authority remains in the GitHub UI',
    );
    $this->assertSession()->elementTextContains(
      'css',
      'details',
      'plan-1094-cockpit-v1-r1',
    );
    $this->assertSession()->elementTextContains(
      'css',
      'details',
      'This receipt is evidence only',
    );
    $this->assertSession()->pageTextContains('View the governed PLAN procedure');
    $this->assertSession()->elementNotExists('css', 'input[type="submit"]');

    $link = $this->getSession()->getPage()->findLink('Prepare PREPROD PLAN');
    self::assertNotNull($link);
    self::assertSame('_blank', $link->getAttribute('target'));
    self::assertSame('noopener noreferrer', $link->getAttribute('rel'));
    $href = $link->getAttribute('href');
    self::assertNotNull($href);
    $parts = parse_url($href);
    self::assertIsArray($parts);
    self::assertSame('https', $parts['scheme'] ?? NULL);
    self::assertSame('github.com', $parts['host'] ?? NULL);
    self::assertSame(
      '/E-merging-digital/agency-website-drupal/issues/new',
      $parts['path'] ?? NULL,
    );
    parse_str($parts['query'] ?? '', $query);
    self::assertSame(
      '[Ops][PLAN] Prepare PREPROD refresh PLAN',
      $query['title'] ?? NULL,
    );
    self::assertSame('Task,P1,status:in-progress', $query['labels'] ?? NULL);
    self::assertSame(
      $this->expectedCockpitPlanIssueBody(),
      $query['body'] ?? NULL,
    );

    $this->drupalGet('/admin/agency/operations/editorial');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('overall: BLOCKED');
  }

  /**
   * Proves unavailable evidence cannot take down or overstate the Cockpit.
   */
  public function testUnavailablePlanEvidenceStillReturnsCockpit(): void {
    $this->container->set(
      'agency_operations.cockpit_plan_receipt',
      new StaticCockpitPlanReceiptReader(FALSE),
    );
    $account = $this->drupalCreateUser(['access agency operations']);
    $this->drupalLogin($account);

    $this->drupalGet('/admin/agency/operations');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Last PREPROD PLAN');
    $this->assertSession()->pageTextContains('Evidence unavailable');
    $this->assertSession()->pageTextContains('PLAN result is not inferred');
    $this->assertSession()->pageTextContains('APPLY remains not authorized');
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

  /**
   * Returns the exact human-reviewable issue body generated by the cockpit.
   */
  private function expectedCockpitPlanIssueBody(): string {
    return implode("\n", [
      'Parent: #816',
      '',
      '## Cockpit PREPROD PLAN authority',
      '',
      'This issue authorizes one PLAN against the live main resolved when the issue is opened.',
      '',
      'PLAN = analysis / preparation only',
      'NO DATA MUTATION',
      'APPLY = NOT AUTHORIZED',
      '',
      'AGENCY_PREPROD_COCKPIT_PLAN_AUTHORITY={"authorized_actor":"E-merging-digital","implementation_issue":914,"mode":"PLAN","parent_issue":816,"profile_id":"agency-preprod-refresh-simple-v1","run_attempt":1,"schema_version":1}',
      '',
    ]);
  }

}

/**
 * Deterministic receipt reader used by functional Cockpit rendering tests.
 */
final class StaticCockpitPlanReceiptReader implements CockpitPlanReceiptReaderInterface {

  public function __construct(
    private readonly bool $available,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function read(): array {
    if (!$this->available) {
      return [
        'available' => FALSE,
        'status' => 'EVIDENCE_UNAVAILABLE',
        'plan_result' => 'NOT_INFERRED',
        'apply' => 'NOT_AUTHORIZED',
        'mutation' => 'NOT_INFERRED',
        'authority_url' => 'https://github.com/E-merging-digital/agency-website-drupal/issues',
        'run_url' => 'https://github.com/E-merging-digital/agency-website-drupal/actions/workflows/preprod-914-governed-successor.yml',
      ];
    }

    return [
      'available' => TRUE,
      'status' => 'PASSED',
      'plan_result' => 'PASS',
      'authority_issue' => 1094,
      'request_id' => 'plan-1094-cockpit-v1-r1',
      'main_sha' => 'bef02e9fa9dfe0b9cad8a1b3f4d39c10e79d1150',
      'observed_prod_release_sha' => '754136965eef88441904108356686adae8a901f9',
      'completed_at' => '2026-09-07T21:36:02Z',
      'receipt_source' => 'PROJECT_LEAD_BACKFILL',
      'dispatch_run' => 34163693959,
      'jit_main_revalidation' => 'PASS',
      'mutation' => 'NONE',
      'apply' => 'NOT_AUTHORIZED',
      'authority_url' => 'https://github.com/E-merging-digital/agency-website-drupal/issues/1094',
      'run_url' => 'https://github.com/E-merging-digital/agency-website-drupal/actions/runs/34163693959',
    ];
  }

}

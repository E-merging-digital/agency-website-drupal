<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Unit;

use Drupal\agency_operations\Service\CockpitPlanReceiptReader;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Proves Cockpit PLAN receipt reading is public, bounded and fail-closed.
 *
 * @group agency_operations
 */
final class CockpitPlanReceiptReaderTest extends UnitTestCase {

  private const MAIN_SHA = 'bef02e9fa9dfe0b9cad8a1b3f4d39c10e79d1150';

  private const PROD_SHA = '754136965eef88441904108356686adae8a901f9';

  private const AUTHORITY_MARKER = 'AGENCY_PREPROD_COCKPIT_PLAN_AUTHORITY={"authorized_actor":"E-merging-digital","implementation_issue":914,"mode":"PLAN","parent_issue":816,"profile_id":"agency-preprod-refresh-simple-v1","run_attempt":1,"schema_version":1}';

  /**
   * Proves the single Project Lead #1094 migration receipt is accepted.
   */
  public function testBackfillReceiptParsesWithTwoPublicGetRequests(): void {
    $history = [];
    $reader = $this->reader([
      $this->jsonResponse([$this->authorityIssue()]),
      $this->jsonResponse([
        $this->receiptComment(
          $this->receipt(),
          'E-merging-digital',
          5575909815,
        ),
      ]),
    ], $history);

    $result = $reader->read();

    self::assertTrue($result['available']);
    self::assertSame('PASSED', $result['status']);
    self::assertSame('PASS', $result['plan_result']);
    self::assertSame(1094, $result['authority_issue']);
    self::assertSame('PROJECT_LEAD_BACKFILL', $result['receipt_source']);
    self::assertSame('NOT_AUTHORIZED', $result['apply']);
    self::assertCount(2, $history);
    $this->assertReadOnlyFixedGitHubRequests($history);
  }

  /**
   * Proves future workflow receipts require matching public run metadata.
   */
  public function testWorkflowReceiptRequiresSuccessfulMatchingRun(): void {
    $history = [];
    $receipt = $this->receipt(['receipt_source' => 'WORKFLOW']);
    $reader = $this->reader([
      $this->jsonResponse([$this->authorityIssue()]),
      $this->jsonResponse([
        $this->receiptComment($receipt, 'github-actions[bot]', 6000000000),
      ]),
      $this->jsonResponse($this->workflowRun()),
    ], $history);

    $result = $reader->read();

    self::assertTrue($result['available']);
    self::assertSame('WORKFLOW', $result['receipt_source']);
    self::assertSame(34163693959, $result['dispatch_run']);
    self::assertCount(3, $history);
    $this->assertReadOnlyFixedGitHubRequests($history);
  }

  /**
   * Proves malformed receipt text never infers a successful PLAN.
   */
  public function testMalformedReceiptFailsClosed(): void {
    $history = [];
    $reader = $this->reader([
      $this->jsonResponse([$this->authorityIssue()]),
      $this->jsonResponse([
        [
          'id' => 5575909815,
          'user' => ['login' => 'E-merging-digital'],
          'body' => 'AGENCY_PREPROD_COCKPIT_PLAN_RECEIPT={malformed}',
        ],
      ]),
    ], $history);

    $this->assertUnavailable($reader->read());
    self::assertCount(2, $history);
  }

  /**
   * Proves each receipt identity/mutation mismatch fails closed.
   */
  #[DataProvider('invalidReceiptProvider')]
  public function testReceiptContractMismatchFailsClosed(
    string $field,
    mixed $value,
  ): void {
    $history = [];
    $reader = $this->reader([
      $this->jsonResponse([$this->authorityIssue()]),
      $this->jsonResponse([
        $this->receiptComment(
          $this->receipt([$field => $value]),
          'E-merging-digital',
          5575909815,
        ),
      ]),
    ], $history);

    $this->assertUnavailable($reader->read());
    self::assertCount(2, $history);
  }

  /**
   * Invalid receipt cases required by the #1095 fail-closed contract.
   */
  public static function invalidReceiptProvider(): array {
    return [
      'wrong authority' => ['authority_issue', 1093],
      'wrong request id' => ['request_id', 'plan-1093-cockpit-v1-r1'],
      'wrong mode' => ['mode', 'APPLY'],
      'wrong profile' => ['profile_id', 'other-profile'],
      'PROD content read' => ['prod_db_content_read', 'READ'],
      'snapshot performed' => ['prod_snapshot', 'PERFORMED'],
      'data transferred' => ['prod_data_transfer', 'MATERIALIZED'],
      'PREPROD mutated' => ['preprod_db_mutation', 'MATERIALIZED'],
      'PROD written' => ['prod_write', 'MATERIALIZED'],
      'activation enabled' => ['data_activation_authority', 'ENABLED'],
      'APPLY not skipped' => ['apply_job', 'SUCCESS'],
      'issue-open apply possible' => ['issue_open_apply', 'POSSIBLE'],
    ];
  }

  /**
   * Proves app-created authority issues are not trusted as human authority.
   */
  public function testAppMediatedAuthorityIssueFailsClosed(): void {
    $history = [];
    $issue = $this->authorityIssue();
    $issue['performed_via_github_app'] = ['slug' => 'some-app'];
    $reader = $this->reader([
      $this->jsonResponse([$issue]),
    ], $history);

    $this->assertUnavailable($reader->read());
    self::assertCount(1, $history);
  }

  /**
   * Proves a timeout degrades evidence without throwing into the Cockpit page.
   */
  public function testGitHubTimeoutReturnsEvidenceUnavailable(): void {
    $history = [];
    $reader = $this->reader([
      new ConnectException(
        'timeout',
        new Request('GET', 'https://api.github.com/'),
      ),
    ], $history);

    $this->assertUnavailable($reader->read());
    self::assertCount(1, $history);
    $this->assertReadOnlyFixedGitHubRequests($history);
  }

  /**
   * Proves a workflow receipt cannot borrow unrelated run metadata.
   */
  public function testWorkflowRunMismatchFailsClosed(): void {
    $history = [];
    $run = $this->workflowRun();
    $run['head_sha'] = str_repeat('a', 40);
    $reader = $this->reader([
      $this->jsonResponse([$this->authorityIssue()]),
      $this->jsonResponse([
        $this->receiptComment(
          $this->receipt(['receipt_source' => 'WORKFLOW']),
          'github-actions[bot]',
          6000000000,
        ),
      ]),
      $this->jsonResponse($run),
    ], $history);

    $this->assertUnavailable($reader->read());
    self::assertCount(3, $history);
  }

  /**
   * Builds a reader backed by a deterministic Guzzle queue and history.
   */
  private function reader(array $queue, array &$history): CockpitPlanReceiptReader {
    $handler = HandlerStack::create(new MockHandler($queue));
    $handler->push(Middleware::history($history));

    return new CockpitPlanReceiptReader(new Client(['handler' => $handler]));
  }

  /**
   * Returns the exact human-created Cockpit PLAN authority fixture.
   */
  private function authorityIssue(): array {
    return [
      'number' => 1094,
      'title' => '[Ops][PLAN] Prepare PREPROD refresh PLAN',
      'body' => "Parent: #816\n\n" . self::AUTHORITY_MARKER,
      'user' => ['login' => 'E-merging-digital'],
      'author_association' => 'OWNER',
      'performed_via_github_app' => NULL,
    ];
  }

  /**
   * Returns one canonical receipt with optional field overrides.
   */
  private function receipt(array $overrides = []): array {
    return array_replace([
      'schema_version' => 1,
      'receipt_source' => 'PROJECT_LEAD_BACKFILL',
      'authority_issue' => 1094,
      'dispatch_run' => 34163693959,
      'run_attempt' => 1,
      'request_id' => 'plan-1094-cockpit-v1-r1',
      'mode' => 'PLAN',
      'profile_id' => 'agency-preprod-refresh-simple-v1',
      'main_sha' => self::MAIN_SHA,
      'jit_main_revalidation' => 'PASS',
      'plan_result' => 'PASS',
      'observed_prod_release_sha' => self::PROD_SHA,
      'prod_db_content_read' => 'NONE',
      'prod_snapshot' => 'NOT_PERFORMED',
      'prod_data_transfer' => 'NONE',
      'preprod_db_mutation' => 'NONE',
      'prod_write' => 'NONE',
      'data_activation_authority' => 'DISABLED',
      'apply_job' => 'SKIPPED',
      'issue_open_apply' => 'IMPOSSIBLE',
      'completed_at' => '2026-09-07T21:36:02Z',
    ], $overrides);
  }

  /**
   * Builds one issue comment containing the canonical marker.
   */
  private function receiptComment(
    array $receipt,
    string $author,
    int $commentId,
  ): array {
    return [
      'id' => $commentId,
      'user' => ['login' => $author],
      'body' => 'Evidence only.' . "\n\n"
      . 'AGENCY_PREPROD_COCKPIT_PLAN_RECEIPT='
      . json_encode($receipt, JSON_THROW_ON_ERROR),
    ];
  }

  /**
   * Returns matching public Actions metadata for a workflow receipt.
   */
  private function workflowRun(): array {
    return [
      'id' => 34163693959,
      'status' => 'completed',
      'conclusion' => 'success',
      'run_attempt' => 1,
      'event' => 'issues',
      'head_sha' => self::MAIN_SHA,
      'actor' => ['login' => 'E-merging-digital'],
      'repository' => [
        'full_name' => 'E-merging-digital/agency-website-drupal',
      ],
    ];
  }

  /**
   * Wraps arbitrary data in an application/json response.
   */
  private function jsonResponse(array $data): Response {
    return new Response(
      200,
      ['Content-Type' => 'application/json'],
      json_encode($data, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Proves every request is an unauthenticated GET to the fixed public host.
   */
  private function assertReadOnlyFixedGitHubRequests(array $history): void {
    self::assertLessThanOrEqual(3, count($history));
    foreach ($history as $transaction) {
      $request = $transaction['request'];
      self::assertSame('GET', $request->getMethod());
      self::assertSame('https', $request->getUri()->getScheme());
      self::assertSame('api.github.com', $request->getUri()->getHost());
      self::assertStringStartsWith(
        '/repos/E-merging-digital/agency-website-drupal/',
        $request->getUri()->getPath(),
      );
      self::assertFalse($request->hasHeader('Authorization'));
    }
  }

  /**
   * Proves unavailable evidence never infers PLAN success or APPLY authority.
   */
  private function assertUnavailable(array $result): void {
    self::assertFalse($result['available']);
    self::assertSame('EVIDENCE_UNAVAILABLE', $result['status']);
    self::assertSame('NOT_INFERRED', $result['plan_result']);
    self::assertSame('NOT_AUTHORIZED', $result['apply']);
  }

}

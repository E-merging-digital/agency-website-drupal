<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the Cockpit human GitHub PLAN-only authority path.
 *
 * @group agency_project_tests
 * @group agency_operations
 */
final class CockpitPlanAuthorityWorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  private const SUCCESSOR = '.github/workflows/preprod-914-governed-successor.yml';

  /**
   * Proves the central dispatcher owns both supported GitHub event shapes.
   */
  public function testSingleDispatcherOwnsIssueEvents(): void {
    $root = dirname(DRUPAL_ROOT);
    $issueCommentListeners = [];
    $issueOpenedListeners = [];

    foreach (glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [] as $path) {
      $parsed = Yaml::parseFile($path);
      self::assertIsArray($parsed, $path);
      $on = $parsed['on'] ?? NULL;
      if (!is_array($on)) {
        continue;
      }
      $relative = substr($path, strlen($root) + 1);
      if (array_key_exists('issue_comment', $on)) {
        $issueCommentListeners[] = $relative;
      }
      if (array_key_exists('issues', $on)) {
        $issueOpenedListeners[] = $relative;
      }
    }

    sort($issueCommentListeners);
    sort($issueOpenedListeners);
    self::assertSame([self::DISPATCHER], $issueCommentListeners);
    self::assertSame([self::DISPATCHER], $issueOpenedListeners);

    $dispatcher = $this->parsed(self::DISPATCHER);
    self::assertSame(
      ['created'],
      $dispatcher['on']['issue_comment']['types'] ?? NULL,
    );
    self::assertSame(['opened'], $dispatcher['on']['issues']['types'] ?? NULL);
  }

  /**
   * Proves issue-open can route only to the bounded PLAN reuse path.
   */
  public function testIssueOpenRouteIsPlanOnlyAndReuses914(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $source = $this->source(self::DISPATCHER);
    $jobs = $dispatcher['jobs'] ?? [];
    $job = $jobs['preprod-refresh-cockpit-plan'] ?? NULL;
    $receipt = $jobs['publish-cockpit-plan-receipt'] ?? NULL;

    self::assertIsArray($job);
    self::assertSame('./' . self::SUCCESSOR, $job['uses'] ?? NULL);
    self::assertSame(
      ['contents' => 'read', 'issues' => 'read'],
      $job['permissions'] ?? NULL,
    );
    self::assertSame([
      'SSH_PRIVATE_KEY',
      'PREPROD_SSH_PRIVATE_KEY',
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'SERVER_USER',
      'PREPROD_SERVER_HOST',
    ], array_keys($job['secrets'] ?? []));
    self::assertStringContainsString(
      "needs.classify.outputs.route == 'PREPROD_REFRESH_COCKPIT_PLAN'",
      (string) ($job['if'] ?? ''),
    );

    self::assertIsArray($receipt);
    self::assertArrayNotHasKey('uses', $receipt);
    self::assertSame(
      ['issues' => 'write'],
      $receipt['permissions'] ?? NULL,
    );
    self::assertSame(
      ['classify', 'preprod-refresh-cockpit-plan'],
      $receipt['needs'] ?? NULL,
    );
    self::assertStringContainsString(
      "needs.classify.outputs.route == 'PREPROD_REFRESH_COCKPIT_PLAN'",
      (string) ($receipt['if'] ?? ''),
    );
    self::assertStringContainsString(
      "needs['preprod-refresh-cockpit-plan'].result == 'success'",
      (string) ($receipt['if'] ?? ''),
    );
    self::assertStringContainsString(
      "needs['preprod-refresh-cockpit-plan'].outputs.mode == 'PLAN'",
      (string) ($receipt['if'] ?? ''),
    );

    self::assertStringContainsString(
      "event_name == 'issues' and event_action == 'opened'",
      $source,
    );
    self::assertStringContainsString(
      "route = 'PREPROD_REFRESH_COCKPIT_PLAN'",
      $source,
    );
    self::assertStringContainsString('"mode":"PLAN"', $source);
    self::assertStringNotContainsString('ISSUE_OPEN_APPLY', $source);
    self::assertStringNotContainsString('workflow_dispatch:', $source);
    self::assertStringNotContainsString('secrets: inherit', $source);
  }

  /**
   * Proves the #914 engine keeps legacy APPLY and adds no second PLAN engine.
   */
  public function testSuccessorKeepsLegacyApplyAndReusesRunPlan(): void {
    $workflow = $this->parsed(self::SUCCESSOR);
    $source = $this->source(self::SUCCESSOR);
    $jobs = $workflow['jobs'] ?? [];
    $outputs = $workflow['on']['workflow_call']['outputs'] ?? [];

    self::assertStringContainsString(
      'validate-execution-authority.py',
      $source,
    );
    self::assertStringContainsString(
      'validate-cockpit-plan-authority.py',
      $source,
    );
    self::assertSame(1, substr_count($source, 'run-plan.sh'));
    self::assertSame(1, substr_count($source, 'run-server-to-server-apply.sh'));
    self::assertStringContainsString(
      "github.event_name == 'issue_comment'",
      $source,
    );
    self::assertStringContainsString(
      "needs.validate-authority.outputs.mode == 'APPLY'",
      $source,
    );
    self::assertArrayNotHasKey('publish-cockpit-plan-receipt', $jobs);
    self::assertStringNotContainsString('issues: write', $source);
    self::assertSame([
      'mode',
      'request_id',
      'main_sha',
      'operation_profile',
      'authority_issue',
      'observed_prod_release_sha',
    ], array_keys($outputs));
  }

  /**
   * Proves receipt publication happens only after successful PLAN cleanup.
   */
  public function testPlanReceiptIsPostCleanupEvidenceOnly(): void {
    $successor = $this->parsed(self::SUCCESSOR);
    $dispatcher = $this->parsed(self::DISPATCHER);
    $successorSource = $this->source(self::SUCCESSOR);
    $dispatcherSource = $this->source(self::DISPATCHER);
    $successorJobs = $successor['jobs'] ?? [];
    $dispatcherJobs = $dispatcher['jobs'] ?? [];
    $plan = $successorJobs['plan'] ?? NULL;
    $apply = $successorJobs['apply'] ?? NULL;
    $legacy = $dispatcherJobs['preprod-refresh'] ?? NULL;
    $cockpit = $dispatcherJobs['preprod-refresh-cockpit-plan'] ?? NULL;
    $receipt = $dispatcherJobs['publish-cockpit-plan-receipt'] ?? NULL;

    self::assertIsArray($plan);
    self::assertIsArray($apply);
    self::assertIsArray($legacy);
    self::assertIsArray($cockpit);
    self::assertIsArray($receipt);
    self::assertSame(
      ['contents' => 'read', 'issues' => 'read'],
      $plan['permissions'] ?? NULL,
    );
    self::assertSame(
      ['contents' => 'read', 'issues' => 'read'],
      $apply['permissions'] ?? NULL,
    );
    self::assertSame(
      ['contents' => 'read', 'issues' => 'read'],
      $legacy['permissions'] ?? NULL,
    );
    self::assertSame(
      ['contents' => 'read', 'issues' => 'read'],
      $cockpit['permissions'] ?? NULL,
    );
    self::assertSame(
      ['issues' => 'write'],
      $receipt['permissions'] ?? NULL,
    );
    self::assertSame(
      ['classify', 'preprod-refresh-cockpit-plan'],
      $receipt['needs'] ?? NULL,
    );
    self::assertStringContainsString(
      "github.event_name == 'issues'",
      (string) ($receipt['if'] ?? ''),
    );
    self::assertStringContainsString(
      "github.event.action == 'opened'",
      (string) ($receipt['if'] ?? ''),
    );
    self::assertStringContainsString(
      "needs['preprod-refresh-cockpit-plan'].result == 'success'",
      (string) ($receipt['if'] ?? ''),
    );
    self::assertStringContainsString(
      "needs['preprod-refresh-cockpit-plan'].outputs.mode == 'PLAN'",
      (string) ($receipt['if'] ?? ''),
    );

    $planSteps = $plan['steps'] ?? [];
    self::assertIsArray($planSteps);
    self::assertSame(
      'Cleanup PLAN identities',
      $planSteps[array_key_last($planSteps)]['name'] ?? NULL,
    );

    self::assertStringNotContainsString(
      'AGENCY_PREPROD_COCKPIT_PLAN_RECEIPT=',
      $successorSource,
    );
    self::assertStringContainsString(
      'AGENCY_PREPROD_COCKPIT_PLAN_RECEIPT=',
      $dispatcherSource,
    );
    self::assertStringContainsString('receipt_source:"WORKFLOW"', $dispatcherSource);
    self::assertStringContainsString('plan_result:"PASS"', $dispatcherSource);
    self::assertStringContainsString('prod_db_content_read:"NONE"', $dispatcherSource);
    self::assertStringContainsString('prod_snapshot:"NOT_PERFORMED"', $dispatcherSource);
    self::assertStringContainsString('prod_data_transfer:"NONE"', $dispatcherSource);
    self::assertStringContainsString('preprod_db_mutation:"NONE"', $dispatcherSource);
    self::assertStringContainsString('prod_write:"NONE"', $dispatcherSource);
    self::assertStringContainsString('apply_job:"SKIPPED"', $dispatcherSource);
    self::assertStringContainsString('issue_open_apply:"IMPOSSIBLE"', $dispatcherSource);
  }

  /**
   * Proves Drupal only creates a fixed external GET draft link.
   */
  public function testCockpitContainsNoGitHubWriteCapability(): void {
    $source = $this->source(
      'web/modules/custom/agency_operations/src/Controller/OperationsController.php',
    );
    self::assertStringContainsString("'/issues/new'", $source);
    self::assertStringContainsString('Prepare PREPROD PLAN', $source);
    self::assertStringContainsString(
      'AGENCY_PREPROD_COCKPIT_PLAN_AUTHORITY=',
      $source,
    );
    self::assertStringContainsString(
      "'labels' => 'Task,P1,status:in-progress'",
      $source,
    );
    self::assertStringContainsString('APPLY = NOT AUTHORIZED', $source);

    foreach ([
      'GITHUB_TOKEN',
      'GITHUB_APP_PRIVATE_KEY',
      'OAUTH',
      'HttpClientInterface',
      'http_client',
      'request(',
      'post(',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }
  }

  /**
   * Parses one repository workflow structurally.
   */
  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

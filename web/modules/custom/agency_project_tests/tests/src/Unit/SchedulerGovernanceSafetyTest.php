<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects PROD scheduler authority from functional promotion side effects.
 *
 * @group agency_project_tests
 * @group production_scheduler
 */
final class SchedulerGovernanceSafetyTest extends TestCase {

  /**
   * Functional promotion can only verify the scheduler and never write it.
   */
  public function testFunctionalPromotionSchedulerPathIsVerifyOnly(): void {
    $promotion = $this->script('scripts/production-promotion/promote-candidate.sh');
    $verifier = $this->script('scripts/production-promotion/reconcile-cron.sh');

    self::assertStringContainsString('reconcile-cron.sh', $promotion);
    self::assertStringNotContainsString('mutate-cron.sh', $promotion);
    self::assertStringContainsString(
      'SCHEDULER_ACTION="${SCHEDULER_ACTION:-${1:-VERIFY_ONLY}}"',
      $verifier,
    );
    self::assertStringContainsString(
      '[[ "$SCHEDULER_ACTION" == \'VERIFY_ONLY\' ]]',
      $verifier,
    );
    self::assertStringContainsString(
      'Functional promotion may only VERIFY_ONLY the PROD scheduler.',
      $verifier,
    );
    self::assertStringContainsString('SCHEDULER_ACTION=VERIFY_ONLY', $promotion);
    self::assertStringContainsString('SCHEDULER_CONTEXT=IN_FLIGHT_PROMOTION', $promotion);
    self::assertStringNotContainsString('crontab "$tmp"', $verifier);
    self::assertStringNotContainsString('mktemp', $verifier);
  }

  /**
   * Standalone verify-only stays receipt-backed to the exact current release.
   */
  public function testVerifyOnlyBindsCurrentReleaseReceipt(): void {
    $verifier = $this->script('scripts/production-promotion/reconcile-cron.sh');

    foreach ([
      'SCHEDULER_CONTEXT="${SCHEDULER_CONTEXT:-STANDALONE}"',
      'PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"',
      'current_release="$(readlink -f "$CURRENT")"',
      'STANDALONE)',
      "'^release_path='",
      "'^candidate_sha='",
      'Current production release must map to exactly one promotion receipt.',
      'Standalone scheduler verification does not accept in-flight identity.',
      'production_current_release=%s',
      'production_current_release_sha=%s',
    ] as $required) {
      self::assertStringContainsString($required, $verifier);
    }
  }

  /**
   * In-flight promotion binds exact current identity without a final receipt.
   */
  public function testInFlightPromotionBindsExactCurrentReleaseWithoutFinalReceipt(): void {
    $promotion = $this->script('scripts/production-promotion/promote-candidate.sh');
    $verifier = $this->script('scripts/production-promotion/reconcile-cron.sh');

    foreach ([
      'SCHEDULER_EXPECTED_CANDIDATE_SHA="${SCHEDULER_EXPECTED_CANDIDATE_SHA:-}"',
      'SCHEDULER_EXPECTED_CURRENT_RELEASE="${SCHEDULER_EXPECTED_CURRENT_RELEASE:-}"',
      'IN_FLIGHT_PROMOTION)',
      '[[ "$SCHEDULER_EXPECTED_CANDIDATE_SHA" =~ ^[0-9a-f]{40}$ ]]',
      'readlink -f -- "$SCHEDULER_EXPECTED_CURRENT_RELEASE"',
      '[[ "$SCHEDULER_EXPECTED_CURRENT_RELEASE" == "$expected_current_release" ]]',
      '[[ "$expected_current_release" == "$RELEASES_DIR/"* ]]',
      '[[ "$expected_release_name" =~ ^[0-9]{14}-[0-9a-f]{12}$ ]]',
      '[[ "${expected_release_name#*-}" == "${SCHEDULER_EXPECTED_CANDIDATE_SHA:0:12}" ]]',
      '[[ "$current_release" == "$expected_current_release" ]]',
      'In-flight promotion candidate identity is invalid.',
      'In-flight promotion expected current release must be canonical.',
      'In-flight promotion expected current release must remain under the production releases directory.',
      'In-flight promotion release identity differs from expected candidate identity.',
      'Current production release differs from in-flight promotion expectation.',
    ] as $required) {
      self::assertStringContainsString($required, $verifier);
    }

    foreach ([
      'SCHEDULER_ACTION=VERIFY_ONLY',
      'SCHEDULER_CONTEXT=IN_FLIGHT_PROMOTION',
      'SCHEDULER_EXPECTED_CANDIDATE_SHA="$EXPECTED_SHA"',
      'SCHEDULER_EXPECTED_CURRENT_RELEASE="$NEW_RELEASE"',
      '"$CURRENT_LINK/scripts/production-promotion/reconcile-cron.sh"',
    ] as $required) {
      self::assertStringContainsString($required, $promotion);
    }

    $standaloneStart = strpos($verifier, "  STANDALONE)\n");
    $inFlightStart = strpos($verifier, "  IN_FLIGHT_PROMOTION)\n");
    $caseEnd = is_int($inFlightStart) ? strpos($verifier, "\nesac", $inFlightStart) : FALSE;

    self::assertIsInt($standaloneStart);
    self::assertIsInt($inFlightStart);
    self::assertIsInt($caseEnd);
    self::assertTrue($standaloneStart < $inFlightStart);
    self::assertTrue($inFlightStart < $caseEnd);

    $standaloneBlock = substr(
      $verifier,
      $standaloneStart,
      $inFlightStart - $standaloneStart,
    );
    $inFlightBlock = substr(
      $verifier,
      $inFlightStart,
      $caseEnd - $inFlightStart,
    );

    self::assertStringContainsString(
      'for receipt in "$PROMOTIONS_DIR"/*.env; do',
      $standaloneBlock,
    );
    self::assertStringNotContainsString(
      'for receipt in "$PROMOTIONS_DIR"/*.env; do',
      $inFlightBlock,
    );

    $maintenanceOff = strpos(
      $promotion,
      '"$CURRENT_LINK/vendor/bin/drush" state:set system.maintenance_mode 0',
    );
    $scheduler = strpos($promotion, 'SCHEDULER_CONTEXT=IN_FLIGHT_PROMOTION');
    $evidence = strpos($promotion, "printf 'schema_version=2\\n'");
    $receipt = strpos($promotion, 'mv -f "$receipt_tmp" "$RECEIPT"');

    foreach ([$maintenanceOff, $scheduler, $evidence, $receipt] as $position) {
      self::assertIsInt($position);
    }

    self::assertTrue($maintenanceOff < $scheduler);
    self::assertTrue($scheduler < $evidence);
    self::assertTrue($evidence < $receipt);
  }

  /**
   * Verify-only accepts only the exact controlled scheduler contract.
   */
  public function testVerifyOnlyContractFailsClosedOnDriftOrDuplicates(): void {
    $verifier = $this->script('scripts/production-promotion/reconcile-cron.sh');

    foreach ([
      "MARKER='# agency-drupal-cron'",
      "SCHEDULE='*/15 * * * *'",
      'LOCK_FILE="$PROJECT_ROOT/shared/cron.lock"',
      'Drupal automated cron must remain disabled in PROD.',
      'An unmanaged system cron Drupal scheduler exists.',
      'An unmanaged systemd Drupal scheduler exists.',
      'Controlled Agency scheduler marker count is not exactly one.',
      'Controlled Agency scheduler does not match the exact expected contract.',
      'Deploy-user Drupal scheduler count is not exactly one.',
      'An unmanaged deploy-user Drupal cron scheduler exists.',
      'production_scheduler_action=VERIFY_ONLY',
      'production_scheduler_runtime_state=CONTROLLED',
    ] as $required) {
      self::assertStringContainsString($required, $verifier);
    }
  }

  /**
   * Scheduler writes stay behind a reusable route with exact owner authority.
   */
  public function testSchedulerMutationRequiresSeparateExactOwnerAuthority(): void {
    $workflow = $this->workflow('.github/workflows/production-scheduler-change.yml');
    $mutator = $this->script('scripts/production-promotion/mutate-cron.sh');

    self::assertStringContainsString('workflow_call:', $workflow);
    self::assertStringNotContainsString('issue_comment:', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString("\n  push:\n", $workflow);
    self::assertStringContainsString(
      "github.event.comment.user.login == 'E-merging-digital'",
      $workflow,
    );
    self::assertStringContainsString(
      "github.event.comment.author_association == 'OWNER'",
      $workflow,
    );
    foreach ([
      '/agency-production-scheduler\\ action=',
      'release=([0-9a-f]{40})',
      'expected=(ABSENT|CONTROLLED|MANAGED_DRIFT)',
      'CREATE:ABSENT|UPDATE:MANAGED_DRIFT|REMOVE:CONTROLLED',
      'auth_body_sha',
      'comment_id',
      'SCHEDULER_AUTHORITY_KIND=OWNER_ISSUE_COMMENT',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'CREATE)',
      'UPDATE)',
      'REMOVE)',
      '[[ "$AUTHORITY_KIND" == \'OWNER_ISSUE_COMMENT\' ]]',
      'AUTH_COMMENT_ID="${4:-}"',
      'AUTH_BODY_SHA256="${5:-}"',
      'Current production release identity differs from authorized identity.',
      'Scheduler state is $RUNTIME_STATE, expected authorized state $EXPECTED_STATE.',
      'crontab "$tmp"',
      'authorization_comment_id=',
      'authorization_body_sha256=',
    ] as $required) {
      self::assertStringContainsString($required, $mutator);
    }
  }

  /**
   * Unmanaged state is never an authorized mutation precondition.
   */
  public function testSchedulerMutationStateTransitionsAreBounded(): void {
    $mutator = $this->script('scripts/production-promotion/mutate-cron.sh');

    self::assertStringContainsString("RUNTIME_STATE='UNMANAGED'", $mutator);
    self::assertStringContainsString('CREATE requires expected state ABSENT.', $mutator);
    self::assertStringContainsString('UPDATE requires expected state MANAGED_DRIFT.', $mutator);
    self::assertStringContainsString('REMOVE requires expected state CONTROLLED.', $mutator);
    self::assertStringNotContainsString('CREATE:UNMANAGED', $mutator);
    self::assertStringNotContainsString('UPDATE:UNMANAGED', $mutator);
    self::assertStringNotContainsString('REMOVE:UNMANAGED', $mutator);
  }

  /**
   * PR-time scheduler validation stays offline and cannot access PROD runtime.
   */
  public function testProductionSchedulerAuditIsOfflineOnPullRequest(): void {
    $workflow = $this->workflow('.github/workflows/production-scheduler-readonly-audit.yml');

    self::assertStringContainsString('pull_request:', $workflow);
    self::assertStringContainsString(
      'Validate PROD scheduler contract without runtime access',
      $workflow,
    );
    self::assertStringContainsString(
      'Validate scheduler verifier without PROD runtime access',
      $workflow,
    );
    self::assertStringContainsString('bash -n "$verifier"', $workflow);
    self::assertStringContainsString(
      '! grep -Fq \'crontab "$tmp"\' "$verifier"',
      $workflow,
    );
    foreach ([
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'SERVER_USER',
      'ssh -o ConnectTimeout',
      'https://emergingdigital.be',
      'curl --silent',
      'SCHEDULER_AUTHORITY_KIND=OWNER_ISSUE_COMMENT',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
    foreach ([
      'SCHEDULER_CONTEXT="${SCHEDULER_CONTEXT:-STANDALONE}"',
      'IN_FLIGHT_PROMOTION)',
      'Current production release must map to exactly one promotion receipt.',
      'Drupal automated cron must remain disabled in PROD.',
      'An unmanaged system cron Drupal scheduler exists.',
      'An unmanaged systemd Drupal scheduler exists.',
      'Controlled Agency scheduler marker count is not exactly one.',
      'Controlled Agency scheduler does not match the exact expected contract.',
      'Deploy-user Drupal scheduler count is not exactly one.',
      'An unmanaged deploy-user Drupal cron scheduler exists.',
      'production_scheduler_action=VERIFY_ONLY',
      'production_scheduler_runtime_state=CONTROLLED',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Returns a parsed workflow and its source text.
   */
  private function workflow(string $relativePath): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/' . $relativePath;

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

  /**
   * Returns a repository-owned script.
   */
  private function script(string $relativePath): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/' . $relativePath;
    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

}

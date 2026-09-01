<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded read-only #941 diagnostic for the consumed #940 request.
 *
 * @group agency_project_tests
 * @group preproduction_refresh
 */
final class PreproductionRefresh940DiagnosticTest extends TestCase {

  /**
   * The route is owner-only, issue-only and accepts no request/path selector.
   */
  public function testWorkflowIsBoundToExact941DiagnosticOnly(): void {
    $workflow = $this->workflow();
    $observer = $this->observer();

    foreach ([
      'github.event.issue.number == 941',
      "github.event.comment.user.login == 'E-merging-digital'",
      "github.actor == 'E-merging-digital'",
      "github.event.comment.body == '/agency-preprod-refresh-940-diagnostic diagnose'",
      'test "$ISSUE_NUMBER" = 941',
      'test "$COMMENT_BODY" = \'/agency-preprod-refresh-940-diagnostic diagnose\'',
      'persist-credentials: false',
      'JIT validate exact #941 diagnostic authority before SSH secret',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringContainsString(
      "REQUEST_ID='apply-940-first-real-s2s-r1'",
      $observer,
    );
    self::assertStringContainsString(
      "EXPECTED_MAIN='0b61d56264ad0163cd3bdbd5ea6e07253a155fbb'",
      $observer,
    );
    self::assertStringContainsString(
      'ROOT_STAGE="$ROOT_STAGE_PARENT/412fc11485c5"',
      $observer,
    );
    self::assertStringContainsString('[[ "$#" -eq 0 ]]', $observer);
    self::assertStringNotContainsString('REQUEST_ID="${1:-}"', $observer);
    self::assertStringNotContainsString('JOB_ROOT="${', $observer);
  }

  /**
   * The observer is metadata-only and owns no mutation/recovery capability.
   */
  public function testObserverContainsNoMutationOrPrivilegeWidening(): void {
    $observer = $this->observer();

    foreach ([
      'kill ',
      'pkill ',
      'sudo ',
      'drush ',
      'mariadb',
      'mysql ',
      'sql:drop',
      'sql:cli',
      'maint:set',
      'state:set',
      'chmod ',
      'chown ',
      'mkdir ',
      'touch ',
      'rm -',
      'mv ',
      'flock ',
      'git ',
      'composer ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $observer);
    }

    $workflow = $this->workflow();
    self::assertStringNotContainsString(
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      $workflow,
    );
    self::assertStringNotContainsString('root@', $workflow);
    self::assertStringNotContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
    self::assertStringNotContainsString('secrets.SERVER_HOST', $workflow);
    self::assertStringNotContainsString('/actions/runs/', $workflow);
    self::assertStringNotContainsString('gh run rerun', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Terminal metadata is parsed without shell evaluation and fully allowlisted.
   */
  public function testResultParserIsNonEvaluatingAndAllowlisted(): void {
    $observer = $this->observer();

    self::assertStringContainsString('awk -F= -v wanted="$key"', $observer);
    self::assertStringNotContainsString('source "$RESULT"', $observer);
    self::assertStringNotContainsString('eval ', $observer);
    self::assertStringNotContainsString('. "$RESULT"', $observer);
    self::assertStringContainsString('"$result_bytes" -gt 4096', $observer);

    foreach ([
      'SANITIZED_DATABASE_ACTIVE_AND_VALIDATED',
      'NO_PREPROD_RUNTIME_MUTATION',
      'EXACT_BACKUP_OR_UNCHANGED_RUNTIME_PROVEN',
      'NO_MUTATION_DEPLOY_LOCK_BUSY',
      'NO_PREPROD_RUNTIME_MUTATION_SERVER_TO_SERVER_PREP_FAILED',
      'NO_PREPROD_RUNTIME_MUTATION_SANITIZED_HANDOFF_FAILED',
      'NO_PREPROD_RUNTIME_MUTATION_ACTIVATION_LAUNCH_FAILED',
      'NO_PREPROD_RUNTIME_MUTATION_UNEXPECTED_PREACTIVATION_FAILURE',
      'RAW_STAGING_CLEANUP_UNPROVEN',
      'PROD_IDENTITY_STAGE_CLEANUP_UNPROVEN',
      'ROLLBACK_NOT_PROVEN_MAINTENANCE_REMAINS_ON',
    ] as $allowedDetail) {
      self::assertStringContainsString($allowedDetail, $observer);
    }
  }

  /**
   * Logs and SQL are observed by metadata only and never emitted as content.
   */
  public function testObserverNeverEmitsArbitraryLogsOrSqlContent(): void {
    $observer = $this->observer();

    foreach ([
      'BOOTSTRAP_LOG="$ROOT_STAGE/bootstrap.log"',
      'bootstrap_bytes="$(stat -c \'%s\' -- "$BOOTSTRAP_LOG"',
      'SANITIZED="$JOB/sanitized.sql"',
      'SANITIZED_TMP="$JOB/sanitized.sql.tmp"',
    ] as $required) {
      self::assertStringContainsString($required, $observer);
    }

    foreach ([
      'cat ',
      'tail ',
      'less ',
      'head "$BOOTSTRAP_LOG"',
      'head -n "$BOOTSTRAP_LOG"',
      '< "$BOOTSTRAP_LOG"',
      '< "$SANITIZED"',
      '< "$SANITIZED_TMP"',
      'worker.log',
      'runtime.env',
      'settings.php',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $observer);
    }
  }

  /**
   * The classification contract distinguishes every #941 incident gate.
   */
  public function testClassificationContractCoversAliveDeadTerminalAndUnobservable(): void {
    $observer = $this->observer();

    foreach ([
      'WORKER_ALIVE_PREACTIVATION',
      'WORKER_ALIVE_ACTIVATION_OR_CONVERGENCE',
      'WORKER_DEAD_NO_TERMINAL_METADATA',
      'TERMINAL_METADATA_PRESENT',
      'COMMITTED',
      'ROLLED_BACK',
      'HUMAN_RECOVERY_REQUIRED',
      'UNOBSERVABLE_FAIL_CLOSED',
      'result_outcome=INVALID',
      'worker_process=UNOBSERVABLE',
      'worker_phase=UNOBSERVABLE',
      "raw_staging_scope='UNOBSERVABLE'",
      "maintenance_mode='UNOBSERVABLE'",
      "refresh_fence='UNOBSERVABLE'",
    ] as $required) {
      self::assertStringContainsString($required, $observer);
    }

    self::assertStringContainsString(
      'classify ABSENT NONE ALIVE PREACTIVATION NO',
      $observer,
    );
    self::assertStringContainsString(
      'classify ABSENT NONE ALIVE ACTIVATION_OR_CONVERGENCE NO',
      $observer,
    );
    self::assertStringContainsString(
      'classify ABSENT NONE DEAD NONE NO',
      $observer,
    );
    self::assertStringContainsString(
      'classify ABSENT NONE UNOBSERVABLE UNOBSERVABLE NO',
      $observer,
    );
    self::assertStringContainsString(
      'classify PRESENT COMMITTED DEAD NONE NO',
      $observer,
    );
  }

  /**
   * Process phase comes from bounded identity, not artifact absence.
   */
  public function testWorkerPhaseUsesBoundedProcessIdentity(): void {
    $observer = $this->observer();

    foreach ([
      'head -c 1 /proc/1/cmdline',
      'pgrep -f -- "$REQUEST_ID"',
      'ps -o euid=,etimes= -p "$worker_pid"',
      'seen_root',
      'seen_deploy_user',
      "worker_phase='ACTIVATION_OR_CONVERGENCE'",
      "worker_phase='PREACTIVATION'",
    ] as $required) {
      self::assertStringContainsString($required, $observer);
    }

    self::assertStringNotContainsString(
      'ALIVE && "$activation_worker"',
      $observer,
    );
  }

  /**
   * SSH observation is fixed, hard-bounded and uses only the normal PREPROD key.
   */
  public function testWorkflowUsesBoundedFixedPreprodObservation(): void {
    $workflow = $this->workflow();

    foreach ([
      'timeout 30s ssh',
      '-o ConnectTimeout=10',
      '-o ServerAliveInterval=5',
      '-o ServerAliveCountMax=2',
      '"agency-preprod@$PREPROD_SERVER_HOST"',
      '\'bash -s\' < "$observer"',
      'Diagnostic SSH observation exceeded the hard 30-second wall-clock bound.',
      'PREPROD_KEY: ${{ secrets.PREPROD_SSH_PRIVATE_KEY }}',
      'Validate pinned PREPROD SSH trust',
      'Diagnostic output exceeds bounded size.',
      'Diagnostic output key contract violated.',
      '\'worker_process\', \'worker_phase\'',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString(
      '"agency-preprod@$PREPROD_SERVER_HOST" "$COMMENT_BODY"',
      $workflow,
    );
    self::assertStringNotContainsString('scp ', $workflow);
    self::assertStringNotContainsString('rsync ', $workflow);
  }

  /**
   * Returns and validates the dedicated workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/preprod-refresh-940-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

  /**
   * Returns the fixed repository-owned remote observer.
   */
  private function observer(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/preproduction-refresh/governed-successor/diagnose-940-worker.sh';

    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

}

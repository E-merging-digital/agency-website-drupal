<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #945 server-to-server refresh defect fixes.
 *
 * @group agency_project_tests
 * @group preproduction_data_refresh
 */
final class PreproductionServerToServerDefectFixTest extends TestCase {

  /**
   * Canonical host bootstrap owns the refresh-jobs contract explicitly.
   */
  public function testBootstrapProvisionsExactRefreshJobsContract(): void {
    $bootstrap = $this->source('scripts/preproduction/bootstrap-host.sh');

    self::assertStringContainsString(
      '"$SHARED_DIR/deploy-jobs" "$SHARED_DIR/refresh-jobs"',
      $bootstrap,
    );
    self::assertStringContainsString(
      'install -d -m 700 -o "$PROJECT_USER" -g "$PROJECT_USER"',
      $bootstrap,
    );
    self::assertStringContainsString(
      '"$(stat -c \'%U:%G:%a\' "$jobs_dir")" == '
      . '"$PROJECT_USER:$PROJECT_USER:700"',
      $bootstrap,
    );
    self::assertStringContainsString(
      '[[ -d "$jobs_dir" && ! -L "$jobs_dir" ]]',
      $bootstrap,
    );
  }

  /**
   * JIT prerequisite is fixed, fail-closed, and precedes secret staging.
   */
  public function testJitPrerequisitePrecedesProdIdentityStaging(): void {
    $control = $this->control();
    $prerequisite = $this->prerequisite();

    $jit = strpos($control, 'REFRESH_JOBS_ROOT=READY');
    $ordinaryProof = strpos(
      $control,
      "stat -c '%U:%G:%a' '\$REFRESH_JOBS_ROOT'",
    );
    $keyCopy = strpos(
      $control,
      'cp "$PROD_SSH_KEY" "$local_stage/prod-read.key"',
    );
    $remoteStage = strpos(
      $control,
      "install -d -o root -g root -m 700 '\$remote_stage'",
    );
    foreach ([$jit, $ordinaryProof, $keyCopy, $remoteStage] as $offset) {
      self::assertIsInt($offset);
    }
    self::assertLessThan($ordinaryProof, $jit);
    self::assertLessThan($keyCopy, $ordinaryProof);
    self::assertLessThan($remoteStage, $keyCopy);

    self::assertStringContainsString(
      "REFRESH_JOBS = SHARED / 'refresh-jobs'",
      $prerequisite,
    );
    self::assertStringContainsString(
      "DEPLOY_USER = 'agency-preprod'",
      $prerequisite,
    );
    self::assertStringContainsString('EXPECTED_MODE = 0o700', $prerequisite);
    self::assertStringContainsString('os.lstat(path)', $prerequisite);
    self::assertStringContainsString(
      'stat.S_ISLNK(metadata.st_mode)',
      $prerequisite,
    );
    self::assertStringContainsString(
      'not stat.S_ISDIR(metadata.st_mode)',
      $prerequisite,
    );
    self::assertStringContainsString('metadata.st_uid != uid', $prerequisite);
    self::assertStringContainsString('metadata.st_gid != gid', $prerequisite);
    self::assertStringContainsString(
      'stat.S_IMODE(metadata.st_mode) != EXPECTED_MODE',
      $prerequisite,
    );
    self::assertStringContainsString(
      'os.mkdir(REFRESH_JOBS, EXPECTED_MODE)',
      $prerequisite,
    );
    self::assertStringContainsString(
      'Fixed prerequisite accepts no arguments.',
      $prerequisite,
    );
  }

  /**
   * The prerequisite cannot reach PROD, DB, or a caller-selected path.
   */
  public function testPrerequisiteHasNoProdDbOrGenericExecutionSurface(): void {
    $prerequisite = strtolower($this->prerequisite());

    $forbiddenTerms = [
      'ssh',
      'mariadb',
      'mysql',
      'drush',
      'subprocess',
      'os.system',
      'shell=true',
    ];
    foreach ($forbiddenTerms as $forbidden) {
      self::assertStringNotContainsString($forbidden, $prerequisite);
    }
    self::assertStringContainsString(
      "path('/var/www/agency-preprod/shared')",
      $prerequisite,
    );
    self::assertStringNotContainsString('input(', $prerequisite);
  }

  /**
   * Polling has both per-observation and total hard wall-clock boundaries.
   */
  public function testPollingHasTrueHardWallClockBounds(): void {
    $control = $this->control();
    $poll = $this->poll();

    self::assertStringContainsString(
      'PER_OBSERVATION_HARD_TIMEOUT_SECONDS=20',
      $poll,
    );
    self::assertStringContainsString(
      'timeout --signal=KILL "${PER_OBSERVATION_HARD_TIMEOUT_SECONDS}s"',
      $poll,
    );
    self::assertSame(
      1,
      substr_count($poll, '"${root_ssh[@]}" "root@$PREPROD_SSH_HOST"'),
    );
    self::assertStringContainsString(
      'CONTROL_PLANE_HARD_TIMEOUT_SECONDS=5400',
      $control,
    );
    self::assertStringContainsString(
      'timeout --signal=KILL "${CONTROL_PLANE_HARD_TIMEOUT_SECONDS}s" env',
      $control,
    );
    self::assertStringContainsString(
      'CONTROL_PLANE_STATE=BOUND_EXHAUSTED_NO_TERMINAL_METADATA',
      $control,
    );
    self::assertStringNotContainsString(
      'worker still running',
      strtolower($control),
    );
  }

  /**
   * Transport failure is an explicit fail-closed state, never empty success.
   */
  public function testTransportFailureIsNotEmptyObservation(): void {
    $poll = $this->poll();

    self::assertStringContainsString(
      'if (( observation_status != 0 )); then',
      $poll,
    );
    self::assertStringContainsString(
      'PREPROD_WORKER_STATE=UNOBSERVABLE_FAIL_CLOSED',
      $poll,
    );
    self::assertStringContainsString(
      'CONTROL_PLANE_OBSERVATION_STATUS=',
      $poll,
    );
    self::assertStringContainsString(
      'if (( ${#lines[@]} != 3 )); then',
      $poll,
    );
  }

  /**
   * Terminal metadata wins; absent metadata requires explicit worker truth.
   */
  public function testWorkerStateClassificationIsTruthful(): void {
    $observer = $this->observer();
    $poll = $this->poll();

    $requiredObserverContracts = [
      "TERMINAL_OUTCOMES = {'COMMITTED', 'ROLLED_BACK', "
      . "'HUMAN_RECOVERY_REQUIRED'}",
      "return 'ABSENT', 'NONE'",
      "return 'PRESENT', outcome",
      "return 'ALIVE' if matched else 'DEAD'",
      "return 'UNOBSERVABLE'",
    ];
    foreach ($requiredObserverContracts as $required) {
      self::assertStringContainsString($required, $observer);
    }
    self::assertStringContainsString(
      'if [[ "$terminal" == PRESENT ]]; then',
      $poll,
    );
    self::assertStringContainsString(
      'PREPROD_WORKER_STATE=WORKER_DEAD_NO_TERMINAL_METADATA',
      $poll,
    );
    self::assertStringContainsString('ALIVE)', $poll);
    self::assertStringContainsString('UNOBSERVABLE)', $poll);

    $terminal = strpos($poll, 'if [[ "$terminal" == PRESENT ]]; then');
    $dead = strpos($poll, 'WORKER_DEAD_NO_TERMINAL_METADATA');
    self::assertIsInt($terminal);
    self::assertIsInt($dead);
    self::assertLessThan($dead, $terminal);
  }

  /**
   * Observer output is metadata-only and process matching is request-bound.
   */
  public function testObserverDoesNotExposeProcessOrSecretContent(): void {
    $observer = $this->observer();

    self::assertStringContainsString('MAX_RESULT_BYTES = 4096', $observer);
    self::assertStringContainsString(
      'stage_worker = str(REQUEST_ROOT / suffix',
      $observer,
    );
    self::assertStringContainsString(
      "activation_worker = str(SHARED / 'refresh-jobs'",
      $observer,
    );
    self::assertStringContainsString(
      'request_id not in argv or expected_main not in argv',
      $observer,
    );
    self::assertStringNotContainsString('print(argv', $observer);
    self::assertStringNotContainsString('print(raw', $observer);
    self::assertStringNotContainsString('bootstrap.log', $observer);
    self::assertStringNotContainsString('prod-read.key', $observer);
    self::assertStringNotContainsString('sanitized.sql', $observer);
  }

  /**
   * The failed #940 authority is not embedded as a reusable request.
   */
  public function testFailedRequestIsNotReusableAndNoRetryPathIsAdded(): void {
    $surface = implode("\n", [
      $this->control(),
      $this->prerequisite(),
      $this->observer(),
      $this->poll(),
    ]);

    self::assertStringNotContainsString(
      'apply-940-first-real-s2s-r1',
      $surface,
    );
    self::assertStringNotContainsString('rerun', strtolower($surface));
    self::assertStringNotContainsString('retry #940', strtolower($surface));
  }

  /**
   * Existing activation backup/rollback and direct raw route remain intact.
   */
  public function testExistingActivationAndRawRouteSemanticsArePreserved(): void {
    $control = $this->control();
    $worker = $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'remote-server-to-server-worker.py',
    );
    $activation = $this->source(
      'scripts/preproduction-refresh/governed-successor/remote-apply-worker.sh',
    );

    self::assertStringContainsString(
      'RAW_PROD_ROUTE=PROD_TO_PREPROD_DIRECT',
      $this->poll(),
    );
    self::assertStringNotContainsString("\nprod_ssh=(", "\n" . $control);
    self::assertStringContainsString('helper.require_absent(scope)', $worker);
    self::assertStringContainsString('cleanup_root_stage(stage)', $worker);
    self::assertStringContainsString(
      'sql:dump --no-interaction --result-file="$BACKUP"',
      $activation,
    );
    self::assertStringContainsString('sql:cli < "$BACKUP"', $activation);
    self::assertStringContainsString('HUMAN_RECOVERY_REQUIRED', $activation);
  }

  /**
   * Returns the server-to-server launcher source.
   */
  private function control(): string {
    return $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'run-server-to-server-apply.sh',
    );
  }

  /**
   * Returns the fixed refresh-jobs prerequisite source.
   */
  private function prerequisite(): string {
    return $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'ensure-refresh-jobs-root.py',
    );
  }

  /**
   * Returns the worker observer source.
   */
  private function observer(): string {
    return $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'observe-server-to-server-worker.py',
    );
  }

  /**
   * Returns the bounded polling helper source.
   */
  private function poll(): string {
    return $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'poll-server-to-server-worker.sh',
    );
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
  }

}
<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the final fixed read-only #948 detail diagnostic.
 *
 * @group agency_project_tests
 * @group preproduction_data_refresh
 */
final class PreproductionRefresh948DetailDiagnosticTest extends TestCase {

  private const WORKFLOW = '.github/workflows/preprod-refresh-948-detail-diagnostic.yml';

  /**
   * The existing observer exposes only its already validated bounded detail.
   */
  public function testObserverExposesValidatedDetailOnly(): void {
    $observer = $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'observe-server-to-server-worker.py',
    );

    self::assertStringContainsString('MAX_RESULT_BYTES = 4096', $observer);
    self::assertStringContainsString('os.lstat(path)', $observer);
    self::assertStringContainsString('metadata.st_uid != uid', $observer);
    self::assertStringContainsString('metadata.st_gid != gid', $observer);
    self::assertStringContainsString('stat.S_IMODE(metadata.st_mode) != 0o600', $observer);
    self::assertStringContainsString("values.get('request_id') != request_id", $observer);
    self::assertStringContainsString("values.get('main_sha') != expected_main", $observer);
    self::assertStringContainsString('outcome not in TERMINAL_OUTCOMES', $observer);
    self::assertStringContainsString("DETAIL_RE = re.compile(r'^[A-Z0-9_]+$')", $observer);
    self::assertStringContainsString("return 'PRESENT', outcome, detail", $observer);
    self::assertStringContainsString("print(f'detail={detail}')", $observer);
    self::assertStringContainsString("print('detail=NONE')", $observer);
    self::assertStringNotContainsString('print(raw', $observer);
    self::assertStringNotContainsString('print(values', $observer);
    self::assertStringNotContainsString('source ', $observer);
    self::assertStringNotContainsString('eval(', $observer);
  }

  /**
   * The existing poller accepts exactly one additional bounded detail field.
   */
  public function testPollerPreservesBoundedWatchdogSemantics(): void {
    $poll = $this->source(
      'scripts/preproduction-refresh/governed-successor/'
      . 'poll-server-to-server-worker.sh',
    );

    self::assertStringContainsString('if (( ${#lines[@]} != 4 )); then', $poll);
    self::assertStringContainsString('[[ "${lines[2]}" == detail=* ]]', $poll);
    self::assertStringContainsString('[[ "$detail" =~ ^[A-Z0-9_]+$ ]]', $poll);
    self::assertStringContainsString('PREPROD_WORKER_DETAIL=%s', $poll);
    self::assertStringContainsString('PER_OBSERVATION_HARD_TIMEOUT_SECONDS=20', $poll);
    self::assertStringContainsString('PREPROD_WORKER_STATE=WORKER_DEAD_NO_TERMINAL_METADATA', $poll);
    self::assertStringContainsString('PREPROD_WORKER_STATE=UNOBSERVABLE_FAIL_CLOSED', $poll);
    self::assertStringNotContainsString('worker still running', strtolower($poll));
  }

  /**
   * The real diagnostic route is fixed to the consumed #948 authority only.
   */
  public function testDiagnosticIsHardBoundToExact948RequestAndMain(): void {
    $workflow = $this->source(self::WORKFLOW);

    self::assertSame(1, substr_count($workflow, 'apply-948-final-real-s2s-r1'));
    self::assertSame(
      1,
      substr_count($workflow, '2e4fa695f57fb8605379cb27837a2d03b6e78ecd'),
    );
    self::assertStringContainsString(
      "'/usr/bin/python3 -I - apply-948-final-real-s2s-r1 "
      . "2e4fa695f57fb8605379cb27837a2d03b6e78ecd'",
      $workflow,
    );
    self::assertStringContainsString(
      "github.event.issue.number == 949",
      $workflow,
    );
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-preprod-refresh-948-detail diagnose'",
      $workflow,
    );
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('inputs:', $workflow);
    self::assertStringNotContainsString('REQUEST_ID:', $workflow);
    self::assertStringNotContainsString('EXPECTED_MAIN:', $workflow);
  }

  /**
   * The diagnostic receives PREPROD root transport only, never PROD access.
   */
  public function testDiagnosticHasNoProdOrGenericExecutionSurface(): void {
    $workflow = $this->source(self::WORKFLOW);
    $dispatcher = $this->source('.github/workflows/agency-command-dispatch.yml');

    self::assertStringContainsString(
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY:',
      $workflow,
    );
    self::assertStringContainsString('PREPROD_SERVER_HOST:', $workflow);
    foreach ([
      'SERVER_USER:',
      'PROD_SSH_HOST',
      'PROD_SSH_USER',
      'PROD_SSH_KEY',
      'SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}',
      'secrets: inherit',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
    self::assertStringContainsString(
      "needs.classify.outputs.route == 'PREPROD_REFRESH_948_DETAIL'",
      $dispatcher,
    );
    self::assertStringContainsString(
      'uses: ./.github/workflows/preprod-refresh-948-detail-diagnostic.yml',
      $dispatcher,
    );
    self::assertStringContainsString(
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY: '
      . '${{ secrets.PREPROD_PROVISIONING_SSH_PRIVATE_KEY }}',
      $dispatcher,
    );
  }

  /**
   * Remote execution is observation-only and raw output is gated before print.
   */
  public function testDiagnosticIsReadOnlyAndOutputIsStrictlyValidated(): void {
    $workflow = $this->source(self::WORKFLOW);

    self::assertStringContainsString('timeout 30s ssh', $workflow);
    self::assertStringContainsString("if len(data) > 1024:", $workflow);
    self::assertStringContainsString("len(lines) != 4", $workflow);
    self::assertStringContainsString(
      "set(pairs) != {'terminal_metadata', 'outcome', 'detail', 'worker_process'}",
      $workflow,
    );
    self::assertStringContainsString(
      "re.fullmatch(r'[A-Z0-9_]+', pairs['detail'])",
      $workflow,
    );
    self::assertStringContainsString("pairs['detail'] == 'NONE'", $workflow);

    $validator = strpos($workflow, "python3 - \"$output\" <<'PY'");
    $printer = strpos($workflow, 'while IFS= read -r bounded_line; do');
    self::assertIsInt($validator);
    self::assertIsInt($printer);
    self::assertLessThan($printer, $validator);

    foreach ([
      'drush ',
      'mariadb ',
      'mysql ',
      'kill ',
      'pkill ',
      'flock ',
      'maintenance-mode',
      'refresh-fence',
      'result.env"',
      'cat "$output"',
      'source "$output"',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, strtolower($workflow));
    }
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(
      dirname(DRUPAL_ROOT) . '/' . $relativePath,
    );
  }

}

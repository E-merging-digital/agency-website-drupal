<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed PROD Ubuntu maintenance capability from #1183.
 *
 * @group agency_project_tests
 */
final class ProdOsMaintenance1183WorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const WORKFLOW = '.github/workflows/prod-os-maintenance-1183.yml';
  private const PLAN = 'scripts/production-maintenance-1183/remote-plan.sh';
  private const APPLY = 'scripts/production-maintenance-1183/remote-apply.sh';
  private const WINDOW_GATE = 'scripts/production-maintenance-1183/check-maintenance-window.py';
  private const MAX_PACKET_OBSERVER =
    'scripts/production-maintenance-1183/max-allowed-packet-observer.sh';
  private const POST = 'scripts/production-maintenance-1183/remote-post-reboot.sh';

  /**
   * Direct OWNER commands are issue-bound; app-authored commands are rejected.
   */
  public function testDispatcherAuthorityAndPlanApplySeparation(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $jobs = $dispatcher['jobs'] ?? [];
    $plan = $jobs['prod-os-maintenance-1183-plan'] ?? NULL;
    $apply = $jobs['prod-os-maintenance-1183-apply'] ?? NULL;
    self::assertIsArray($plan);
    self::assertIsArray($apply);
    foreach ([$plan, $apply] as $job) {
      self::assertSame('./' . self::WORKFLOW, $job['uses'] ?? NULL);
      self::assertSame(
        ['actions' => 'read', 'contents' => 'read', 'issues' => 'write'],
        $job['permissions'] ?? NULL,
      );
      $condition = (string) ($job['if'] ?? '');
      foreach ([
        "github.event_name == 'issue_comment'",
        "github.event.action == 'created'",
        'github.event.issue.pull_request == null',
        'github.event.issue.number == 1183',
        "github.event.comment.author_association == 'OWNER'",
        "github.event.comment.user.login == 'E-merging-digital'",
        'github.event.comment.performed_via_github_app == null',
      ] as $required) {
        self::assertStringContainsString($required, $condition);
      }
      self::assertSame(
        ['SSH_PRIVATE_KEY', 'SERVER_HOST', 'SERVER_USER'],
        array_keys($job['secrets'] ?? []),
      );
    }
    self::assertStringContainsString(
      "body == '/agency-prod-os-maintenance-1183 plan'",
      (string) ($plan['if'] ?? ''),
    );
    self::assertStringContainsString(
      "startsWith(github.event.comment.body, '/agency-prod-os-maintenance-1183 apply ')",
      (string) ($apply['if'] ?? ''),
    );
    self::assertNotSame($plan['if'] ?? NULL, $apply['if'] ?? NULL);
  }

  /**
   * PROD identity, paths and trust are distinct from PREPROD.
   */
  public function testProdIdentityPathsAndReadOnlyPlanContract(): void {
    $workflow = $this->source(self::WORKFLOW);
    $plan = $this->source(self::PLAN);
    foreach ([
      "ISSUE='1183'",
      "TARGET='PROD'",
      "MODE='PLAN'",
      "PROD_URL='https://emergingdigital.be'",
      "DRUPAL_ROOT='/var/www/agency/current'",
      'scripts/production-ssh-trust/manage-known-host.sh PROVISION',
      '$SERVER_USER@$SERVER_HOST',
      'CONFIG_STATUS',
      'CONFIG_AUTO_CORRECTION',
      'MAX_ALLOWED_PACKET',
      'PUBLIC_HOME',
      'CONTACT_FORM_SURFACE',
      'RECENT_NGINX_PHP_ERRORS',
    ] as $required) {
      self::assertStringContainsString($required, $workflow . "\n" . $plan);
    }
    foreach ([
      'apt-get install',
      'apt-get upgrade',
      'full-upgrade',
      'dist-upgrade',
      'do-release-upgrade',
      'systemctl reboot',
      'systemctl restart',
      'state:set system.maintenance_mode',
      'drush cim',
      'drush updb',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $plan);
    }
    self::assertStringNotContainsString('/var/www/agency-preprod', $workflow . $plan);
    self::assertStringNotContainsString('PREPROD_', $workflow . $plan);
  }

  /**
   * Snapshot/window gates, backups and exact tuples precede package mutation.
   */
  public function testApplyOrderingAndBoundaries(): void {
    $workflow = $this->source(self::WORKFLOW);
    $apply = $this->source(self::APPLY);
    foreach ([
      'snapshot_ref=',
      'window_ref=',
      'PROVIDER_SNAPSHOT_REF',
      'MAINTENANCE_WINDOW_REF',
      'vendor/bin/drush sql:dump --gzip',
      'sudo -n -- "$SYSTEM_CONFIG_BACKUP_HELPER"',
      'sudo -n -- "$REBOOT_HELPER"',
      'MAINTENANCE_ACTION:"REBOOT_ONLY"',
      'PACKAGE_APPLY:"NONE"',
      'PACKAGE_APPLY_SUCCESS:"NOT_REQUIRED"',
      'SECOND_EXACT_APT_SIMULATION:"NOT_REQUIRED"',
      'BACKUPS_BEFORE_REBOOT:"PASS"',
      'KEEP_PREVIOUS_KERNEL',
    ] as $required) {
      self::assertStringContainsString($required, $workflow . "\n" . $apply);
    }
    foreach ([
      'sudo -n -- /usr/bin/apt-get',
      'sudo -n -- /usr/sbin/nginx -t',
      'sudo -n -- /usr/sbin/php-fpm8.4 -t',
      'sudo -n -- /usr/bin/systemctl is-active',
      'sudo -n -- /usr/bin/systemctl reboot',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $apply);
    }
    $dbBackup = strpos($apply, 'vendor/bin/drush sql:dump --gzip');
    $configBackup = strpos($apply, 'sudo -n -- "$SYSTEM_CONFIG_BACKUP_HELPER"');
    $maintenanceOn = strpos($apply, 'state:set system.maintenance_mode 1');
    $reboot = strpos($apply, 'sudo -n -- "$REBOOT_HELPER"');
    foreach ([$dbBackup, $configBackup, $maintenanceOn, $reboot] as $position) {
      self::assertNotFalse($position);
    }
    self::assertLessThan($maintenanceOn, $dbBackup);
    self::assertLessThan($maintenanceOn, $configBackup);
    self::assertLessThan($reboot, $maintenanceOn);
  }

  /**
   * The approved maintenance window is deterministic and half-open.
   */
  public function testMaintenanceWindowGateIsDeterministicAndHalfOpen(): void {
    $script = dirname(DRUPAL_ROOT) . '/' . self::WINDOW_GATE;
    self::assertFileExists($script);

    $window = '2026-09-20T22:35+02:00/2026-09-20T23:35+02:00';
    $start = (new \DateTimeImmutable('2026-09-20T22:35+02:00'))->getTimestamp();
    $end = (new \DateTimeImmutable('2026-09-20T23:35+02:00'))->getTimestamp();
    $cases = [
      'before_start' => [$window, $start - 1, 1, 'TOO_EARLY'],
      'exact_start' => [$window, $start, 0, 'PASS'],
      'inside_window' => [$window, $start + 1, 0, 'PASS'],
      'exact_end' => [$window, $end, 1, 'TOO_LATE'],
      'after_end' => [$window, $end + 1, 1, 'TOO_LATE'],
      'malformed_start' => ['not-a-time/2026-09-20T23:35+02:00', $start, 1, 'INVALID'],
      'malformed_end' => ['2026-09-20T22:35+02:00/not-a-time', $start, 1, 'INVALID'],
      'missing_offset' => ['2026-09-20T22:35/2026-09-20T23:35+02:00', $start, 1, 'INVALID'],
      'invalid_date' => ['2026-02-30T22:35+02:00/2026-09-20T23:35+02:00', $start, 1, 'INVALID'],
      'invalid_time' => ['2026-09-20T25:35+02:00/2026-09-20T26:35+02:00', $start, 1, 'INVALID'],
      'start_equals_end' => ['2026-09-20T22:35+02:00/2026-09-20T22:35+02:00', $start, 1, 'INVALID'],
      'start_after_end' => ['2026-09-20T23:35+02:00/2026-09-20T22:35+02:00', $start, 1, 'INVALID'],
    ];

    foreach ($cases as $label => [$candidate, $now, $expectedStatus, $expectedOutput]) {
      $output = [];
      $status = 99;
      exec(
        'python3 ' . escapeshellarg($script)
        . ' ' . escapeshellarg($candidate)
        . ' --now-epoch ' . escapeshellarg((string) $now)
        . ' 2>&1',
        $output,
        $status,
      );
      self::assertSame($expectedStatus, $status, $label . ': ' . implode("\n", $output));
      self::assertSame($expectedOutput, implode("\n", $output), $label);
    }
  }

  /**
   * The maintenance-window gate rejects before every real mutation boundary.
   */
  public function testMaintenanceWindowGatePrecedesMutationAndHasBoundedReceipt(): void {
    $workflow = $this->source(self::WORKFLOW);
    $apply = $this->source(self::APPLY);

    self::assertStringContainsString(
      'python3 "$WINDOW_GATE_HELPER" "$WINDOW_REF"',
      $apply,
    );
    self::assertStringNotContainsString('--now-epoch', $apply);
    self::assertStringContainsString(self::WINDOW_GATE, $workflow);

    $gate = strpos($apply, 'python3 "$WINDOW_GATE_HELPER" "$WINDOW_REF"');
    $stalePlan = strpos($apply, '"$PLAN_SCRIPT" "$main_sha"');
    $dbBackup = strpos($apply, 'vendor/bin/drush sql:dump --gzip');
    $configBackup = strpos($apply, 'sudo -n -- "$SYSTEM_CONFIG_BACKUP_HELPER"');
    $maintenanceOn = strpos($apply, 'state:set system.maintenance_mode 1');
    $reboot = strpos($apply, 'sudo -n -- "$REBOOT_HELPER"');
    foreach ([$gate, $stalePlan, $dbBackup, $configBackup, $maintenanceOn, $reboot] as $position) {
      self::assertNotFalse($position);
    }
    self::assertLessThan($stalePlan, $gate);
    self::assertLessThan($dbBackup, $gate);
    self::assertLessThan($configBackup, $gate);
    self::assertLessThan($maintenanceOn, $gate);
    self::assertLessThan($reboot, $gate);

    foreach ([
      'FAILURE_PHASE:"PRE_MUTATION"',
      'FAILURE_STAGE:"MAINTENANCE_WINDOW_CHECK"',
      'MAINTENANCE_WINDOW_CHECK:"FAIL"',
      'MAINTENANCE_WINDOW_REASON:$reason',
      'REAL_PACKAGE_MUTATION:"NONE"',
      'REBOOT_HELPER_INVOKED:"NO"',
      'REBOOT_BOUNDARY_CROSSED:"NO"',
      'DRUPAL_MAINTENANCE_MODE_CHANGE:"NONE"',
    ] as $evidence) {
      self::assertStringContainsString($evidence, $apply);
    }
    foreach ([
      '.FAILURE_PHASE == "PRE_MUTATION"',
      '.FAILURE_STAGE == "MAINTENANCE_WINDOW_CHECK"',
      '.MAINTENANCE_WINDOW_CHECK == "FAIL"',
      '.MAINTENANCE_WINDOW_REASON == "TOO_EARLY"',
      '.MAINTENANCE_WINDOW_REASON == "TOO_LATE"',
      '.MAINTENANCE_WINDOW_REASON == "INVALID"',
    ] as $contract) {
      self::assertStringContainsString($contract, $workflow);
    }
  }

  /**
   * One bounded primitive observes max_allowed_packet in every #1183 phase.
   */
  public function testMaxAllowedPacketUsesCanonicalObserver(): void {
    $observer = $this->source(self::MAX_PACKET_OBSERVER);
    $plan = $this->source(self::PLAN);
    $apply = $this->source(self::APPLY);
    $post = $this->source(self::POST);
    $workflow = $this->source(self::WORKFLOW);

    foreach ([$plan, $apply, $post] as $consumer) {
      self::assertStringContainsString('observe_max_allowed_packet', $consumer);
    }
    self::assertStringContainsString(self::MAX_PACKET_OBSERVER, $workflow);
    self::assertStringContainsString('vendor/bin/drush php:eval', $observer);
    self::assertStringContainsString('sudo -n mariadb -NBe', $observer);
    self::assertStringContainsString('DRUPAL_DB_API', $observer);
    self::assertStringContainsString('SUDO_MARIADB', $observer);
    self::assertStringNotContainsString(
      "drush sql:query 'SELECT @@global.max_allowed_packet;'",
      $apply . "\n" . $post,
    );
    foreach ([$apply, $post] as $strictConsumer) {
      self::assertStringContainsString(
        '[[ "$max_allowed_packet" == \'67108864\' ]]',
        $strictConsumer,
      );
    }
    foreach ([
      'MAX_ALLOWED_PACKET:$max_allowed_packet',
      'MAX_ALLOWED_PACKET_SOURCE:$max_allowed_packet_source',
    ] as $evidence) {
      self::assertStringContainsString($evidence, $apply);
    }
    self::assertStringContainsString(
      "'MAX_ALLOWED_PACKET_SOURCE': post['MAX_ALLOWED_PACKET_SOURCE']",
      $post,
    );
  }

  /**
   * Canonical observation preserves wrong values and fails closed on unknown.
   */
  public function testMaxAllowedPacketObserverDeterministicContract(): void {
    $observer = dirname(DRUPAL_ROOT) . '/' . self::MAX_PACKET_OBSERVER;
    self::assertFileExists($observer);

    $directory = sys_get_temp_dir()
      . '/agency-1183-max-packet-' . bin2hex(random_bytes(6));
    $drupalRoot = $directory . '/drupal';
    self::assertTrue(mkdir($drupalRoot . '/vendor/bin', 0700, TRUE));
    try {
      file_put_contents($drupalRoot . '/vendor/bin/drush', <<<'SH'
#!/usr/bin/env bash
case "$1" in
  status)
    exit "${FAKE_DRUSH_STATUS_RC:-0}"
    ;;
  php:eval)
    rc="${FAKE_DRUSH_QUERY_RC:-0}"
    [[ "$rc" -eq 0 ]] || exit "$rc"
    printf '%s\n' "${FAKE_DRUSH_PACKET:-67108864}"
    ;;
  *)
    exit 97
    ;;
esac
SH
      );
      chmod($drupalRoot . '/vendor/bin/drush', 0700);
      file_put_contents($directory . '/sudo', <<<'SH'
#!/usr/bin/env bash
[[ "${1:-}" == '-n' && "${2:-}" == 'mariadb' && "${3:-}" == '-NBe' ]] || exit 98
[[ "${4:-}" == 'SELECT @@global.max_allowed_packet;' ]] || exit 98
rc="${FAKE_SUDO_RC:-0}"
[[ "$rc" -eq 0 ]] || exit "$rc"
printf '%s\n' "${FAKE_SUDO_PACKET:-67108864}"
SH
      );
      chmod($directory . '/sudo', 0700);

      $cases = [
        'canonical' => [
          ['FAKE_DRUSH_PACKET' => '67108864', 'FAKE_SUDO_PACKET' => '16777216'],
          ['67108864', 'DRUPAL_DB_API'],
        ],
        'wrong_numeric_no_fallback' => [
          ['FAKE_DRUSH_PACKET' => '16777216', 'FAKE_SUDO_PACKET' => '67108864'],
          ['16777216', 'DRUPAL_DB_API'],
        ],
        'bounded_fallback' => [
          ['FAKE_DRUSH_QUERY_RC' => '1', 'FAKE_SUDO_PACKET' => '67108864'],
          ['67108864', 'SUDO_MARIADB'],
        ],
        'unknown' => [
          ['FAKE_DRUSH_QUERY_RC' => '1', 'FAKE_SUDO_RC' => '1'],
          ['UNKNOWN', 'UNKNOWN'],
        ],
      ];

      foreach ($cases as $label => [$environment, $expected]) {
        $environment['PATH'] = $directory . ':/usr/bin:/bin';
        $assignments = [];
        foreach ($environment as $name => $value) {
          $assignments[] = $name . '=' . escapeshellarg($value);
        }
        $command = 'env ' . implode(' ', $assignments)
          . ' bash -c '
          . escapeshellarg('source "$1"; observe_max_allowed_packet "$2"')
          . ' _ ' . escapeshellarg($observer)
          . ' ' . escapeshellarg($drupalRoot);
        $output = [];
        $status = 99;
        exec($command . ' 2>&1', $output, $status);
        self::assertSame(0, $status, $label . ': ' . implode("\n", $output));
        self::assertCount(2, $output, $label);
        self::assertSame(
          'MAX_ALLOWED_PACKET=' . $expected[0],
          $output[0],
          $label,
        );
        self::assertSame(
          'MAX_ALLOWED_PACKET_SOURCE=' . $expected[1],
          $output[1],
          $label,
        );
      }
    }
    finally {
      @unlink($directory . '/sudo');
      @unlink($drupalRoot . '/vendor/bin/drush');
      @rmdir($drupalRoot . '/vendor/bin');
      @rmdir($drupalRoot . '/vendor');
      @rmdir($drupalRoot);
      @rmdir($directory);
    }
  }

  /**
   * Maintenance mode is restored on pre-reboot failure or post-reboot success.
   */
  public function testMaintenanceLifecycleAndPostRebootGate(): void {
    $apply = $this->source(self::APPLY);
    $post = $this->source(self::POST);
    self::assertStringContainsString('state:set system.maintenance_mode 1', $apply);
    self::assertStringContainsString('[[ "$maintenance_now" == \'1\' ]]', $apply);
    self::assertStringContainsString('handle_pre_reboot_failure', $apply);
    self::assertStringContainsString('state:set system.maintenance_mode 0', $apply);
    self::assertStringContainsString('state:set system.maintenance_mode 0', $post);
    self::assertStringContainsString('[[ "$maintenance_after" == \'0\' ]]', $post);
    foreach ([
      'version_id="$(awk -F=',
      "$(uname -r)",
      'MAX_ALLOWED_PACKET',
      'systemctl is-active --quiet nginx',
      'systemctl is-active --quiet php8.4-fpm',
      'systemctl is-active --quiet mariadb',
      'CONTACT_FORM_SURFACE',
      'PUBLIC_HOME',
      'RECENT_NGINX_PHP_ERRORS',
      'CONFIG_STATUS',
      'CONFIG_AUTO_CORRECTION',
      'POST_REBOOT_VALIDATION',
      'VERSION_LOG_REQUIRED',
      'SNAPSHOT_RESTORE',
    ] as $required) {
      self::assertStringContainsString($required, $post);
    }
    self::assertStringContainsString("'KEEP_PREVIOUS_KERNEL': 'YES'", $post);
  }

  /**
   * Post-reboot update accounting compares observed sets only.
   */
  public function testPostRebootUpdateAccountingUsesObservedSets(): void {
    $post = $this->source(self::POST);
    self::assertSame(
      1,
      preg_match('/python3 - "\$APPROVED_PLAN" "\$post_plan" <<\'PY\'\n(.*?)\nPY\n/s', $post, $matches),
    );
    $directory = sys_get_temp_dir() . '/agency-1183-post-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $approved = [
        'MAIN_SHA' => str_repeat('a', 40),
        'PLAN_ID' => 'plan-1183-accounting-r1',
        'PLAN_DIGEST' => str_repeat('b', 64),
        'UPGRADABLE_PACKAGES' => [
          ['name' => 'existing-a'],
          ['name' => 'existing-b'],
        ],
      ];
      $postReceipt = [
        'UPGRADABLE_PACKAGES' => [
          ['name' => 'existing-a'],
          ['name' => 'existing-b'],
          ['name' => 'new-c'],
        ],
        'KERNEL_RUNNING' => '6.8.0-139-generic',
        'KERNEL_INSTALLED_LATEST' => '6.8.0-139-generic',
        'REBOOT_REQUIRED' => 'NO',
        'SECURITY_UPDATES_TOTAL' => 0,
        'FAILED_SYSTEMD_UNITS' => [],
        'NGINX_SERVICE' => 'ACTIVE',
        'PHP_FPM_SERVICE' => 'ACTIVE',
        'MARIADB_SERVICE' => 'ACTIVE',
        'DRUPAL_HEALTH' => 'PASS',
        'PUBLIC_HEALTH' => 'PASS',
        'CONFIG_STATUS' => 'DIFFERENT',
        'MAX_ALLOWED_PACKET' => '67108864',
        'MAX_ALLOWED_PACKET_SOURCE' => 'DRUPAL_DB_API',
        'PUBLIC_HOME' => 'PASS',
        'CONTACT_FORM_SURFACE' => 'PASS',
        'RECENT_NGINX_PHP_ERRORS' => 'NONE_MATERIAL',
      ];
      $approvedPath = $directory . '/approved.json';
      $postPath = $directory . '/post.json';
      $script = $directory . '/accounting.py';
      file_put_contents($approvedPath, json_encode($approved, JSON_THROW_ON_ERROR));
      file_put_contents($postPath, json_encode($postReceipt, JSON_THROW_ON_ERROR));
      file_put_contents($script, $matches[1] . "\n");
      $output = [];
      $status = 1;
      exec(
        'python3 ' . escapeshellarg($script) . ' '
        . escapeshellarg($approvedPath) . ' ' . escapeshellarg($postPath),
        $output,
        $status,
      );
      self::assertSame(0, $status, implode("\n", $output));
      $result = json_decode(implode("\n", $output), TRUE, 32, JSON_THROW_ON_ERROR);
      self::assertSame(['new-c'], $result['NEW_UPDATES_AFTER_PLAN']);
      self::assertSame('NONE', $result['PACKAGE_APPLY']);
      self::assertSame('NOT_REQUIRED', $result['PACKAGE_APPLY_SUCCESS']);
      self::assertSame('NOT_REQUIRED', $result['SECOND_EXACT_APT_SIMULATION']);
      self::assertSame('NONE', $result['REAL_PACKAGE_MUTATION']);
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($directory);
    }
  }

  /**
   * Consumed PLAN/APPLY authorities cannot satisfy the fresh immutable gate.
   */
  public function testConsumedAuthoritiesCannotBeReused(): void {
    $workflow = $this->source(self::WORKFLOW);
    foreach ([
      'test "$(jq -r \'.conclusion\' <<<"$run_json")" = \'success\'',
      'test "$(jq -r \'.event\' <<<"$run_json")" = \'issue_comment\'',
      'test "$(jq -r \'.head_sha\' <<<"$run_json")" = "$MAIN_SHA"',
      'and .MAIN_SHA == $main',
      'and .PLAN_DIGEST == $digest',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
    self::assertStringNotContainsString('35213855672', $workflow);
    self::assertStringNotContainsString('35147468119', $workflow);
  }

  /**
   * The stale-plan digest excludes healthy disk fluctuation but not real drift.
   */
  public function testStableDigestAndMaterialDrift(): void {
    $first = $this->evaluatePlan(13696200);
    $second = $this->evaluatePlan(13695000);
    self::assertSame(13696200, $first['DISK_AVAILABLE_KB']);
    self::assertSame(13695000, $second['DISK_AVAILABLE_KB']);
    self::assertSame($first['PLAN_DIGEST'], $second['PLAN_DIGEST']);

    $normalUpdateDrift = $this->evaluatePlan(13696200, '1.24.0-2ubuntu7.19');
    self::assertSame($first['PLAN_DIGEST'], $normalUpdateDrift['PLAN_DIGEST']);

    $kernelDrift = $this->evaluatePlan(
      13696200,
      '1.24.0-2ubuntu7.18',
      ['KERNEL_RUNNING' => '6.8.0-136-generic'],
    );
    self::assertNotSame($first['PLAN_DIGEST'], $kernelDrift['PLAN_DIGEST']);
  }

  /**
   * Runtime/service/version boundaries fail closed before an approved PLAN.
   */
  public function testUnsafeRuntimeAndServiceDriftFailsClosed(): void {
    foreach ([
      ['NGINX_SERVICE' => 'inactive'],
      ['VERSION_ID' => '26.04'],
      ['PHP_BRANCH' => '8.5'],
      ['MARIADB_BRANCH' => '12.0'],
      ['MAX_ALLOWED_PACKET' => '16777216'],
      [
        'MAX_ALLOWED_PACKET' => 'UNKNOWN',
        'MAX_ALLOWED_PACKET_SOURCE' => 'UNKNOWN',
      ],
      ['DRUPAL_HEALTH' => 'FAIL'],
      ['PUBLIC_HOME' => 'FAIL'],
      ['CONTACT_FORM_SURFACE' => 'FAIL'],
    ] as $drift) {
      $failure = $this->executePlan(13696200, '1.24.0-2ubuntu7.18', $drift);
      self::assertNotSame(0, $failure['status']);
      self::assertStringContainsString('PLAN safety gate failed:', $failure['output']);
    }
  }

  /**
   * Low disk still fails closed and actual disk remains a receipt observation.
   */
  public function testLowDiskFailsClosedAndDigestAllowlistExcludesDisk(): void {
    $failure = $this->executePlan((2 * 1024 * 1024) - 1);
    self::assertNotSame(0, $failure['status']);
    self::assertStringContainsString('disk_space_min_2gib', $failure['output']);

    $source = $this->source(self::PLAN);
    self::assertStringContainsString(
      "'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB'])",
      $source,
    );
    self::assertStringContainsString('mutation_identity_keys = (', $source);
    $identity = strstr($source, 'mutation_identity_keys = (');
    self::assertIsString($identity);
    $identity = strstr($identity, ")\nreceipt['PLAN_DIGEST']", TRUE);
    self::assertIsString($identity);
    self::assertStringNotContainsString("'DISK_AVAILABLE_KB'", $identity);
    foreach ([
      "'MAIN_SHA'",
      "'PLAN_ID'",
      "'PACKAGE_UPGRADES'",
      "'KERNEL_RUNNING'",
      "'MAX_ALLOWED_PACKET'",
      "'PUBLIC_HOME'",
      "'CONTACT_FORM_SURFACE'",
      "'RECENT_NGINX_PHP_ERRORS'",
      "'SAFETY_GATE'",
    ] as $key) {
      self::assertStringContainsString($key, $identity);
    }
  }

  /**
   * Executes the actual embedded PLAN evaluator on synthetic bounded evidence.
   */
  private function evaluatePlan(
    int $diskAvailableKb,
    string $candidate = '1.24.0-2ubuntu7.18',
    array $overrides = [],
  ): array {
    $result = $this->executePlan($diskAvailableKb, $candidate, $overrides);
    self::assertSame(0, $result['status'], $result['output']);
    $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($receipt);
    return $receipt;
  }

  /**
   * Executes the embedded Python digest/safety evaluator only.
   */
  private function executePlan(
    int $diskAvailableKb,
    string $candidate = '1.24.0-2ubuntu7.18',
    array $overrides = [],
  ): array {
    $source = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match("/python3 - <<'PY'\\n(.*?)\\nPY\\n/s", $source, $matches),
    );

    $directory = sys_get_temp_dir() . '/agency-1183-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      file_put_contents(
        $directory . '/upgradable.raw',
        "Listing...\nnginx/noble-updates {$candidate} amd64 [upgradable from: 1.24.0-2ubuntu7.17]\n",
      );
      file_put_contents(
        $directory . '/upgrade-sim.raw',
        "Inst nginx [1.24.0-2ubuntu7.17] ({$candidate} Ubuntu:24.04/noble-updates [amd64])\n",
      );
      file_put_contents(
        $directory . '/upgrade-sim-phased.raw',
        "Inst nginx [1.24.0-2ubuntu7.17] ({$candidate} Ubuntu:24.04/noble-updates [amd64])\n",
      );
      file_put_contents($directory . '/held.raw', '');
      file_put_contents($directory . '/failed.raw', '');
      $script = $directory . '/plan.py';
      file_put_contents($script, $matches[1] . "\n");
      file_put_contents($directory . '/sudo', <<<'SH'
#!/usr/bin/env bash
[[ "$1 $2 $3 $4" == '-k -n -ll --' ]] || exit 99
shift 4
printf 'Sudoers entry:\n    RunAsUsers: root\n    Options: !authenticate, !setenv\n    Commands:\n        %s\n    Matched: %s\n' "$*" "$*"
SH
      );
      chmod($directory . '/sudo', 0700);
      $environment = array_replace([
        'PATH' => $directory . ':/usr/bin:/bin',
        'WORK_ROOT' => $directory,
        'MAIN_SHA' => str_repeat('a', 40),
        'PLAN_ID' => 'plan-1183-deterministic-fixture-r1',
        'ISSUE' => '1183',
        'TARGET' => 'PROD',
        'MODE' => 'PLAN',
        'PLAN_CONTEXT' => 'PLAN',
        'TARGET_KERNEL' => '6.8.0-139-generic',
        'OS_PRETTY_NAME' => 'Ubuntu 24.04.5 LTS',
        'VERSION_ID' => '24.04',
        'KERNEL_RUNNING' => '6.8.0-137-generic',
        'KERNEL_INSTALLED_LATEST' => '6.8.0-139-generic',
        'REBOOT_REQUIRED' => 'YES',
        'PHP_BRANCH' => '8.4',
        'MARIADB_BRANCH' => '11.8',
        'NGINX_SERVICE' => 'active',
        'PHP_FPM_SERVICE' => 'active',
        'MARIADB_SERVICE' => 'active',
        'DRUPAL_HEALTH' => 'PASS',
        'PUBLIC_HEALTH' => 'PASS',
        'MAINTENANCE_MODE' => '0',
        'CONFIG_STATUS' => 'DIFFERENT',
        'MAX_ALLOWED_PACKET' => '67108864',
        'MAX_ALLOWED_PACKET_SOURCE' => 'DRUPAL_DB_API',
        'PUBLIC_HOME' => 'PASS',
        'PUBLIC_HOME_HTTP_CODE' => '200',
        'PUBLIC_HOME_EFFECTIVE_PATH' => '/fr',
        'CONTACT_FORM_SURFACE' => 'PASS',
        'RECENT_ERROR_READ_CAPABILITY' => 'PASS',
        'RECENT_NGINX_PHP_ERRORS' => 'NONE_MATERIAL',
        'NGINX_RECENT_ERROR_COUNT' => '0',
        'PHP_FPM_RECENT_ERROR_COUNT' => '0',
        'DISK_AVAILABLE_KB' => (string) $diskAvailableKb,
      ], $overrides);
      $assignments = [];
      foreach ($environment as $name => $value) {
        $assignments[] = $name . '=' . escapeshellarg((string) $value);
      }
      $command = 'env ' . implode(' ', $assignments)
        . ' python3 ' . escapeshellarg($script) . ' 2>&1';
      $output = [];
      $status = 1;
      exec($command, $output, $status);
      return [
        'status' => $status,
        'output' => implode("\n", $output),
      ];
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $path) {
        @unlink($path);
      }
      @rmdir($directory);
    }
  }

  /**
   * The local harness proves bounded pre-reboot recovery semantics.
   */
  public function testPreRebootFailureRecoveryHarness(): void {
    $script = dirname(DRUPAL_ROOT)
      . '/scripts/production-maintenance-1183/tests/test_pre_reboot_recovery.py';
    self::assertFileExists($script);
    $output = [];
    $status = 1;
    exec('python3 ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
  }

  /**
   * Both workflow files parse structurally.
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

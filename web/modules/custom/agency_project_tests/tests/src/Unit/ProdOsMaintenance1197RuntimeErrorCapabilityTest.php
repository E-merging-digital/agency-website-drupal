<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded PROD runtime-error capability introduced by #1197.
 *
 * @group agency_project_tests
 */
final class ProdOsMaintenance1197RuntimeErrorCapabilityTest extends TestCase {

  private const BASE = 'scripts/production-maintenance-1183/runtime-error-counts';
  private const HELPER = self::BASE . '/agency-prod-runtime-error-counts';
  private const SUDOERS = self::BASE . '/agency-prod-runtime-error-counts.sudoers.template';
  private const RENDER = self::BASE . '/render-sudoers.sh';
  private const VERIFY = self::BASE . '/verify-installed-root.sh';
  private const PROFILE = self::BASE . '/capability.json';
  private const PLAN = 'scripts/production-maintenance-1183/remote-plan.sh';
  private const POST = 'scripts/production-maintenance-1183/remote-post-reboot.sh';

  /**
   * Helper input and privileged source selection are fully fixed.
   */
  public function testHelperHasNoCallerControlledLogSurface(): void {
    $helper = $this->source(self::HELPER);
    self::assertStringContainsString('if (( $# != 0 )); then', $helper);
    self::assertStringContainsString('count_fixed_unit nginx', $helper);
    self::assertStringContainsString('count_fixed_unit php8.4-fpm', $helper);
    self::assertStringContainsString("--since '30 minutes ago'", $helper);
    self::assertStringContainsString("--priority 'err..alert'", $helper);
    self::assertStringContainsString('--output=json', $helper);
    self::assertStringNotContainsString('--output cat', $helper);
    self::assertStringNotContainsString('eval ', $helper);
    self::assertStringNotContainsString('${1:-}', $helper);
    self::assertStringNotContainsString('${2:-}', $helper);
    self::assertStringNotContainsString('NGINX_RECENT_ERROR_LINES', $helper);
    self::assertStringNotContainsString('PHP_FPM_RECENT_ERROR_LINES', $helper);

    $profile = $this->profile();
    self::assertSame(1197, $profile['issue']);
    self::assertSame('EXTEND_EXISTING', $profile['decision']);
    self::assertSame('NONE', $profile['helper']['arguments']);
    self::assertSame(['nginx', 'php8.4-fpm'], $profile['helper']['sources']);
    self::assertSame('30 minutes', $profile['helper']['window']);
    self::assertSame('err..alert', $profile['helper']['severity']);
    self::assertSame('FORBIDDEN', $profile['helper']['raw_log_output']);
  }

  /**
   * Helper emits only bounded counts for healthy or material-error states.
   */
  public function testHelperReturnsBoundedCountsOnly(): void {
    $zero = $this->runHelper(0, 0);
    self::assertSame(0, $zero['status']);
    self::assertSame([
      'STATUS=PASS',
      'NGINX_RECENT_ERROR_COUNT=0',
      'PHP_FPM_RECENT_ERROR_COUNT=0',
    ], $zero['lines']);

    $nginx = $this->runHelper(2, 0);
    self::assertSame(0, $nginx['status']);
    self::assertSame('NGINX_RECENT_ERROR_COUNT=2', $nginx['lines'][1]);

    $php = $this->runHelper(0, 3);
    self::assertSame(0, $php['status']);
    self::assertSame('PHP_FPM_RECENT_ERROR_COUNT=3', $php['lines'][2]);

    foreach ([$zero, $nginx, $php] as $result) {
      $joined = implode("\n", $result['lines']);
      self::assertStringNotContainsString('request=', $joined);
      self::assertStringNotContainsString('/var/log/', $joined);
      self::assertStringNotContainsString('stack', strtolower($joined));
    }
  }

  /**
   * Any source-read failure or caller argument fails closed without raw logs.
   */
  public function testHelperFailureAndArgumentsFailClosed(): void {
    $failed = $this->runHelper(0, 0, 'nginx');
    self::assertNotSame(0, $failed['status']);
    self::assertSame([
      'STATUS=FAIL',
      'NGINX_RECENT_ERROR_COUNT=UNKNOWN',
      'PHP_FPM_RECENT_ERROR_COUNT=UNKNOWN',
    ], $failed['lines']);

    $argument = $this->runHelper(0, 0, '', ['nginx']);
    self::assertSame(64, $argument['status']);
    self::assertSame('STATUS=FAIL', $argument['lines'][0]);
  }

  /**
   * PLAN maps governed helper output to explicit safety classifications.
   */
  public function testPlanObservationIsFailClosedAndNeverMapsUnknownToZero(): void {
    self::assertSame(
      ['PASS', '0', '0', 'NONE_MATERIAL'],
      $this->runPlanObservation("STATUS=PASS\nNGINX_RECENT_ERROR_COUNT=0\nPHP_FPM_RECENT_ERROR_COUNT=0"),
    );
    self::assertSame(
      ['PASS', '2', '0', 'PRESENT_MATERIAL'],
      $this->runPlanObservation("STATUS=PASS\nNGINX_RECENT_ERROR_COUNT=2\nPHP_FPM_RECENT_ERROR_COUNT=0"),
    );
    self::assertSame(
      ['PASS', '0', '4', 'PRESENT_MATERIAL'],
      $this->runPlanObservation("STATUS=PASS\nNGINX_RECENT_ERROR_COUNT=0\nPHP_FPM_RECENT_ERROR_COUNT=4"),
    );
    self::assertSame(
      ['FAIL', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN'],
      $this->runPlanObservation('STATUS=FAIL', 1),
    );
    self::assertSame(
      ['FAIL', 'UNKNOWN', 'UNKNOWN', 'UNKNOWN'],
      $this->runPlanObservation("STATUS=PASS\nNGINX_RECENT_ERROR_COUNT=oops\nPHP_FPM_RECENT_ERROR_COUNT=0"),
    );
  }

  /**
   * Sudoers and installed-file verification stay exact and non-generic.
   */
  public function testSudoersAndOwnershipContractsAreNarrow(): void {
    $template = trim($this->source(self::SUDOERS));
    $expectedTemplate = '__SERVER_USER__ ALL=(root) NOPASSWD: NOSETENV: '
      . '/usr/local/sbin/agency-prod-runtime-error-counts';
    self::assertSame($expectedTemplate, $template);
    self::assertStringNotContainsString('NOPASSWD: ALL', $template);
    self::assertStringNotContainsString('*', $template);
    foreach (['journalctl', 'tail', 'cat', 'grep', 'awk', 'bash', ' sh ', 'python', 'env '] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $template);
    }

    $rendered = $this->runCommand('bash ' . escapeshellarg($this->path(self::RENDER)) . ' agency-prod');
    self::assertSame(0, $rendered['status']);
    self::assertSame(
      'agency-prod ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-runtime-error-counts',
      trim($rendered['stdout']),
    );
    $verify = $this->source(self::VERIFY);
    self::assertStringContainsString("root:root:755", $verify);
    self::assertStringContainsString("root:root:440", $verify);
    self::assertStringContainsString('/usr/sbin/visudo -cf "$SUDOERS_PATH"', $verify);
    self::assertStringContainsString('[[ -f "$HELPER_PATH" && ! -L "$HELPER_PATH" ]]', $verify);
    self::assertStringContainsString('[[ -f "$SUDOERS_PATH" && ! -L "$SUDOERS_PATH" ]]', $verify);

    $profile = $this->profile();
    self::assertSame('root', $profile['helper']['owner']);
    self::assertSame('root', $profile['helper']['group']);
    self::assertSame('0755', $profile['helper']['mode']);
    self::assertSame('root', $profile['sudoers']['owner']);
    self::assertSame('root', $profile['sudoers']['group']);
    self::assertSame('0440', $profile['sudoers']['mode']);
    self::assertSame('FIXED_HELPER_ONLY', $profile['sudoers']['nopasswd_scope']);
    self::assertTrue($profile['sudoers']['no_setenv']);
    self::assertSame('FORBIDDEN', $profile['sudoers']['nopasswd_all']);
    self::assertSame('NONE', $profile['sudoers']['generic_privilege']);
  }

  /**
   * PLAN and post-reboot use the same fixed helper, never generic journal sudo.
   */
  public function testPlanAndPostRebootConvergeOnGovernedHelper(): void {
    $plan = $this->source(self::PLAN);
    $post = $this->source(self::POST);
    foreach ([$plan, $post] as $source) {
      self::assertStringContainsString(
        "RUNTIME_ERROR_HELPER='/usr/local/sbin/agency-prod-runtime-error-counts'",
        $source,
      );
      self::assertStringContainsString('sudo -n -- "$RUNTIME_ERROR_HELPER"', $source);
      self::assertStringNotContainsString('sudo -n journalctl', $source);
      self::assertStringNotContainsString('--output=cat', $source);
    }
    self::assertStringContainsString('[[ "$nginx_recent_error_count" -eq 0 ]]', $post);
    self::assertStringContainsString('[[ "$php_fpm_recent_error_count" -eq 0 ]]', $post);
    $observe = strpos($post, 'sudo -n -- "$RUNTIME_ERROR_HELPER"');
    $maintenanceOff = strpos($post, 'state:set system.maintenance_mode 0');
    self::assertNotFalse($observe);
    self::assertNotFalse($maintenanceOff);
    self::assertLessThan($maintenanceOff, $observe);
  }

  /**
   * Repository records that real PROD installation remains separate.
   */
  public function testRealHostProvisioningRemainsSeparate(): void {
    $profile = $this->profile();
    self::assertTrue($profile['provisioning']['real_host_install_required']);
    self::assertFalse($profile['provisioning']['real_host_install_executed']);
    self::assertSame('NONE_FOUND', $profile['provisioning']['prod_root_provisioning_route']);
    self::assertSame(
      'NO_GOVERNED_PROD_ROOT_PROVISIONING_TRANSPORT_FOR_ROOT_OWNED_HELPER_AND_SUDOERS',
      $profile['provisioning']['missing_real_apply_route'],
    );
  }

  /**
   * Executes the helper with a deterministic fake journal implementation.
   */
  private function runHelper(
    int $nginxCount,
    int $phpCount,
    string $failUnit = '',
    array $arguments = [],
  ): array {
    $directory = sys_get_temp_dir() . '/agency-1197-helper-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $journal = $directory . '/journalctl';
      file_put_contents($journal, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
unit=''
since=''
priority=''
output=''
no_pager='NO'
while (( $# > 0 )); do
  case "$1" in
    --unit) unit="$2"; shift 2 ;;
    --since) since="$2"; shift 2 ;;
    --priority) priority="$2"; shift 2 ;;
    --no-pager) no_pager='YES'; shift ;;
    --output) output="$2"; shift 2 ;;
    --output=*) output="${1#--output=}"; shift ;;
    *) exit 91 ;;
  esac
done
[[ "$since" == '30 minutes ago' ]]
[[ "$priority" == 'err..alert' ]]
[[ "$output" == 'json' ]]
[[ "$no_pager" == 'YES' ]]
[[ "$unit" == 'nginx' || "$unit" == 'php8.4-fpm' ]]
[[ "$FAKE_FAIL_UNIT" != "$unit" ]] || exit 92
count="$FAKE_NGINX_COUNT"
[[ "$unit" != 'php8.4-fpm' ]] || count="$FAKE_PHP_COUNT"
for (( i = 0; i < count; i++ )); do
  printf '%s\n' '{}'
done
SH
      );
      chmod($journal, 0700);
      $helper = $directory . '/helper';
      $source = str_replace('/usr/bin/journalctl', $journal, $this->source(self::HELPER));
      file_put_contents($helper, $source);
      chmod($helper, 0700);
      $command = 'env FAKE_NGINX_COUNT=' . $nginxCount
        . ' FAKE_PHP_COUNT=' . $phpCount
        . ' FAKE_FAIL_UNIT=' . escapeshellarg($failUnit)
        . ' bash ' . escapeshellarg($helper);
      foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg((string) $argument);
      }
      $output = [];
      $status = 1;
      exec($command . ' 2>&1', $output, $status);
      return ['status' => $status, 'lines' => $output];
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($directory);
    }
  }

  /**
   * Executes only the #1197 PLAN observation block with a fake sudo binary.
   */
  private function runPlanObservation(string $helperOutput, int $helperStatus = 0): array {
    $source = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match(
        '/# BEGIN #1197 GOVERNED RUNTIME ERROR OBSERVATION\n(.*?)# END #1197 GOVERNED RUNTIME ERROR OBSERVATION/s',
        $source,
        $matches,
      ),
    );
    $directory = sys_get_temp_dir() . '/agency-1197-plan-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $sudo = $directory . '/sudo';
      file_put_contents($sudo, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
[[ "$#" -eq 3 ]]
[[ "$1" == '-n' ]]
[[ "$2" == '--' ]]
[[ "$3" == '/usr/local/sbin/agency-prod-runtime-error-counts' ]]
printf '%b' "$FAKE_HELPER_OUTPUT"
exit "$FAKE_HELPER_STATUS"
SH
      );
      chmod($sudo, 0700);
      $runner = $directory . '/runner.sh';
      file_put_contents(
        $runner,
        "#!/usr/bin/env bash\nset -Eeuo pipefail\n"
        . "RUNTIME_ERROR_HELPER='/usr/local/sbin/agency-prod-runtime-error-counts'\n"
        . "recent_errors='UNKNOWN'\nrecent_error_read_capability='FAIL'\nnginx_recent_error_count='UNKNOWN'\nphp_fpm_recent_error_count='UNKNOWN'\n"
        . $matches[1]
        . "\nprintf '%s|%s|%s|%s\\n' \"\$recent_error_read_capability\" \"\$nginx_recent_error_count\" \"\$php_fpm_recent_error_count\" \"\$recent_errors\"\n",
      );
      chmod($runner, 0700);
      $command = 'env PATH=' . escapeshellarg(
        $directory . ':/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
      )
        . ' FAKE_HELPER_OUTPUT=' . escapeshellarg($helperOutput)
        . ' FAKE_HELPER_STATUS=' . $helperStatus
        . ' bash ' . escapeshellarg($runner) . ' 2>&1';
      $output = [];
      $status = 1;
      exec($command, $output, $status);
      self::assertSame(0, $status, implode("\n", $output));
      return explode('|', trim(implode("\n", $output)));
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($directory);
    }
  }

  /**
   * Runs a local command and returns bounded output.
   */
  private function runCommand(string $command): array {
    $output = [];
    $status = 1;
    exec($command . ' 2>&1', $output, $status);
    return [
      'status' => $status,
      'stdout' => implode("\n", $output),
    ];
  }

  /**
   * Reads the source-controlled #1197 capability profile.
   */
  private function profile(): array {
    $profile = json_decode($this->source(self::PROFILE), TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($profile);
    return $profile;
  }

  /**
   * Resolves one repository-relative path.
   */
  private function path(string $relativePath): string {
    return dirname(DRUPAL_ROOT) . '/' . $relativePath;
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    $path = $this->path($relativePath);
    self::assertFileExists($path);
    return (string) file_get_contents($path);
  }

}

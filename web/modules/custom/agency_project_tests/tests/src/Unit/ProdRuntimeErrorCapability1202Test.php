<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects #1202 provisioning using local synthetic evidence only.
 *
 * @group agency_project_tests
 */
final class ProdRuntimeErrorCapability1202Test extends TestCase {

  private const WORKFLOW = '.github/workflows/prod-runtime-error-capability-1202.yml';
  private const BASE = 'scripts/production-maintenance-1183/runtime-error-counts/';
  private const COMMAND = '/agency-prod-runtime-error-capability-1202 ';

  /**
   * Issue #1202 bypasses the historical route matrix using bounded outer gates.
   */
  public function testDispatcher(): void {
    $dispatcher = Yaml::parseFile(dirname(DRUPAL_ROOT) . '/.github/workflows/agency-command-dispatch.yml');
    $routes = json_decode($dispatcher['env']['AGENCY_COMMAND_ROUTES'], TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertCount(14, $routes);
    foreach ($routes as $route) {
      self::assertStringNotContainsString('1202', $route['route']);
      self::assertStringNotContainsString('/agency-prod-runtime-error-capability-1202 ', $route['prefix']);
    }

    $jobs = $dispatcher['jobs'];
    $keys = array_keys($jobs);
    self::assertSame(
      array_search('prod-runtime-error-capability-1202-plan', $keys, TRUE) + 1,
      array_search('prod-runtime-error-capability-1202-apply', $keys, TRUE),
    );

    foreach (['plan', 'apply'] as $mode) {
      $job = $jobs['prod-runtime-error-capability-1202-' . $mode];
      self::assertSame('./' . self::WORKFLOW, $job['uses']);
      self::assertSame('classify', $job['needs']);
      self::assertSame(['actions' => 'read', 'contents' => 'read', 'issues' => 'write'], $job['permissions']);
      self::assertSame(['SSH_PRIVATE_KEY', 'SERVER_HOST', 'SERVER_USER'], array_keys($job['secrets']));
      foreach ($job['secrets'] as $name => $value) {
        self::assertSame('${{ secrets.' . $name . ' }}', $value);
      }
      foreach ([
        "github.event_name == 'issue_comment'",
        "github.event.action == 'created'",
        'github.event.issue.pull_request == null',
        'github.event.issue.number == 1202',
        "github.event.comment.author_association == 'OWNER'",
        "github.event.comment.user.login == 'E-merging-digital'",
        'github.event.comment.performed_via_github_app == null',
      ] as $gate) {
        self::assertStringContainsString($gate, $job['if']);
      }
      self::assertStringNotContainsString('needs.classify.outputs.route', $job['if']);
    }

    self::assertStringContainsString(
      "github.event.comment.body == '/agency-prod-runtime-error-capability-1202 plan'",
      $jobs['prod-runtime-error-capability-1202-plan']['if'],
    );
    self::assertStringNotContainsString(
      'startsWith(',
      $jobs['prod-runtime-error-capability-1202-plan']['if'],
    );
    self::assertStringContainsString(
      "startsWith(github.event.comment.body, '/agency-prod-runtime-error-capability-1202 apply ')",
      $jobs['prod-runtime-error-capability-1202-apply']['if'],
    );

    $script = $jobs['classify']['steps'][0]['run'];
    self::assertStringNotContainsString('PROD_RUNTIME_ERROR_CAPABILITY_1202', $script);
    self::assertStringNotContainsString('/agency-prod-runtime-error-capability-1202 ', $script);

    foreach ([
      self::COMMAND . 'plan',
      self::COMMAND . 'apply plan_run=123 plan_digest=' . str_repeat('a', 64),
    ] as $body) {
      $result = $this->runLocal(['bash'], $script, [
        'EVENT_NAME' => 'issue_comment',
        'EVENT_ACTION' => 'created',
        'COMMENT_BODY' => $body,
        'ISSUE_NUMBER' => '1202',
        'ISSUE_TITLE' => '',
        'ISSUE_BODY' => '',
        'IS_PULL_REQUEST' => 'false',
        'ROUTES_JSON' => $dispatcher['env']['AGENCY_COMMAND_ROUTES'],
        'GITHUB_OUTPUT' => '/dev/null',
      ]);
      self::assertSame(0, $result['status'], $result['error']);
      self::assertSame('ROUTE=NONE', trim($result['output']));
    }
  }

  /**
   * Reusable workflow owns human/live-main and immutable run/hash validation.
   */
  public function testWorkflowBindings(): void {
    $source = $this->source(self::WORKFLOW);
    $workflow = Yaml::parseFile(dirname(DRUPAL_ROOT) . '/' . self::WORKFLOW);
    self::assertSame(['workflow_call'], array_keys($workflow['on']));
    foreach (['plan' => 'PLAN', 'apply' => 'APPLY'] as $job => $mode) {
      self::assertSame("\${{ needs.validate-authority.outputs.mode == '$mode' }}", $workflow['jobs'][$job]['if']);
      self::assertSame('validate-authority', $workflow['jobs'][$job]['needs']);
      self::assertSame('${{ needs.validate-authority.outputs.main_sha }}', $workflow['jobs'][$job]['steps'][0]['with']['ref']);
    }
    foreach ([
      'test "$COMMENT_USER_TYPE" = \'User\'',
      'test "$COMMENT_VIA_APP" = \'false\'',
      'test "$WORKFLOW_SHA" = "$main_sha"',
      'git/ref/heads/main',
      'test "$GITHUB_RUN_ATTEMPT" = \'1\'',
      "'.head_sha'",
      "'.conclusion'",
      'prod-runtime-error-capability-1202-plan-${PLAN_RUN}-1',
      '.PLAN_ID == ("plan-1202-" + $run + "-1")',
      '.PLAN_DIGEST == $digest',
      '.HELPER_SOURCE_SHA256 == $helper',
      '.RENDERED_SUDOERS_SHA256 == $sudoers',
      '.SERVER_USER_SHA256 == $user',
      'AGENCY_1202_PLAN_CONSUMED',
      'secrets.SERVER_USER',
      '"$SERVER_USER@$SERVER_HOST"',
      'StrictHostKeyChecking=yes',
    ] as $binding) {
      self::assertStringContainsString($binding, $source);
    }
    $apply = substr($source, strpos($source, "\n  apply:"));
    $this->assertBefore('Revalidate exact repository bindings', 'Materialize pinned PROD identity', $apply);
    self::assertStringNotContainsString('PREPROD_', $source);
  }

  /**
   * Executes the actual human/live-main gate with a local GitHub API stub.
   */
  public function testAuthorityRejectsWrongActorAppEventAndStaleMain(): void {
    $workflow = Yaml::parseFile(dirname(DRUPAL_ROOT) . '/' . self::WORKFLOW);
    $script = $workflow['jobs']['validate-authority']['steps'][0]['run'];
    $stub = <<<'SH'
gh() {
  case "$*" in
    'api repos/fixture/repository/issues/1202') printf '%s' '{"state":"open","labels":[{"name":"P1"}]}' ;;
    'api repos/fixture/repository/git/ref/heads/main --jq .object.sha') printf '%s' "$LIVE_MAIN" ;;
    *) return 99 ;;
  esac
}
SH;
    $environment = [
      'GITHUB_RUN_ATTEMPT' => '1',
      'GITHUB_RUN_ID' => '42',
      'GITHUB_REPOSITORY' => 'fixture/repository',
      'GITHUB_OUTPUT' => '/dev/null',
      'EVENT_NAME' => 'issue_comment',
      'EVENT_ACTION' => 'created',
      'ISSUE_NUMBER' => '1202',
      'IS_PULL_REQUEST' => 'false',
      'COMMENT_LOGIN' => 'E-merging-digital',
      'COMMENT_USER_TYPE' => 'User',
      'COMMENT_ASSOCIATION' => 'OWNER',
      'COMMENT_VIA_APP' => 'false',
      'COMMENT_BODY' => self::COMMAND . 'plan',
      'WORKFLOW_SHA' => str_repeat('a', 40),
      'LIVE_MAIN' => str_repeat('a', 40),
    ];
    self::assertSame(0, $this->runLocal(['bash'], $stub . "\n" . $script, $environment)['status']);
    $apply = self::COMMAND . 'apply plan_run=1 plan_digest=' . str_repeat('a', 64);
    self::assertSame(0, $this->runLocal(['bash'], $stub . "\n" . $script, array_replace($environment, ['COMMENT_BODY' => $apply]))['status']);
    foreach ([
      'GITHUB_RUN_ATTEMPT' => '2',
      'EVENT_NAME' => 'workflow_dispatch',
      'EVENT_ACTION' => 'edited',
      'ISSUE_NUMBER' => '1183',
      'IS_PULL_REQUEST' => 'true',
      'COMMENT_LOGIN' => 'other-owner',
      'COMMENT_USER_TYPE' => 'Bot',
      'COMMENT_ASSOCIATION' => 'MEMBER',
      'COMMENT_VIA_APP' => 'true',
      'LIVE_MAIN' => str_repeat('b', 40),
      'COMMENT_BODY' => $apply . ' extra',
    ] as $key => $value) {
      self::assertNotSame(0, $this->runLocal(['bash'], $stub . "\n" . $script, array_replace($environment, [$key => $value]))['status'], $key);
    }
  }

  /**
   * Real probe functions inspect temporary files and a synthetic sudo result.
   */
  public function testTargetClassificationAndPrivilegeProbe(): void {
    $plan = $this->source(self::BASE . 'remote-provision-plan.sh');
    $start = strpos($plan, 'probe_exact_sudo() {');
    $end = strpos($plan, 'MAIN_SHA="${1:-}"');
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $functions = substr($plan, $start, $end - $start);
    $file = tempnam(sys_get_temp_dir(), 'agency-1202-probe-');
    self::assertNotFalse($file);
    try {
      file_put_contents($file, 'fixture');
      foreach ([
        [$file . '-absent', 'root:root:755', 0, 'ABSENT'],
        [$file, 'root:root:755', 0, 'ALREADY_CONFORMANT'],
        [$file, 'root:root:777', 0, 'NONCONFORMANT'],
        [$file, 'root:root:755', 1, 'UNKNOWN'],
      ] as [$path, $metadata, $rc, $expected]) {
        $result = $this->runLocal(['bash'], "set -Eeuo pipefail\n" . $functions . <<<'SH'
stat() { printf '%s' "$FIXTURE_META"; return "$FIXTURE_RC"; }
classify_target "$FIXTURE_PATH" "$FIXTURE_HASH" root:root:755
SH,
          [
            'FIXTURE_PATH' => $path,
            'FIXTURE_HASH' => hash('sha256', 'fixture'),
            'FIXTURE_META' => $metadata,
            'FIXTURE_RC' => (string) $rc,
          ],
        );
        self::assertSame(0, $result['status']);
        self::assertSame($expected, $result['output']);
      }
    }
    finally {
      unlink($file);
    }
  }

  /**
   * Private policy parsing rejects listing and credential false positives.
   */
  public function testExactInstallPolicy(): void {
    $source = $this->source(self::BASE . 'remote-provision-plan.sh');
    $start = strpos($source, 'probe_exact_sudo() {');
    $end = strpos($source, 'MAIN_SHA="${1:-}"');
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $functions = substr($source, $start, $end - $start);
    $reasons = [
      'WRONG_DENIAL_COMMAND' => 'STDERR_PRESENT',
      'DENIAL_WITH_POLICY' => 'STDERR_PRESENT',
      'LISTPW_FAILURE' => 'STDERR_PRESENT',
      'RAW_STDERR_PRIVATE' => 'STDERR_PRESENT',
      'MISSING_SUDO' => 'STDERR_PRESENT',
      'ADVERSARIAL_STDERR' => 'STDERR_PRESENT',
      'DENIAL_WITH_AUTH_FAILURE' => 'STDERR_PASSWORD_REQUIRED',
      'PASSWORD_REQUIRED' => 'STDERR_PASSWORD_REQUIRED',
      'LISTPW_ANY_FALSE_POSITIVE' => 'POLICY_FORMAT_UNSUPPORTED',
      'UNSUPPORTED_FORMAT' => 'POLICY_FORMAT_UNSUPPORTED',
      'ADVERSARIAL_POLICY' => 'POLICY_FORMAT_UNSUPPORTED',
      'CACHED_CREDENTIAL_FALSE_POSITIVE' => 'EXIT_ONE_POLICY_EXACT',
      'UNSUPPORTED_EXIT' => 'EXIT_OTHER_POLICY_EXACT',
      'EXIT_ONE_EMPTY' => 'EXIT_ONE_POLICY_EMPTY',
      'EXIT_ONE_NONEXACT' => 'EXIT_ONE_POLICY_OTHER',
      'EXIT_OTHER_EMPTY' => 'EXIT_OTHER_POLICY_EMPTY',
      'EXIT_OTHER_NONEXACT' => 'EXIT_OTHER_POLICY_OTHER',
      'SETENV' => 'OPTION_UNSUPPORTED',
      'NO_ENV_RESET' => 'OPTION_UNSUPPORTED',
      'UNKNOWN_OPTION' => 'OPTION_UNSUPPORTED',
      'RELATIVE_SECURE_PATH' => 'OPTION_UNSUPPORTED',
      'CONFLICTING_SECURE_PATH' => 'OPTION_UNSUPPORTED',
      'WRONG_RUNAS' => 'RUNAS_MISMATCH',
      'WRONG_GROUP' => 'RUNAS_MISMATCH',
      'WRONG_PATH' => 'COMMAND_MISMATCH',
      'WRONG_ARGS' => 'COMMAND_MISMATCH',
      'WRONG_MATCHED' => 'COMMAND_MISMATCH',
      'WILDCARD' => 'COMMAND_MISMATCH',
      'PATTERN' => 'COMMAND_MISMATCH',
      'CONFLICTING' => 'MULTIPLE_ENTRIES',
      'DUPLICATE' => 'MULTIPLE_ENTRIES',
      'CONFLICTING_OPTIONS' => 'AUTH_AMBIGUOUS',
      'DUPLICATE_OPTIONS' => 'AUTH_AMBIGUOUS',
      'MISSING_AUTH' => 'AUTH_AMBIGUOUS',
      'MISSING_MATCHED' => 'MATCHED_MISSING',
      'EMPTY_POLICY' => 'POLICY_EMPTY',
    ];
    $stage = '/home/agency-prod/.agency-1202-runtime-error-capability-stage/';
    foreach ([
      '/usr/bin/install -o root -g root -m 0755 -- ' . $stage . 'agency-prod-runtime-error-counts /usr/local/sbin/agency-prod-runtime-error-counts',
      '/usr/bin/install -o root -g root -m 0440 -- ' . $stage . 'agency-prod-runtime-error-counts.sudoers /etc/sudoers.d/agency-prod-runtime-error-counts',
      '/usr/sbin/visudo -cf ' . $stage . 'agency-prod-runtime-error-counts.sudoers',
    ] as $command) {
      $rule = "Sudoers entry: /etc/sudoers.d/private-policy\n    RunAsUsers: root\n    Options: !authenticate\n    Commands:\n        $command\nMatched: $command";
      $denial = "sudo: Sorry, user agency-prod is not allowed to execute '$command' as root on production.\n";
      foreach ([
        'EXACT_NOPASSWD' => [$rule, '', 0, 'AVAILABLE'],
        'HARMLESS_DEFAULTS' => [
          str_replace(
            '!authenticate',
            'env_reset, mail_badpass, secure_path=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin, use_pty, !authenticate',
            $rule,
          ),
          '',
          0,
          'AVAILABLE',
        ],
        'NOSETENV' => [str_replace('!authenticate', '!authenticate, !setenv', $rule), '', 0, 'AVAILABLE'],
        'RESTRICTIVE_LOGGING' => [
          str_replace('!authenticate', '!authenticate, noexec, log_input, log_output', $rule),
          '',
          0,
          'AVAILABLE',
        ],
        'SOURCELESS' => [str_replace(': /etc/sudoers.d/private-policy', ':', $rule), '', 0, 'AVAILABLE'],
        'ROOT_GROUP' => [
          str_replace('RunAsUsers: root', "RunAsUsers: root\n    RunAsGroups: root", $rule),
          '',
          0,
          'AVAILABLE',
        ],
        'EXACT_PASSWD' => [str_replace('!authenticate', 'authenticate', $rule), '', 0, 'UNAVAILABLE'],
        'EXACT_DENIAL' => ['', $denial, 1, 'UNAVAILABLE'],
        'LIST_DENIAL' => [
          '',
          "User agency-prod is not allowed to run '$command' as root on production.\n",
          1,
          'UNAVAILABLE',
        ],
        'WRONG_DENIAL_COMMAND' => ['', str_replace($command, '/usr/bin/other', $denial), 1, 'UNKNOWN'],
        'DENIAL_WITH_POLICY' => [$rule, $denial, 1, 'UNKNOWN'],
        'DENIAL_WITH_AUTH_FAILURE' => ['', $denial . "sudo: a password is required\n", 1, 'UNKNOWN'],
        'LISTPW_ANY_FALSE_POSITIVE' => [$command, '', 0, 'UNKNOWN'],
        'CACHED_CREDENTIAL_FALSE_POSITIVE' => [$rule, '', 1, 'UNKNOWN'],
        'PASSWORD_REQUIRED' => ['', "sudo: a password is required\n", 1, 'UNKNOWN'],
        'LISTPW_FAILURE' => ['', "Sorry, user agency-prod may not run sudo on production.\n", 1, 'UNKNOWN'],
        'SETENV' => [str_replace('!authenticate', '!authenticate, setenv', $rule), '', 0, 'UNKNOWN'],
        'NO_ENV_RESET' => [str_replace('!authenticate', '!authenticate, !env_reset', $rule), '', 0, 'UNKNOWN'],
        'UNKNOWN_OPTION' => [str_replace('!authenticate', '!authenticate, future_option', $rule), '', 0, 'UNKNOWN'],
        'RELATIVE_SECURE_PATH' => [
          str_replace('!authenticate', '!authenticate, secure_path=/bin:relative', $rule),
          '',
          0,
          'UNKNOWN',
        ],
        'CONFLICTING_SECURE_PATH' => [
          str_replace('!authenticate', '!authenticate, secure_path=/bin, secure_path=/usr/bin', $rule),
          '',
          0,
          'UNKNOWN',
        ],
        'WRONG_RUNAS' => [str_replace('RunAsUsers: root', 'RunAsUsers: ALL', $rule), '', 0, 'UNKNOWN'],
        'WRONG_GROUP' => [
          str_replace('RunAsUsers: root', "RunAsUsers: root\n    RunAsGroups: ALL", $rule),
          '',
          0,
          'UNKNOWN',
        ],
        'WRONG_PATH' => [str_replace('/usr/', '/opt/', $rule), '', 0, 'UNKNOWN'],
        'WRONG_ARGS' => [str_replace($command, $command . ' extra', $rule), '', 0, 'UNKNOWN'],
        'WRONG_MATCHED' => [str_replace('Matched: ' . $command, 'Matched: /usr/bin/other', $rule), '', 0, 'UNKNOWN'],
        'WILDCARD' => [str_replace($command, '/usr/bin/*', $rule), '', 0, 'UNKNOWN'],
        'PATTERN' => [str_replace($command, '^/usr/bin/.*$', $rule), '', 0, 'UNKNOWN'],
        'CONFLICTING' => [$rule . "\n" . str_replace('!authenticate', 'authenticate', $rule), '', 0, 'UNKNOWN'],
        'DUPLICATE' => [$rule . "\n" . $rule, '', 0, 'UNKNOWN'],
        'CONFLICTING_OPTIONS' => [str_replace('!authenticate', '!authenticate, authenticate', $rule), '', 0, 'UNKNOWN'],
        'DUPLICATE_OPTIONS' => [str_replace('!authenticate', '!authenticate, !authenticate', $rule), '', 0, 'UNKNOWN'],
        'UNSUPPORTED_FORMAT' => ['private unsupported policy', '', 0, 'UNKNOWN'],
        'MISSING_MATCHED' => [str_replace("\nMatched: $command", '', $rule), '', 0, 'UNKNOWN'],
        'RAW_STDERR_PRIVATE' => [$rule, 'private stderr', 0, 'UNKNOWN'],
        'EMPTY_POLICY' => ['', '', 0, 'UNKNOWN'],
        'UNSUPPORTED_EXIT' => [$rule, '', 2, 'UNKNOWN'],
        'EXIT_ONE_EMPTY' => ['', '', 1, 'UNKNOWN'],
        'EXIT_ONE_NONEXACT' => [str_replace('!authenticate', '!authenticate, future_option', $rule), '', 1, 'UNKNOWN'],
        'EXIT_OTHER_EMPTY' => [" \t\n", '', 2, 'UNKNOWN'],
        'EXIT_OTHER_NONEXACT' => [str_replace('!authenticate', '!authenticate, future_option', $rule), '', 2, 'UNKNOWN'],
        'MISSING_AUTH' => [str_replace('!authenticate', 'env_reset', $rule), '', 0, 'UNKNOWN'],
        'ADVERSARIAL_POLICY' => [
          "AVAILABLE NONE\nWHY_UNKNOWN_HELPER_INSTALL=NONE\nprivate-user private-host /private/source /unrelated/command",
          '',
          0,
          'UNKNOWN',
        ],
        'ADVERSARIAL_STDERR' => [
          $rule,
          "UNKNOWN NONE\nprivate-user private-host /private/source /unrelated/command",
          0,
          'UNKNOWN',
        ],
        'MISSING_SUDO' => ['', 'private stderr', 127, 'UNKNOWN'],
      ] as $name => [$policy, $error, $rc, $expected]) {
        foreach (['probe_exact_sudo', 'probe_exact_sudo_with_reason'] as $probe) {
          $result = $this->runLocal(['bash'], "set -Eeuo pipefail\n" . $functions . <<<'SH'
sudo() { [[ "$*" == "-k -n -ll -- $COMMAND" && "$LC_ALL" == C ]] || return 99; printf '%s' "$POLICY"; printf '%s' "$STDERR" >&2; return "$RC"; }
read -r -a command_args <<< "$COMMAND"
"$PROBE" "${command_args[@]}"
SH, ['PROBE' => $probe, 'COMMAND' => $command, 'POLICY' => $policy, 'STDERR' => $error, 'RC' => (string) $rc]);
          self::assertSame(0, $result['status'], $name);
          $reason = $reasons[$name] ?? 'NONE';
          self::assertSame($probe === 'probe_exact_sudo' ? $expected : "$expected $reason", $result['output'], $name);
          self::assertSame('', $result['error'], $name);
        }
      }
    }
  }

  /**
   * Internal failures emit bounded pairs without leaking private errors.
   */
  public function testPrivateProbeInternalFailures(): void {
    $source = $this->source(self::BASE . 'remote-provision-plan.sh');
    $start = strpos($source, 'probe_exact_sudo() {');
    $end = strpos($source, 'MAIN_SHA="${1:-}"');
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $functions = substr($source, $start, $end - $start);
    self::assertSame(1, substr_count($functions, 'sudo -k -n -ll --'));
    foreach ([
      'mktemp() { printf private >&2; return 1; }' => 'INTERNAL_TEMPFILE_ERROR',
      'rm() { command rm "$@"; printf private >&2; return 1; }' => 'INTERNAL_TEMPFILE_ERROR',
      'python3() { printf private; printf private >&2; return 1; }' => 'INTERNAL_PARSER_ERROR',
      'python3() { printf "AVAILABLE private"; }' => 'INTERNAL_PARSER_ERROR',
    ] as $stub => $reason) {
      $result = $this->runLocal(['bash'], "set -Eeuo pipefail\n" . $functions . "\n" . $stub . <<<'SH'

sudo() { printf private; printf private >&2; return 1; }
probe_exact_sudo_with_reason /fixture
SH);
      self::assertSame(0, $result['status']);
      self::assertSame('UNKNOWN ' . $reason, $result['output']);
      self::assertSame('', $result['error']);
    }
  }

  /**
   * Receipt diagnostics are closed enums and excluded from the PASS identity.
   */
  public function testReceiptReasonsAndDigest(): void {
    $plan = json_decode($this->plan()['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    $identity = array_diff_key($plan, array_flip([
      'STATUS', 'RAW_SUDO_POLICY_EXPOSURE', 'NONCONFORMANT_OVERWRITE',
      'REAL_PROD_MUTATION', 'CANNOT_BE_APPROVED', 'PLAN_DIGEST',
      'WHY_UNKNOWN_HELPER_INSTALL', 'WHY_UNKNOWN_SUDOERS_INSTALL',
      'WHY_UNKNOWN_VISUDO_VALIDATION',
    ]));
    ksort($identity);
    self::assertSame(hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $plan['PLAN_DIGEST']);
    $validReasons = [
      'NONE', 'POLICY_EMPTY',
      'EXIT_ONE_POLICY_EMPTY', 'EXIT_ONE_POLICY_EXACT', 'EXIT_ONE_POLICY_OTHER',
      'EXIT_OTHER_POLICY_EMPTY', 'EXIT_OTHER_POLICY_EXACT', 'EXIT_OTHER_POLICY_OTHER',
    ];
    foreach (['HELPER_INSTALL', 'SUDOERS_INSTALL', 'VISUDO_VALIDATION'] as $suffix) {
      $key = 'WHY_UNKNOWN_' . $suffix;
      self::assertSame('NONE', $plan[$key]);
      foreach (['AVAILABLE', 'UNAVAILABLE', 'UNKNOWN'] as $privilege) {
        $invalidReasons = [
          'EXIT_UNSUPPORTED',
          '',
          "private-user private-host /private/source /unrelated/command\nNONE",
        ];
        foreach (array_merge($validReasons, $invalidReasons) as $reason) {
          $result = $this->plan(['PRIVILEGED_' . $suffix => $privilege, $key => $reason]);
          $valid = in_array($reason, $validReasons, TRUE) && (($reason === 'NONE') === ($privilege !== 'UNKNOWN'));
          self::assertSame($valid ? ($privilege === 'AVAILABLE' ? 0 : 1) : 70, $result['status']);
          self::assertSame('', $result['error']);
          if (!$valid) {
            self::assertSame('', $result['output']);
          }
          elseif ($privilege !== 'AVAILABLE') {
            $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
            self::assertSame($reason, $receipt[$key]);
            self::assertSame('FAIL', $receipt['STATUS']);
            self::assertSame('BLOCKED', $receipt['INSTALL_DECISION']);
            self::assertSame('YES', $receipt['CANNOT_BE_APPROVED']);
            self::assertNull($receipt['PLAN_DIGEST']);
          }
        }
      }
    }
  }

  /**
   * Optional exact hash observes unreadable sudoers without granting access.
   */
  public function testOptionalSudoersHash(): void {
    $source = $this->source(self::BASE . 'remote-provision-plan.sh');
    $functions = substr($source, strpos($source, 'probe_exact_sudo() {'), strpos($source, 'MAIN_SHA="${1:-}"') - strpos($source, 'probe_exact_sudo() {'));
    // Force the unreadable branch deterministically, including under root CI.
    $functions = str_replace('[[ -r "$path" ]]', 'false', $functions);
    $file = tempnam(sys_get_temp_dir(), 'agency-1202-unreadable-');
    self::assertNotFalse($file);
    $hash = str_repeat('a', 64);
    $line = $hash . "  /etc/sudoers.d/agency-prod-runtime-error-counts\n";
    try {
      foreach ([
        [$line, 0, 'ALREADY_CONFORMANT'],
        [$line, 1, 'UNKNOWN'],
        ['', 127, 'UNKNOWN'],
        [str_replace($hash, str_repeat('b', 64), $line), 0, 'NONCONFORMANT'],
        [strtoupper($line), 0, 'UNKNOWN'],
        [$line . "private\n", 0, 'UNKNOWN'],
      ] as [$output, $rc, $expected]) {
        $result = $this->runLocal(['bash'], "set -Eeuo pipefail\n" . $functions . <<<'SH'
stat() { printf root:root:440; }
sudo() { [[ "$*" == '-k -n -- /usr/bin/sha256sum -- /etc/sudoers.d/agency-prod-runtime-error-counts' ]] || return 99; printf '%s' "$HASH_OUTPUT"; printf private >&2; return "$RC"; }
classify_target "$SUDOERS_DEST" "$EXPECTED_HASH" root:root:440
SH, ['SUDOERS_DEST' => $file, 'EXPECTED_HASH' => $hash, 'HASH_OUTPUT' => $output, 'RC' => (string) $rc]);
        self::assertSame(0, $result['status']);
        self::assertSame($expected, $result['output']);
        self::assertSame('', $result['error']);
        $receipt = json_decode($this->plan([
          'HELPER_STATE' => 'ALREADY_CONFORMANT',
          'SUDOERS_STATE' => $result['output'],
        ])['output'], TRUE, 32, JSON_THROW_ON_ERROR);
        self::assertSame($expected === 'ALREADY_CONFORMANT' ? 'ALREADY_CONFORMANT' : 'BLOCKED', $receipt['INSTALL_DECISION']);
      }
      $result = $this->runLocal(['bash'], "set -Eeuo pipefail\n" . $functions . <<<'SH'
sudo() { printf UNEXPECTED_HASH_PROBE; return 99; }
classify_target "$SUDOERS_DEST" unused root:root:440
SH, ['SUDOERS_DEST' => $file . '-absent']);
      self::assertSame('ABSENT', $result['output']);
      self::assertSame(0, $this->plan(['SUDOERS_STATE' => $result['output']])['status']);
      $apply = $this->source(self::BASE . 'remote-provision-apply.sh');
      self::assertStringContainsString('expected_rule="$SERVER_USER ALL=(root) NOPASSWD: NOSETENV: $HELPER_DEST"', $apply);
      self::assertStringContainsString(
        'sudo -k -n -- /usr/bin/sha256sum -- /etc/sudoers.d/agency-prod-runtime-error-counts',
        $apply,
      );
      self::assertStringNotContainsString(
        'sha256sum',
        $this->source(self::BASE . 'agency-prod-runtime-error-counts.sudoers.template'),
      );
      self::assertStringNotContainsString('VISUDO_INSTALLED=', $source);
    }
    finally {
      unlink($file);
    }
  }

  /**
   * All target states and unavailable/unknown install privileges fail closed.
   */
  public function testPlanStateAndPrivilegeMatrix(): void {
    foreach (['ABSENT', 'ALREADY_CONFORMANT', 'NONCONFORMANT', 'UNKNOWN'] as $helper) {
      foreach (['ABSENT', 'ALREADY_CONFORMANT', 'NONCONFORMANT', 'UNKNOWN'] as $sudoers) {
        $result = $this->plan(['HELPER_STATE' => $helper, 'SUDOERS_STATE' => $sudoers]);
        $pass = $helper === $sudoers && in_array($helper, ['ABSENT', 'ALREADY_CONFORMANT'], TRUE);
        self::assertSame($pass ? 0 : 1, $result['status']);
        $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
        self::assertSame($pass ? 'NO' : 'YES', $receipt['CANNOT_BE_APPROVED']);
        self::assertSame('NONE', $receipt['RAW_SUDO_POLICY_EXPOSURE']);
        self::assertSame('NONE', $receipt['REAL_PROD_MUTATION']);
        if ($pass) {
          self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $receipt['PLAN_DIGEST']);
          self::assertSame($helper === 'ABSENT' ? 'INSTALL_REQUIRED' : 'ALREADY_CONFORMANT', $receipt['INSTALL_DECISION']);
        }
        else {
          self::assertNull($receipt['PLAN_DIGEST']);
          self::assertSame('BLOCKED', $receipt['INSTALL_DECISION']);
        }
      }
    }
    foreach (['PRIVILEGED_HELPER_INSTALL', 'PRIVILEGED_SUDOERS_INSTALL', 'PRIVILEGED_VISUDO_VALIDATION'] as $privilege) {
      foreach (['UNAVAILABLE', 'UNKNOWN'] as $state) {
        foreach (['ABSENT', 'ALREADY_CONFORMANT'] as $targetState) {
          $result = $this->plan([
            'HELPER_STATE' => $targetState,
            'SUDOERS_STATE' => $targetState,
            $privilege => $state,
            str_replace('PRIVILEGED_', 'WHY_UNKNOWN_', $privilege) => $state === 'UNKNOWN' ? 'POLICY_EMPTY' : 'NONE',
          ]);
          self::assertSame(1, $result['status'], "$targetState / $privilege / $state");
          $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
          self::assertSame('FAIL', $receipt['STATUS']);
          self::assertSame('BLOCKED', $receipt['INSTALL_DECISION']);
          self::assertSame('YES', $receipt['CANNOT_BE_APPROVED']);
          self::assertNull($receipt['PLAN_DIGEST']);
        }
      }
    }
  }

  /**
   * Validator rejects malformed, wrong and hash-stale plan evidence locally.
   */
  public function testValidatorAndDigestBindings(): void {
    $plan = json_decode($this->plan()['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertSame(0, $this->validate(json_encode($plan), $plan['PLAN_DIGEST'])['status']);
    foreach (['{', 'null', '[]', '{}'] as $invalid) {
      self::assertNotSame(0, $this->validate($invalid, $plan['PLAN_DIGEST'])['status']);
    }
    foreach ([
      'ISSUE' => 1183,
      'TARGET' => 'PREPROD',
      'MODE' => 'APPLY',
      'STATUS' => 'FAIL',
      'MAIN_SHA' => str_repeat('b', 40),
      'PLAN_ID' => 'plan-1202-2-1',
      'SERVER_USER_SHA256' => str_repeat('b', 64),
      'HELPER_SOURCE_SHA256' => str_repeat('b', 64),
      'RENDERED_SUDOERS_SHA256' => str_repeat('b', 64),
      'HELPER_DESTINATION' => '/tmp/helper',
      'SUDOERS_DESTINATION' => '/tmp/sudoers',
      'HELPER_EXPECTED_OWNER_GROUP_MODE' => 'root:root:0777',
      'SUDOERS_EXPECTED_OWNER_GROUP_MODE' => 'root:root:0666',
      'HELPER_STATE' => 'NONCONFORMANT',
      'SUDOERS_STATE' => 'UNKNOWN',
      'PRIVILEGED_HELPER_INSTALL' => 'UNAVAILABLE',
      'PRIVILEGED_SUDOERS_INSTALL' => 'UNKNOWN',
      'PRIVILEGED_VISUDO_VALIDATION' => 'UNAVAILABLE',
      'RAW_SUDO_POLICY_EXPOSURE' => 'RAW',
      'REAL_PROD_MUTATION' => 'YES',
    ] as $key => $value) {
      self::assertNotSame(0, $this->validate(json_encode(array_replace($plan, [$key => $value])), $plan['PLAN_DIGEST'])['status'], $key);
    }
    self::assertNotSame(0, $this->validate(json_encode($plan), str_repeat('f', 64))['status']);
    foreach (['MAIN_SHA', 'PLAN_ID', 'SERVER_USER_SHA256', 'HELPER_SOURCE_SHA256', 'RENDERED_SUDOERS_SHA256'] as $key) {
      $changed = json_decode($this->plan([$key => $key === 'PLAN_ID' ? 'plan-1202-2-1' : str_repeat('b', $key === 'MAIN_SHA' ? 40 : 64)])['output'], TRUE, 32, JSON_THROW_ON_ERROR);
      self::assertNotSame($plan['PLAN_DIGEST'], $changed['PLAN_DIGEST'], $key);
    }
  }

  /**
   * Exact destination, privilege and mutation boundaries precede installation.
   */
  public function testApplyOrderingAndBoundaries(): void {
    $apply = $this->source(self::BASE . 'remote-provision-apply.sh');
    $plan = $this->source(self::BASE . 'remote-provision-plan.sh');
    foreach ([$apply, $plan] as $source) {
      self::assertStringContainsString("HELPER_DEST='/usr/local/sbin/agency-prod-runtime-error-counts'", $source);
      self::assertStringContainsString("SUDOERS_DEST='/etc/sudoers.d/agency-prod-runtime-error-counts'", $source);
      self::assertStringContainsString('[[ "$(id -un)" == "$SERVER_USER" ]]', $source);
      self::assertStringContainsString('[[ -L "$path" ]]', $source);
      self::assertStringContainsString('root:root:755', $source);
      self::assertStringContainsString('root:root:440', $source);
      foreach ([
        'ssh ', 'scp ', 'apt-get ', 'systemctl ', 'drush ', 'reboot',
        '/var/log/', 'sudo -n journalctl', 'NOPASSWD: ALL',
      ] as $forbidden) {
        self::assertStringNotContainsString($forbidden, $source);
      }
    }
    self::assertStringContainsString('sudo -k -n -ll -- "$@" 2>"$stderr_file"', $plan);
    foreach ([
      '[[ "$actual_helper_hash" == "$HELPER_SOURCE_SHA256" ]]',
      '[[ "$actual_sudoers_hash" == "$RENDERED_SUDOERS_SHA256" ]]',
      '[[ "${staged_sudoers_lines[0]}" == "$expected_rule" ]]',
      "'RAW_LOG_EXPOSURE': 'NONE'",
      "'RAW_SUDO_POLICY_EXPOSURE': 'NONE'",
      "'UNRELATED_PROD_MUTATION': 'NONE'",
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }
    $helper = 'sudo -k -n -- /usr/bin/install -o root -g root -m 0755 --';
    $sudoers = 'sudo -k -n -- /usr/bin/install -o root -g root -m 0440 --';
    $this->assertBefore('[[ "$actual_sudoers_hash" == "$RENDERED_SUDOERS_SHA256" ]]', $helper, $apply);
    $this->assertBefore('sudo -k -n -- /usr/sbin/visudo -cf "$STAGE_SUDOERS"', $helper, $apply);
    $this->assertBefore('STALE_PLAN=\'FAIL\'', $helper, $apply);
    $this->assertBefore($helper, $sudoers, $apply);
    $this->assertBefore($sudoers, 'sudo -k -n -- "$HELPER_DEST"', $apply);
    self::assertStringContainsString(
      "SUDOERS_POST_VALIDATION='TRANSACTIONAL_EXACT_IDENTITY'",
      $apply,
    );
    self::assertStringNotContainsString(
      'visudo -cf "$SUDOERS_DEST"',
      $apply,
    );
    self::assertStringNotContainsString(
      'sha256sum -- "$SUDOERS_DEST"',
      $apply,
    );
    foreach ([
      'sudo -k -n -- /usr/sbin/visudo -cf "$STAGE_SUDOERS"',
      'sudo -k -n -- /usr/bin/install -o root -g root -m 0755 --',
      'sudo -k -n -- /usr/bin/install -o root -g root -m 0440 --',
      'sudo -k -n -- "$HELPER_DEST"',
    ] as $nonInteractive) {
      self::assertStringContainsString($nonInteractive, $apply);
    }
  }

  /**
   * Staged helper, sudoers and governed user drift stop before any sudo call.
   */
  public function testStagedHashAndUserDrift(): void {
    $source = $this->source(self::BASE . 'remote-provision-apply.sh');
    $start = strpos($source, 'actual_helper_hash=');
    $end = strpos($source, 'if [[ "$INSTALL_DECISION"', $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $helper = tempnam(sys_get_temp_dir(), 'agency-1202-helper-');
    $sudoers = tempnam(sys_get_temp_dir(), 'agency-1202-sudoers-');
    self::assertNotFalse($helper);
    self::assertNotFalse($sudoers);
    try {
      file_put_contents($helper, 'helper fixture');
      file_put_contents($sudoers, "agency-prod ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-runtime-error-counts\n");
      $environment = [
        'STAGE_HELPER' => $helper,
        'STAGE_SUDOERS' => $sudoers,
        'HELPER_SOURCE_SHA256' => hash_file('sha256', $helper),
        'RENDERED_SUDOERS_SHA256' => hash_file('sha256', $sudoers),
        'SERVER_USER' => 'agency-prod',
        'HELPER_DEST' => '/usr/local/sbin/agency-prod-runtime-error-counts',
      ];
      $script = "set -Eeuo pipefail\n" . substr($source, $start, $end - $start);
      self::assertSame(0, $this->runLocal(['bash'], $script, $environment)['status']);
      foreach ([
        'HELPER_SOURCE_SHA256' => str_repeat('a', 64),
        'RENDERED_SUDOERS_SHA256' => str_repeat('b', 64),
        'SERVER_USER' => 'other-user',
      ] as $key => $value) {
        self::assertNotSame(0, $this->runLocal(['bash'], $script, array_replace($environment, [$key => $value]))['status'], $key);
      }
    }
    finally {
      unlink($helper);
      unlink($sudoers);
    }
  }

  /**
   * Executes only the pre-install drift gate; no privileged command can run.
   */
  public function testTargetDriftNeverReachesInstall(): void {
    $source = $this->source(self::BASE . 'remote-provision-apply.sh');
    $start = strpos($source, 'current_helper_state=');
    $end = strpos($source, 'if [[ "$INSTALL_DECISION" == \'INSTALL_REQUIRED\' ]]; then', $start);
    self::assertNotFalse($start);
    self::assertNotFalse($end);
    $gate = substr($source, $start, $end - $start);
    foreach (['ABSENT', 'ALREADY_CONFORMANT'] as $planned) {
      foreach (['ABSENT', 'ALREADY_CONFORMANT', 'NONCONFORMANT', 'UNKNOWN'] as $current) {
        foreach (['HELPER', 'SUDOERS'] as $target) {
          $result = $this->runLocal(['bash'], <<<'SH'
set -Eeuo pipefail
classify_target() { if [[ "$1" == helper ]]; then printf '%s' "$CURRENT_HELPER"; else printf '%s' "$CURRENT_SUDOERS"; fi; }
emit_receipt() { printf '%s' "$STALE_PLAN"; }
HELPER_DEST=helper
SUDOERS_DEST=sudoers
HELPER_SOURCE_SHA256=unused
RENDERED_SUDOERS_SHA256=unused
SH
            . "\n" . $gate . "\nprintf REACHED_INSTALL",
            [
              'PLANNED_HELPER_STATE' => $planned,
              'PLANNED_SUDOERS_STATE' => $planned,
              'CURRENT_HELPER' => $target === 'HELPER' ? $current : $planned,
              'CURRENT_SUDOERS' => $target === 'SUDOERS' ? $current : $planned,
            ],
          );
          self::assertSame($current === $planned ? 0 : 1, $result['status']);
          self::assertSame($current === $planned ? 'REACHED_INSTALL' : 'FAIL', $result['output']);
        }
      }
    }
  }

  /**
   * Only three canonical helper lines become bounded receipt counts.
   */
  public function testBoundedHelperProof(): void {
    $source = $this->source(self::BASE . 'remote-provision-apply.sh');
    $start = strpos($source, 'set +e', strpos($source, "SUDOERS_POST_VALIDATION='TRANSACTIONAL_EXACT_IDENTITY'"));
    self::assertNotFalse($start);
    $proof = substr($source, $start);
    $good = "STATUS=PASS\nNGINX_RECENT_ERROR_COUNT=2\nPHP_FPM_RECENT_ERROR_COUNT=0";
    foreach ([
      [$good, 0, 'PASS'],
      [$good, 1, 'FAIL'],
      [$good . "\nrequest=secret", 0, 'FAIL'],
      [str_replace('COUNT=2', 'COUNT=UNKNOWN', $good), 0, 'FAIL'],
      [str_replace('COUNT=2', 'COUNT=-1', $good), 0, 'FAIL'],
      ['STATUS=FAIL', 0, 'FAIL'],
    ] as [$output, $rc, $expected]) {
      $result = $this->runLocal(['bash'], <<<'SH'
set -Eeuo pipefail
sudo() { [[ "$*" == '-k -n -- fixture-helper' ]] || exit 90; printf '%s' "$FIXTURE_OUTPUT"; return "$FIXTURE_RC"; }
emit_receipt() { printf '%s' "$POST_INSTALL_HELPER_PROOF"; }
HELPER_DEST=fixture-helper
SH
        . "\n" . $proof, ['FIXTURE_OUTPUT' => $output, 'FIXTURE_RC' => (string) $rc]);
      self::assertSame($expected === 'PASS' ? 0 : 1, $result['status']);
      self::assertSame($expected, $result['output']);
      self::assertSame('', $result['error']);
    }
  }

  /**
   * Runs only the embedded pure Python PLAN evaluator on synthetic inputs.
   */
  private function plan(array $overrides = []): array {
    self::assertSame(1, preg_match("/python3 - <<'PY'\n(.*?)\nPY\n/s", $this->source(self::BASE . 'remote-provision-plan.sh'), $matches));
    return $this->runLocal(['python3', '-'], $matches[1], array_replace([
      'MAIN_SHA' => str_repeat('a', 40),
      'PLAN_ID' => 'plan-1202-1-1',
      'SERVER_USER_SHA256' => str_repeat('c', 64),
      'HELPER_SOURCE_SHA256' => str_repeat('d', 64),
      'RENDERED_SUDOERS_SHA256' => str_repeat('e', 64),
      'HELPER_STATE' => 'ABSENT',
      'SUDOERS_STATE' => 'ABSENT',
      'WHY_UNKNOWN_HELPER_INSTALL' => 'NONE',
      'WHY_UNKNOWN_SUDOERS_INSTALL' => 'NONE',
      'WHY_UNKNOWN_VISUDO_VALIDATION' => 'NONE',
      'PRIVILEGED_HELPER_INSTALL' => 'AVAILABLE',
      'PRIVILEGED_SUDOERS_INSTALL' => 'AVAILABLE',
      'PRIVILEGED_VISUDO_VALIDATION' => 'AVAILABLE',
    ], $overrides));
  }

  /**
   * Validator reads a disposable local fixture, never a remote artifact.
   */
  private function validate(string $json, string $digest): array {
    $file = tempnam(sys_get_temp_dir(), 'agency-1202-');
    self::assertNotFalse($file);
    try {
      file_put_contents($file, $json);
      return $this->runLocal([
        'python3',
        dirname(DRUPAL_ROOT) . '/' . self::BASE . 'validate-provision-plan.py',
        $file,
        $digest,
        str_repeat('c', 64),
      ]);
    }
    finally {
      unlink($file);
    }
  }

  /**
   * Executes local interpreters only; no workflow or remote script is launched.
   */
  private function runLocal(array $command, string $input = '', array $environment = []): array {
    self::assertContains($command[0], ['bash', 'python3']);
    $process = new Process($command, NULL, $environment, $input, 10);
    $status = $process->run();
    return ['status' => $status, 'output' => $process->getOutput(), 'error' => $process->getErrorOutput()];
  }

  /**
   * Checks both presence and ordering of material gates.
   */
  private function assertBefore(string $first, string $second, string $source): void {
    $left = strpos($source, $first);
    $right = strpos($source, $second);
    self::assertNotFalse($left, $first);
    self::assertNotFalse($right, $second);
    self::assertLessThan($right, $left);
  }

  /**
   * Reads a complete local repository file.
   */
  private function source(string $relative): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relative);
  }

}

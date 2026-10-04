<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the governed PROD PHP 8.5 PLAN/APPLY capability from #1353.
 *
 * @group agency_project_tests
 */
final class ProdPhp85Migration1353WorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const WORKFLOW = '.github/workflows/prod-php85-migration-1353.yml';
  private const PLAN = 'scripts/production-php85-1353/remote-plan.sh';
  private const APPLY = 'scripts/production-php85-1353/remote-apply-root.sh';
  private const WRAPPER = 'scripts/production-php85-1353/remote-apply-wrapper.sh';
  private const BOOTSTRAP = 'scripts/production-php85-1353/bootstrap/human-bootstrap-root.sh';
  private const VERIFY = 'scripts/production-php85-1353/bootstrap/verify-installed-root.sh';
  private const RENDER = 'scripts/production-php85-1353/bootstrap/render-sudoers.sh';
  private const SUDOERS_TEMPLATE = 'scripts/production-php85-1353/bootstrap/agency-prod-php85-1353.sudoers.template';
  private const CAPABILITY = 'scripts/production-php85-1353/bootstrap/capability.json';

  /**
   * Dispatcher keeps separate direct OWNER PLAN and APPLY routes.
   */
  public function testDispatcherUsesBoundedProdPlanAndApplyRoutes(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    foreach ([
      'prod-php85-migration-1353-plan' => [
        "github.event.comment.body == '/agency-prod-php85-1353 plan'",
      ],
      'prod-php85-migration-1353-apply' => [
        "startsWith(github.event.comment.body, '/agency-prod-php85-1353 apply ')",
      ],
    ] as $jobId => $routeAssertions) {
      $job = $dispatcher['jobs'][$jobId] ?? NULL;
      self::assertIsArray($job, $jobId);
      self::assertSame('./' . self::WORKFLOW, $job['uses'] ?? NULL);
      self::assertSame(
        ['actions' => 'read', 'contents' => 'read', 'issues' => 'write'],
        $job['permissions'] ?? NULL,
      );
      self::assertSame(
        ['SSH_PRIVATE_KEY', 'SERVER_HOST', 'SERVER_USER'],
        array_keys($job['secrets'] ?? []),
      );
      $condition = (string) ($job['if'] ?? '');
      foreach ([
        "github.event_name == 'issue_comment'",
        "github.event.action == 'created'",
        'github.event.issue.pull_request == null',
        'github.event.issue.number == 1353',
        "github.event.comment.author_association == 'OWNER'",
        "github.event.comment.user.login == 'E-merging-digital'",
        'github.event.comment.performed_via_github_app == null',
        ...$routeAssertions,
      ] as $required) {
        self::assertStringContainsString($required, $condition, $jobId);
      }
    }
  }

  /**
   * Workflow binds APPLY to exact immutable PLAN and one-shot consumption.
   */
  public function testWorkflowAppliesOnlyExactOneShotApprovedPlan(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);
    $on = $workflow['on'] ?? [];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayNotHasKey('workflow_dispatch', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertSame(
      ['validate-authority', 'plan', 'apply'],
      array_keys($workflow['jobs'] ?? []),
    );

    foreach ([
      'test "$GITHUB_RUN_ATTEMPT" = \'1\'',
      'test "$ISSUE_NUMBER" = \'1353\'',
      'test "$COMMENT_LOGIN" = \'E-merging-digital\'',
      'test "$COMMENT_ASSOCIATION" = \'OWNER\'',
      'test "$COMMENT_VIA_APP" = \'false\'',
      'test "$WORKFLOW_SHA" = "$main_sha"',
      "^/agency-prod-php85-1353\\ apply\\ plan_run=([1-9][0-9]*)\\ plan_digest=([0-9a-f]{64})$",
      "'.github/workflows/agency-command-dispatch.yml'",
      "'.conclusion' <<<",
      "'.event' <<<",
      "'.run_attempt' <<<",
      "'.head_sha' <<<",
      'artifact_name="prod-php85-1353-plan-${PLAN_RUN}-1"',
      '.SAFETY_GATE == "PASS"',
      '.FAILED_CHECKS == []',
      'AGENCY_PROD_PHP85_1353_APPLY_CONSUMED',
      'Approved #1353 PLAN run is already consumed.',
      'live_main="$(gh api "repos/$GITHUB_REPOSITORY/git/ref/heads/main"',
      'test "$live_main" = "$MAIN_SHA"',
      'scripts/production-php85-1353/remote-apply-wrapper.sh',
      'helper_hash="$(sha256sum scripts/production-php85-1353/remote-apply-root.sh',
      'plan_script_hash="$(sha256sum scripts/production-php85-1353/remote-plan.sh',
      '.FAILURE_STATE == "HUMAN_BOOTSTRAP_REQUIRED"',
      '.CAPABILITY_READY == "NO"',
      '.PACKAGE_APPLY == "NOT_STARTED"',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    foreach ([
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'root@$SERVER_HOST',
      'do-release-upgrade',
      'sudo -k -n -- /usr/bin/install',
      'sudo -k -n -- /usr/sbin/visudo',
      'sudo -k -n -- /bin/bash',
      'sudo -k -n -- /usr/bin/env',
      'NOPASSWD:ALL',
      'helper_dest=',
      'sudoers_dest=',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }
  }

  /**
   * PLAN remains read-only, bounded and decision-complete.
   */
  public function testPlanIsReadOnlyAndDecisionComplete(): void {
    $plan = $this->source(self::PLAN);
    foreach ([
      "ISSUE='1353'",
      "TARGET='PROD'",
      "MODE='PLAN'",
      "PROD_URL='https://emergingdigital.be'",
      "DRUPAL_ROOT='/var/www/agency/current'",
      'PHP85_INSTALL_SIMULATION',
      'PHP85_INSTALLABLE',
      'PHP84_PACKAGES_PRESENT',
      'PHP84_SERVICE_ACTIVE',
      'NGINX_VHOST_PHP84_SOCKET_MATCH',
      'FPM84_POOL_CONTRACT',
      'ROLLBACK_PHP84_AVAILABLE',
      'apt-get --simulate install',
      'PACKAGE_ADDITIONS',
      'TRANSITIVE_ADDITIONS',
      'PLAN_DIGEST',
    ] as $required) {
      self::assertStringContainsString($required, $plan);
    }
    foreach ([
      'apt-get update',
      'apt upgrade',
      'full-upgrade',
      'dist-upgrade',
      'do-release-upgrade',
      'apt-get install -y',
      'systemctl restart',
      'systemctl reload',
      'systemctl enable',
      'systemctl disable',
      'drush cr',
      'drush updb',
      'drush cim',
      'sudo ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $plan);
    }
    self::assertStringNotContainsString('php8.5-opcache', $plan);
    self::assertStringContainsString(
      "    'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB'])",
      $plan,
    );
    self::assertStringNotContainsString("    'DISK_AVAILABLE_KB',", $plan);
  }

  /**
   * APPLY keeps exact stale-plan/package identity and PHP 8.4 rollback.
   */
  public function testApplyEnforcesStalePlanPackageAndPhp84RollbackContract(): void {
    $apply = $this->source(self::APPLY);

    foreach ([
      "ISSUE='1353'",
      "TARGET='PROD'",
      'CAPABILITY_HELPER=\'/usr/local/sbin/agency-prod-php85-1353-apply\'',
      'CONSUMED_ROOT=\'/var/lib/agency-prod-php85-1353-consumed\'',
      '"$PLAN_SCRIPT" "$EXPECTED_MAIN" "$plan_id"',
      'test "$(jq -r \'.PLAN_DIGEST\' "$work_root/current-plan.json")" = "$EXPECTED_DIGEST"',
      'apt-get --simulate install "${package_specs[@]}"',
      'apt-get install -y "${package_specs[@]}"',
      'dpkg-query -W -f=\'${Status}\' "$package"',
      'systemctl is-active --quiet php8.4-fpm',
      'php-fpm8.5 -t',
      'systemctl enable --now php8.5-fpm',
      'systemctl restart php8.5-fpm',
      'FPM85_CONTRACT:"PASS"',
      'PHP84_FPM:"ACTIVE_ROLLBACK_AVAILABLE"',
      'PHP84_REMOVAL:"NONE"',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    $stale = strpos($apply, '"$PLAN_SCRIPT" "$EXPECTED_MAIN" "$plan_id"');
    $simulate = strpos($apply, 'apt-get --simulate install');
    $install = strpos($apply, 'apt-get install -y');
    self::assertNotFalse($stale);
    self::assertNotFalse($simulate);
    self::assertNotFalse($install);
    self::assertLessThan($simulate, $stale);
    self::assertLessThan($install, $simulate);

    foreach ([
      'apt-get remove',
      'apt-get purge',
      'dpkg --remove',
      'dpkg --purge',
      'systemctl stop php8.4-fpm',
      'systemctl disable php8.4-fpm',
      'do-release-upgrade',
      'drush updb',
      'drush cim',
      'drush config:import',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $apply);
    }
  }

  /**
   * Nginx cutover is socket-only and post-switch failures restore PHP 8.4.
   */
  public function testApplyUsesSocketOnlyNginxSwitchAndRollback(): void {
    $apply = $this->source(self::APPLY);
    foreach ([
      'candidate, substitutions = pattern.subn(',
      'if source.count(old) != substitutions:',
      "targets != {'unix:' + new}",
      'cp --preserve=all "$nginx_vhost" "$backup_root/nginx-vhost.before"',
      'cp --preserve=all "$backup_root/nginx-vhost.before" "$nginx_vhost"',
      'nginx -t',
      'systemctl reload nginx',
      'vendor/bin/drush status --fields=bootstrap',
      '"$PROD_URL/health/live"',
      '"$PROD_URL/health/ready"',
      'homepage_status=',
      'NGINX_SOCKET_ONLY_DELTA:"PASS"',
      'ROLLBACK:"NOT_REQUIRED"',
      'OS_UPGRADE:"NONE"',
      'MARIADB_CHANGE:"NONE"',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    $switch = strpos($apply, 'cat "$work_root/nginx.candidate" > "$nginx_vhost"');
    $syntax = strpos($apply, 'nginx -t', $switch === FALSE ? 0 : $switch);
    $reload = strpos($apply, 'systemctl reload nginx', $switch === FALSE ? 0 : $switch);
    self::assertNotFalse($switch);
    self::assertNotFalse($syntax);
    self::assertNotFalse($reload);
    self::assertLessThan($syntax, $switch);
    self::assertLessThan($reload, $syntax);
  }

  /**
   * Ordinary PROD identity never self-bootstraps privileged material.
   */
  public function testOrdinaryWrapperFailsClosedAtHumanBootstrapBoundary(): void {
    $wrapper = $this->source(self::WRAPPER);

    foreach ([
      "HELPER='/usr/local/sbin/agency-prod-php85-1353-apply'",
      "PLAN_SCRIPT='/usr/local/lib/agency-prod-php85-1353/remote-plan.sh'",
      'sudo -k -n -- "$HELPER" READY',
      'sudo -k -n -- "$HELPER" APPLY < "$APPROVED_PLAN"',
      'FAILURE_STATE:"HUMAN_BOOTSTRAP_REQUIRED"',
      'CAPABILITY_READY:"NO"',
      'STALE_PLAN:"NOT_REACHED"',
      'EXACT_PACKAGE_SIMULATION:"NOT_REACHED"',
      'PACKAGE_APPLY:"NOT_STARTED"',
      'PHP84_FPM:"ACTIVE_ROLLBACK_AVAILABLE"',
      'PHP84_REMOVAL:"NONE"',
      'ROLLBACK:"NOT_REQUIRED"',
    ] as $required) {
      self::assertStringContainsString($required, $wrapper);
    }

    $ready = strpos($wrapper, 'sudo -k -n -- "$HELPER" READY');
    $apply = strpos($wrapper, 'sudo -k -n -- "$HELPER" APPLY < "$APPROVED_PLAN"');
    self::assertNotFalse($ready);
    self::assertNotFalse($apply);
    self::assertLessThan($apply, $ready);

    foreach ([
      '/usr/bin/install',
      '/usr/sbin/visudo',
      'NOPASSWD:ALL',
      'NOPASSWD: ALL',
      'sudo -k -n -- /bin/bash',
      'sudo -k -n -- /bin/sh',
      'sudo -k -n -- /usr/bin/env',
      'sudo -k -n -- /usr/bin/python',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $wrapper);
    }
  }

  /**
   * Human/admin bootstrap installs exact reviewed fixed-purpose bytes only.
   */
  public function testHumanBootstrapContractIsExactAndLeastPrivilege(): void {
    $bootstrap = $this->source(self::BOOTSTRAP);
    $verify = $this->source(self::VERIFY);
    $render = $this->source(self::RENDER);
    $sudoers = $this->source(self::SUDOERS_TEMPLATE);
    $capability = json_decode($this->source(self::CAPABILITY), TRUE, 512, JSON_THROW_ON_ERROR);
    self::assertIsArray($capability);

    foreach ([
      '[[ "$(id -u)" -eq 0 ]]',
      "HELPER_DEST='/usr/local/sbin/agency-prod-php85-1353-apply'",
      "LIB_DIR='/usr/local/lib/agency-prod-php85-1353'",
      'PLAN_DEST="$LIB_DIR/remote-plan.sh"',
      "SUDOERS_DEST='/etc/sudoers.d/agency-prod-php85-1353'",
      '/usr/sbin/visudo -cf "$rendered"',
      '/usr/bin/install -o root -g root -m 0755 -- "$HELPER_SOURCE" "$HELPER_DEST"',
      '/usr/bin/install -o root -g root -m 0755 -- "$PLAN_SOURCE" "$PLAN_DEST"',
      '/usr/bin/install -o root -g root -m 0440 -- "$rendered" "$SUDOERS_DEST"',
      '/usr/sbin/visudo -cf "$SUDOERS_DEST"',
      'HELPER_METADATA=root:root:755',
      'PLAN_METADATA=root:root:755',
      'SUDOERS_METADATA=root:root:440',
      'GENERIC_ROOT_CAPABILITY=NONE',
      'PROD_PHP_MUTATION=NONE',
    ] as $required) {
      self::assertStringContainsString($required, $bootstrap);
    }

    self::assertStringContainsString('test "$(sha256sum "$HELPER_SOURCE" | awk', $verify);
    self::assertStringContainsString('test "$(sha256sum "$PLAN_SOURCE" | awk', $verify);
    self::assertStringContainsString('test "$(sha256sum "$rendered" | awk', $verify);

    $expectedSudoers = implode("\n", [
      '__SERVER_USER__ ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-php85-1353-apply READY',
      '__SERVER_USER__ ALL=(root) NOPASSWD: NOSETENV: /usr/local/sbin/agency-prod-php85-1353-apply APPLY',
      '',
    ]);
    self::assertSame($expectedSudoers, $sudoers);
    self::assertStringContainsString('NOPASSWD: NOSETENV:', $render);

    self::assertSame('FIXED_HELPER_READY_AND_APPLY_ONLY', $capability['sudoers']['nopasswd_scope'] ?? NULL);
    self::assertSame('HUMAN_ADMIN_INTERACTIVE', $capability['bootstrap']['boundary'] ?? NULL);
    self::assertSame('FORBIDDEN', $capability['bootstrap']['ordinary_prod_identity_self_bootstrap'] ?? NULL);
    self::assertSame('FORBIDDEN', $capability['sudoers']['generic_install'] ?? NULL);
    self::assertSame('FORBIDDEN', $capability['sudoers']['generic_visudo'] ?? NULL);
    self::assertSame('FORBIDDEN', $capability['sudoers']['generic_shell'] ?? NULL);
    self::assertSame('FORBIDDEN', $capability['sudoers']['nopasswd_all'] ?? NULL);

    foreach ([
      'NOPASSWD:ALL',
      'NOPASSWD: ALL',
      '/usr/bin/install',
      '/usr/sbin/visudo',
      '/bin/bash',
      '/bin/sh',
      '/usr/bin/env',
      '/usr/bin/python',
      '*',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $sudoers);
    }
  }

  /**
   * Root helper accepts fixed action tokens and no privileged path arguments.
   */
  public function testRootHelperUsesPersistentFixedPurposeCapability(): void {
    $apply = $this->source(self::APPLY);
    foreach ([
      'ACTION="${1:-}"',
      '[[ "$#" -eq 1 ]]',
      '[[ "$ACTION" == \'READY\' || "$ACTION" == \'APPLY\' ]]',
      "PLAN_SCRIPT='/usr/local/lib/agency-prod-php85-1353/remote-plan.sh'",
      'APPROVED_PLAN="$work_root/approved-plan.json"',
      'cat > "$APPROVED_PLAN"',
      'CAPABILITY_READY=YES',
      'PRIVILEGED_PATH_ARGUMENTS=NONE',
      '"$PLAN_SCRIPT" "$EXPECTED_MAIN" "$plan_id"',
      'apt-get --simulate install "${package_specs[@]}"',
      'apt-get install -y "${package_specs[@]}"',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }
    foreach ([
      'APPROVED_PLAN="${1:-}"',
      'PLAN_SCRIPT="${4:-}"',
      'CAPABILITY_STATE=',
      "\nsudo -",
      'rm -f -- "$CAPABILITY_SUDOERS"',
      'rm -f -- "$CAPABILITY_HELPER"',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $apply);
    }
  }

  /**
   * PLAN and APPLY shells remain syntactically valid.
   */
  public function testShellSyntax(): void {
    foreach ([
      self::PLAN,
      self::APPLY,
      self::WRAPPER,
      self::BOOTSTRAP,
      self::VERIFY,
      self::RENDER,
    ] as $relative) {
      $path = dirname(DRUPAL_ROOT) . '/' . $relative;
      $output = [];
      $status = 1;
      exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
      self::assertSame(0, $status, $relative . "\n" . implode("\n", $output));
    }
  }

  /**
   * Reads and parses a repository YAML file.
   *
   * @return array<string, mixed>
   *   The parsed YAML data.
   */
  private function parsed(string $path): array {
    $parsed = Yaml::decode($this->source($path));
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads a repository file.
   */
  private function source(string $path): string {
    $source = file_get_contents(dirname(DRUPAL_ROOT) . '/' . $path);
    self::assertIsString($source);
    return $source;
  }

}

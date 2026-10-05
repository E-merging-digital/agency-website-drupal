<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed PREPROD Ubuntu maintenance capability from #1182.
 *
 * @group agency_project_tests
 */
final class PreprodOsMaintenance1182WorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  private const WORKFLOW = '.github/workflows/preprod-os-maintenance-1182.yml';

  private const PLAN = 'scripts/preproduction-maintenance-1182/remote-plan.sh';

  private const APPLY = 'scripts/preproduction-maintenance-1182/remote-apply-root.sh';

  /**
   * The single dispatcher exposes only the fixed #1182 PLAN/APPLY command lane.
   */
  public function testDispatcherRouteIsHumanIssueBoundAndPreprodOnly(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $jobs = $dispatcher['jobs'] ?? [];
    $plan = $jobs['preprod-os-maintenance-1182-plan'] ?? NULL;
    $apply = $jobs['preprod-os-maintenance-1182-apply'] ?? NULL;
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
        'github.event.issue.number == 1182',
        "github.event.comment.author_association == 'OWNER'",
        "github.event.comment.user.login == 'E-merging-digital'",
        'github.event.comment.performed_via_github_app == null',
      ] as $required) {
        self::assertStringContainsString($required, $condition);
      }
    }

    self::assertSame(
      ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($plan['secrets'] ?? []),
    );
    self::assertSame(
      ['PREPROD_PROVISIONING_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($apply['secrets'] ?? []),
    );
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-preprod-os-maintenance-1182 plan'",
      (string) ($plan['if'] ?? ''),
    );
    self::assertStringContainsString(
      "startsWith(github.event.comment.body, '/agency-preprod-os-maintenance-1182 apply ')",
      (string) ($apply['if'] ?? ''),
    );
    self::assertStringNotContainsString(
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      implode("\n", array_keys($plan['secrets'] ?? [])),
    );
  }

  /**
   * PLAN stays read-only, temporary and fail-closed on unsafe host state.
   */
  public function testPlanIsReadOnlyBoundedAndSafetyGated(): void {
    $plan = $this->source(self::PLAN);
    foreach ([
      "ISSUE='1182'",
      "TARGET='PREPROD'",
      "MODE='PLAN'",
      "TARGET_KERNEL='6.8.0-139-generic'",
      'Dir::State=$apt_root/state',
      'Dir::Cache=$apt_root/cache',
      'Debug::NoLocking=1',
      'apt-get "${apt_options[@]}" update',
      'apt-get "${apt_options[@]}" --simulate upgrade',
      'PACKAGE_REMOVALS',
      'PACKAGE_ADDITIONS',
      'PACKAGE_UPGRADES',
      'UPGRADABLE_PACKAGES',
      'SECURITY_UPDATES_TOTAL',
      "'php_branch_8_4'",
      "'mariadb_branch_11_8'",
      "'no_package_additions'",
      "'no_package_removals'",
      "'no_failed_units'",
      "'drupal_health'",
      "'public_health'",
      "'disk_space_min_2gib'",
      'PLAN_DIGEST',
      'hashlib.sha256',
    ] as $required) {
      self::assertStringContainsString($required, $plan);
    }

    foreach ([
      'sudo ',
      'apt-get install',
      'apt-get upgrade',
      'full-upgrade',
      'dist-upgrade',
      'do-release-upgrade',
      'systemctl reboot',
      'systemctl restart',
      'drush cim',
      'drush updb',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $plan);
    }
  }

  /**
   * APPLY consumes one exact PLAN and cannot absorb package-set drift.
   */
  public function testApplyIsStalePlanBoundAndExactPackageOnly(): void {
    $apply = $this->source(self::APPLY);
    foreach ([
      'STALE_PLAN',
      'current_digest',
      '[[ "$current_digest" == "$EXPECTED_DIGEST" ]]',
      'PACKAGE_UPGRADES',
      'apt-get --simulate install --only-upgrade',
      'apt-get install -y --only-upgrade',
      'Exact apply simulation drift',
      'pre-os-maintenance-1182-',
      'vendor/bin/drush sql:dump --gzip',
      'etc/nginx etc/php/8.4 etc/mysql',
      'KEEP_PREVIOUS_KERNEL',
      'PROVIDER_SNAPSHOT_REF',
      'PROVIDER_SNAPSHOT_AUTOMATED_VERIFICATION',
      'systemctl reboot',
      'DRUPAL_DEPLOY',
      'DRUPAL_CONFIG_IMPORT',
      'PROD_ACCESS',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    foreach ([
      'do-release-upgrade',
      'full-upgrade',
      'dist-upgrade',
      'autoremove',
      '/var/www/agency/current',
      'config:import',
      'drush cim',
      'drush updb',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $apply);
    }
  }

  /**
   * Workflow separates PLAN from APPLY and reconciles an expected reboot.
   */
  public function testWorkflowSeparatesAuthorityAndPostRebootValidation(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);
    $on = $workflow['on'] ?? [];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayNotHasKey('workflow_dispatch', $on);
    self::assertArrayNotHasKey('issue_comment', $on);

    $jobs = $workflow['jobs'] ?? [];
    self::assertSame(
      [
        'validate-authority',
        'plan',
        'apply',
      ],
      array_keys($jobs),
    );
    self::assertStringContainsString(
      "needs.validate-authority.outputs.mode == 'PLAN'",
      (string) ($jobs['plan']['if'] ?? ''),
    );
    self::assertStringContainsString(
      "needs.validate-authority.outputs.mode == 'APPLY'",
      (string) ($jobs['apply']['if'] ?? ''),
    );

    foreach ([
      "test \"\$ISSUE_NUMBER\" = '1182'",
      "test \"\$COMMENT_LOGIN\" = 'E-merging-digital'",
      "test \"\$COMMENT_ASSOCIATION\" = 'OWNER'",
      "test \"\$COMMENT_VIA_APP\" = 'false'",
      "\"\$COMMENT_BODY\" == '/agency-preprod-os-maintenance-1182 plan'",
      'plan_run=([1-9][0-9]*)',
      'plan_digest=([0-9a-f]{64})',
      'snapshot_ref=([A-Za-z0-9._:@/-]{8,160})',
      'test "$WORKFLOW_SHA" = "$main_sha"',
      'scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION',
      'agency-preprod@$PREPROD_SERVER_HOST',
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'root@$PREPROD_SERVER_HOST',
      'saw_down=0',
      'reachable=0',
      "target_kernel = '6.8.0-139-generic'",
      "'approved_packages_converged'",
      "'NEW_UPDATES_AFTER_PLAN'",
      "'POST_REBOOT_VALIDATION': 'PASS'",
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringNotContainsString("\n      SSH_PRIVATE_KEY:", $source);
    self::assertStringNotContainsString("\n      SERVER_HOST:", $source);
    self::assertStringNotContainsString("\n      SERVER_USER:", $source);
    self::assertStringNotContainsString("body == '/agency-preprod-os-maintenance-1182 apply'", $source);
  }

  /**
   * Provider snapshot automation gap is explicit rather than silently skipped.
   */
  public function testRollbackGapAndLocalBackupsAreExplicit(): void {
    $workflow = $this->source(self::WORKFLOW);
    $apply = $this->source(self::APPLY);
    self::assertStringContainsString('snapshot_ref=', $workflow);
    self::assertStringContainsString('PROVIDER_SNAPSHOT_REF', $apply);
    self::assertStringContainsString(
      'PROVIDER_SNAPSHOT_AUTOMATED_VERIFICATION',
      $apply,
    );
    self::assertStringContainsString('UNAVAILABLE', $apply);
    self::assertStringContainsString('DATABASE_BACKUP', $apply);
    self::assertStringContainsString('SYSTEM_CONFIG_BACKUP', $apply);
  }

  /**
   * Both remote scripts are valid Bash.
   */
  public function testShellSyntax(): void {
    foreach ([self::PLAN, self::APPLY] as $relativePath) {
      $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
      $output = [];
      $status = 1;
      exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
      self::assertSame(0, $status, implode("\n", $output));
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

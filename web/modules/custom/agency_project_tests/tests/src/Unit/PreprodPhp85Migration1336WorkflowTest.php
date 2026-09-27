<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the governed PREPROD PHP 8.5 migration capability from #1336.
 *
 * @group agency_project_tests
 */
final class PreprodPhp85Migration1336WorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const WORKFLOW = '.github/workflows/preprod-php85-migration-1336.yml';
  private const PLAN = 'scripts/preproduction-php85-1336/remote-plan.sh';
  private const APPLY = 'scripts/preproduction-php85-1336/remote-apply-root.sh';

  public function testDispatcherIsExactOwnerIssueBoundAndSplitByMode(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $jobs = $dispatcher['jobs'] ?? [];
    $plan = $jobs['preprod-php85-migration-1336-plan'] ?? NULL;
    $apply = $jobs['preprod-php85-migration-1336-apply'] ?? NULL;
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
        'github.event.issue.number == 1336',
        "github.event.comment.author_association == 'OWNER'",
        "github.event.comment.user.login == 'E-merging-digital'",
        'github.event.comment.performed_via_github_app == null',
      ] as $required) {
        self::assertStringContainsString($required, $condition);
      }
    }

    self::assertStringContainsString(
      "github.event.comment.body == '/agency-preprod-php85-1336 plan'",
      (string) $plan['if'],
    );
    self::assertStringContainsString(
      "startsWith(github.event.comment.body, '/agency-preprod-php85-1336 apply ')",
      (string) $apply['if'],
    );
    self::assertSame(
      ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($plan['secrets'] ?? []),
    );
    self::assertSame(
      ['PREPROD_PROVISIONING_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($apply['secrets'] ?? []),
    );
  }

  public function testWorkflowIsReusableExactHeadAttemptOneAndNoBackdoor(): void {
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
      'test "$ISSUE_NUMBER" = \'1336\'',
      'test "$COMMENT_LOGIN" = \'E-merging-digital\'',
      'test "$COMMENT_ASSOCIATION" = \'OWNER\'',
      'test "$COMMENT_VIA_APP" = \'false\'',
      'test "$WORKFLOW_SHA" = "$main_sha"',
      "'/agency-preprod-php85-1336 plan'",
      'plan_run=([1-9][0-9]*)',
      'plan_digest=([0-9a-f]{64})',
      'AGENCY_PREPROD_PHP85_1336_APPLY_CONSUMED',
      'scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION',
      'agency-preprod@$PREPROD_SERVER_HOST',
      'root@$PREPROD_SERVER_HOST',
      'preprod-php85-1336-plan-',
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }
  }

  public function testPlanIsMutationFreeAllowlistedAndStableDigestExcludesDisk(): void {
    $plan = $this->source(self::PLAN);
    foreach ([
      "ISSUE='1336'",
      "TARGET='PREPROD'",
      "MODE='PLAN'",
      'php8.5-bcmath',
      'php8.5-cli',
      'php8.5-common',
      'php8.5-curl',
      'php8.5-fpm',
      'php8.5-gd',
      'php8.5-intl',
      'php8.5-mbstring',
      'php8.5-mysql',
      'php8.5-opcache',
      'php8.5-xml',
      'php8.5-zip',
      'apt-get --simulate install',
      'PACKAGE_REMOVALS',
      'PACKAGE_UPGRADES',
      'package_additions_exact_allowlist',
      'PHP84_PACKAGES_PRESENT',
      'PHP84_SERVICE_ACTIVE',
      'NGINX_VHOST_PHP84_SOCKET_MATCH',
      'FPM84_POOL_CONTRACT',
      'SENDMAIL_SAFETY_CONTRACT',
      'mutation_identity_keys = (',
      "receipt['PLAN_DIGEST'] = hashlib.sha256(canonical).hexdigest()",
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
      'systemctl stop',
      'systemctl disable',
      'drush cim',
      'drush updb',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $plan);
    }
    self::assertStringContainsString(
      "'DISK_AVAILABLE_KB': int(os.environ['DISK_AVAILABLE_KB'])",
      $plan,
    );
    self::assertStringNotContainsString(
      "    'DISK_AVAILABLE_KB',",
      $plan,
    );

    $first = $this->evaluatePlan(5 * 1024 * 1024);
    $second = $this->evaluatePlan(6 * 1024 * 1024);
    self::assertNotSame($first['DISK_AVAILABLE_KB'], $second['DISK_AVAILABLE_KB']);
    self::assertSame($first['PLAN_DIGEST'], $second['PLAN_DIGEST']);
  }

  public function testApplyPreservesPhp84AndRestrictsNginxToSocketOnlyDelta(): void {
    $apply = $this->source(self::APPLY);
    foreach ([
      'STALE_PLAN',
      'current_digest',
      '[[ "$current_digest" == "$EXPECTED_DIGEST" ]]',
      'apt-get --simulate install',
      'apt-get install -y',
      'dpkg-query -W',
      'systemctl is-active --quiet php8.4-fpm',
      '/run/php/php8.4-fpm-agency-preprod.sock',
      '/run/php/php8.5-fpm-agency-preprod.sock',
      'php-fpm8.5 -t',
      'systemctl enable --now php8.5-fpm',
      'NGINX_SOCKET_ONLY_DELTA failed',
      'nginx -t',
      'systemctl reload nginx',
      'side_effects=PASS',
      '127.0.0.1:18087/health/ready',
      'WEB_RUNTIME_PHP85',
      'rollback()',
      'ROLLBACK',
      'PHP84_REMOVAL',
      'DRUPAL_DEPLOY',
      'DRUPAL_CONFIG_IMPORT',
      'DB_MUTATION',
      'PROD_ACCESS',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }
    foreach ([
      'apt-get remove',
      'apt-get purge',
      'autoremove',
      'systemctl stop php8.4-fpm',
      'systemctl disable php8.4-fpm',
      'do-release-upgrade',
      'drush cim',
      'drush updb',
      '/var/www/agency/current',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $apply);
    }
  }

  public function testRemoteScriptsHaveValidBashSyntax(): void {
    foreach ([self::PLAN, self::APPLY] as $relativePath) {
      $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
      $output = [];
      $status = 1;
      exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
      self::assertSame(0, $status, implode("\n", $output));
    }
  }

  private function evaluatePlan(int $diskAvailableKb): array {
    $source = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match("/python3 - <<'PY'\\n(.*?)\\nPY\\n/s", $source, $matches),
    );

    $directory = sys_get_temp_dir() . '/agency-1336-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $packages = [
        'php8.5-bcmath','php8.5-cli','php8.5-common','php8.5-curl',
        'php8.5-fpm','php8.5-gd','php8.5-intl','php8.5-mbstring',
        'php8.5-mysql','php8.5-opcache','php8.5-xml','php8.5-zip',
      ];
      $candidateLines = [];
      $simulationLines = [];
      foreach ($packages as $package) {
        $candidateLines[] = $package . "\t8.5.11-1";
        $simulationLines[] = 'Inst ' . $package . ' (8.5.11-1 repo [amd64])';
      }
      file_put_contents($directory . '/candidates.tsv', implode("\n", $candidateLines) . "\n");
      file_put_contents($directory . '/install-sim.raw', implode("\n", $simulationLines) . "\n");
      file_put_contents($directory . '/failed.raw', '');
      $script = $directory . '/plan.py';
      file_put_contents($script, $matches[1] . "\n");

      $environment = [
        'WORK_ROOT' => $directory,
        'MAIN_SHA' => str_repeat('a', 40),
        'PLAN_ID' => 'plan-1336-deterministic-fixture-r1',
        'OS_PRETTY_NAME' => 'Ubuntu 24.04.5 LTS',
        'VERSION_ID' => '24.04',
        'KERNEL_RUNNING' => '6.8.0-139-generic',
        'REBOOT_REQUIRED' => 'NO',
        'CURRENT_PHP_CLI' => '8.4.25',
        'CURRENT_PHP_FPM' => 'PHP 8.4.25 (fpm-fcgi)',
        'CURRENT_PHP_FPM_SERVICE' => 'active',
        'CURRENT_PREPROD_SOCKET' => '/run/php/php8.4-fpm-agency-preprod.sock',
        'NGINX_SERVICE' => 'active',
        'MARIADB_SERVICE' => 'active',
        'MARIADB_VERSION' => 'mariadb  Ver 15.1 Distrib 11.8.9-MariaDB',
        'DRUPAL_HEALTH' => 'PASS',
        'PUBLIC_HEALTH' => 'PASS',
        'DISK_AVAILABLE_KB' => (string) $diskAvailableKb,
        'PHP84_PACKAGES_PRESENT' => 'YES',
        'PHP84_SERVICE_ACTIVE' => 'YES',
        'NGINX_VHOST_PHP84_SOCKET_MATCH' => 'YES',
        'FPM84_POOL_CONTRACT' => 'YES',
        'SENDMAIL_SAFETY_CONTRACT' => 'YES',
        'NGINX_VHOST_SHA256' => str_repeat('b', 64),
        'FPM84_POOL_SHA256' => str_repeat('c', 64),
        'CANDIDATE_GAP' => 'NO',
      ];
      $assignments = [];
      foreach ($environment as $name => $value) {
        $assignments[] = $name . '=' . escapeshellarg($value);
      }
      $command = 'env ' . implode(' ', $assignments)
        . ' python3 ' . escapeshellarg($script) . ' 2>&1';
      $output = [];
      $status = 1;
      exec($command, $output, $status);
      self::assertSame(0, $status, implode("\n", $output));
      $receipt = json_decode(implode("\n", $output), TRUE, 32, JSON_THROW_ON_ERROR);
      self::assertIsArray($receipt);
      return $receipt;
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $path) {
        @unlink($path);
      }
      @rmdir($directory);
    }
  }

  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

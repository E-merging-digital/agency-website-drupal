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

  /**
   * Dispatcher remains exact, owner-only, issue-bound and mode-separated.
   */
  public function testDispatcherIsExactOwnerIssueBoundAndSplitByMode(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $jobs = $dispatcher['jobs'] ?? [];
    $plan = $jobs['preprod-php85-migration-1336-plan'] ?? NULL;
    $apply = $jobs['preprod-php85-migration-1336-apply'] ?? NULL;
    $reboot = $jobs['preprod-php85-migration-1336-reboot'] ?? NULL;
    $repair = $jobs['preprod-php85-migration-1336-repair-vhost-metadata'] ?? NULL;
    self::assertIsArray($plan);
    self::assertIsArray($apply);
    self::assertIsArray($reboot);
    self::assertIsArray($repair);

    foreach ([$plan, $apply, $reboot, $repair] as $job) {
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
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-preprod-php85-1336 reboot'",
      (string) $reboot['if'],
    );
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-preprod-php85-1336 repair-vhost-metadata'",
      (string) $repair['if'],
    );
    self::assertSame(
      ['PREPROD_PROVISIONING_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($repair['secrets'] ?? []),
    );
    self::assertSame(
      [
        'PREPROD_SSH_PRIVATE_KEY',
        'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
        'PREPROD_SERVER_HOST',
      ],
      array_keys($reboot['secrets'] ?? []),
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

  /**
   * Reusable workflow enforces exact-main attempt-one authority.
   */
  public function testWorkflowIsReusableExactHeadAttemptOneAndNoBackdoor(): void {
    $workflow = $this->parsed(self::WORKFLOW);
    $source = $this->source(self::WORKFLOW);
    $on = $workflow['on'] ?? [];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayNotHasKey('workflow_dispatch', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertSame(
      ['validate-authority', 'plan', 'apply', 'reboot', 'repair'],
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
      "'/agency-preprod-php85-1336 reboot'",
      "'/agency-preprod-php85-1336 repair-vhost-metadata'",
      'AGENCY_PREPROD_PHP85_1336_VHOST_METADATA_REPAIR_CONSUMED',
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

  /**
   * Authority consumption markers use explicit repository identity.
   */
  public function testAuthorityConsumptionMarkersNeedNoCheckout(): void {
    $workflow = $this->source(self::WORKFLOW);
    self::assertSame(
      1,
      preg_match(
        '/\\n  validate-authority:\\n(.*?)(?=\\n  plan:)/s',
        $workflow,
        $match,
      ),
    );
    $authority = $match[1];

    self::assertStringNotContainsString('actions/checkout', $authority);
    self::assertStringNotContainsString('gh issue comment 1336', $authority);
    self::assertSame(
      3,
      substr_count(
        $authority,
        'gh api --method POST "repos/$GITHUB_REPOSITORY/issues/1336/comments" -f body="$marker"',
      ),
    );
    self::assertSame(
      3,
      substr_count(
        $authority,
        'gh api "repos/$GITHUB_REPOSITORY/issues/1336/comments" --paginate',
      ),
    );

    foreach ([
      'AGENCY_PREPROD_PHP85_1336_APPLY_CONSUMED',
      'AGENCY_PREPROD_PHP85_1336_REBOOT_CONSUMED',
      'AGENCY_PREPROD_PHP85_1336_VHOST_METADATA_REPAIR_CONSUMED',
    ] as $marker) {
      $markerPosition = strpos($authority, $marker);
      self::assertNotFalse($markerPosition);
      $lookupPosition = strpos(
        $authority,
        'gh api "repos/$GITHUB_REPOSITORY/issues/1336/comments" --paginate',
        $markerPosition,
      );
      self::assertNotFalse($lookupPosition);
      $publicationPosition = strpos(
        $authority,
        'gh api --method POST "repos/$GITHUB_REPOSITORY/issues/1336/comments" -f body="$marker"',
        $lookupPosition,
      );
      self::assertNotFalse($publicationPosition);
      self::assertLessThan($publicationPosition, $lookupPosition);
    }
  }

  /**
   * PLAN is mutation-free and excludes volatile disk from identity.
   */
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
      'php8.5-xml',
      'php8.5-zip',
      'apt-get --simulate install',
      'REQUESTED_PACKAGE_ALLOWLIST',
      'PACKAGE_ADDITIONS',
      'TRANSITIVE_ADDITIONS',
      'PACKAGE_REMOVALS',
      'PACKAGE_UPGRADES',
      'all_requested_packages_present_in_simulation',
      'transitive_additions_php85_only',
      'PHP84_PACKAGES_PRESENT',
      'PHP84_SERVICE_ACTIVE',
      'NGINX_VHOST_PHP84_SOCKET_MATCH',
      'FPM84_POOL_CONTRACT',
      'SENDMAIL_SAFETY_CONTRACT',
      'REBOOT_REQUIRED_PACKAGES_SOURCE',
      'REBOOT_REQUIRED_PACKAGES',
      'NGINX_FASTCGI_PASS_VALUES',
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
    self::assertStringNotContainsString('php8.5-opcache', $plan);
    self::assertStringNotContainsString('NGINX_VHOST_CONTENT', $plan);

    $first = $this->evaluatePlan(5 * 1024 * 1024);
    $second = $this->evaluatePlan(6 * 1024 * 1024);
    self::assertSame('PASS', $first['STATUS']);
    self::assertSame('PASS', $first['SAFETY_GATE']);
    self::assertSame([], $first['FAILED_CHECKS']);
    self::assertSame('PASS', $first['PHP85_INSTALL_SIMULATION']);
    self::assertCount(11, $first['REQUESTED_PACKAGE_ALLOWLIST']);
    self::assertNotContains('php8.5-opcache', $first['REQUESTED_PACKAGE_ALLOWLIST']);
    self::assertContains(
      'php8.5-readline',
      array_column($first['TRANSITIVE_ADDITIONS'], 'name'),
    );
    self::assertNotSame($first['DISK_AVAILABLE_KB'], $second['DISK_AVAILABLE_KB']);
    self::assertSame($first['PLAN_DIGEST'], $second['PLAN_DIGEST']);
  }

  /**
   * PLAN receipt exposes bounded reboot-package and Nginx FastCGI evidence.
   */
  public function testPlanReceiptIncludesBoundedRebootAndNginxEvidence(): void {
    $present = $this->executePlan(
      5 * 1024 * 1024,
      ['php8.5-readline'],
      FALSE,
      'PASS',
      'PRESENT',
      ['linux-image-6.8.0-139-generic', 'linux-base'],
      [
        'unix:/run/php/php8.4-fpm-agency-preprod.sock',
        'unix:/run/php/php8.4-fpm-agency-preprod.sock',
      ],
    );
    self::assertSame(0, $present['status'], $present['output']);
    $presentReceipt = json_decode($present['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertSame('PRESENT', $presentReceipt['REBOOT_REQUIRED_PACKAGES_SOURCE']);
    self::assertSame(
      ['linux-base', 'linux-image-6.8.0-139-generic'],
      $presentReceipt['REBOOT_REQUIRED_PACKAGES'],
    );
    self::assertSame(
      ['unix:/run/php/php8.4-fpm-agency-preprod.sock'],
      $presentReceipt['NGINX_FASTCGI_PASS_VALUES'],
    );

    $absent = $this->executePlan(
      5 * 1024 * 1024,
      ['php8.5-readline'],
      FALSE,
      'PASS',
      'ABSENT',
      [],
      ['unix:/run/php/php8.4-fpm-agency-preprod.sock'],
    );
    self::assertSame(0, $absent['status'], $absent['output']);
    $absentReceipt = json_decode($absent['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertSame('ABSENT', $absentReceipt['REBOOT_REQUIRED_PACKAGES_SOURCE']);
    self::assertSame([], $absentReceipt['REBOOT_REQUIRED_PACKAGES']);
  }

  /**
   * Host evidence extractors emit only bounded normalized values.
   */
  public function testPlanHostEvidenceExtractorsAreBoundedAndNormalized(): void {
    $plan = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match("/<<'PY_REBOOT'\n(.*?)\nPY_REBOOT/s", $plan, $rebootMatch),
    );
    self::assertSame(
      1,
      preg_match("/<<'PY_NGINX'\n(.*?)\nPY_NGINX/s", $plan, $nginxMatch),
    );

    $directory = sys_get_temp_dir() . '/agency-1336-evidence-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $rebootInput = $directory . '/reboot-required.pkgs';
      file_put_contents(
        $rebootInput,
        "linux-image-6.8.0-139-generic\nlinux-base\nlinux-base\n",
      );
      $rebootScript = $directory . '/reboot.py';
      file_put_contents($rebootScript, $rebootMatch[1] . "\n");

      $output = [];
      $status = 1;
      exec(
        'python3 ' . escapeshellarg($rebootScript) . ' '
        . escapeshellarg($rebootInput) . ' 2>&1',
        $output,
        $status,
      );
      self::assertSame(0, $status, implode("\n", $output));
      self::assertSame(
        ['linux-base', 'linux-image-6.8.0-139-generic'],
        $output,
      );

      $vhost = $directory . '/agency-preprod';
      file_put_contents(
        $vhost,
        <<<'NGINX'
server {
  set $private_value do-not-publish;
  fastcgi_pass unix:/run/php/php8.4-fpm-agency-preprod.sock;
  fastcgi_pass 127.0.0.1:9000; # bounded target
  fastcgi_pass unix:/run/php/php8.4-fpm-agency-preprod.sock;
}
NGINX
        . "\n",
      );
      $nginxScript = $directory . '/nginx.py';
      file_put_contents($nginxScript, $nginxMatch[1] . "\n");

      $output = [];
      $status = 1;
      $nginxStatus = $directory . '/nginx.status';
      exec(
        'python3 ' . escapeshellarg($nginxScript) . ' '
        . escapeshellarg($vhost) . ' '
        . escapeshellarg($nginxStatus) . ' 2>&1',
        $output,
        $status,
      );
      self::assertSame(0, $status, implode("\n", $output));
      self::assertSame(
        [
          '127.0.0.1:9000',
          'unix:/run/php/php8.4-fpm-agency-preprod.sock',
        ],
        $output,
      );
      self::assertNotContains('do-not-publish', $output);
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($directory);
    }
  }

  /**
   * PLAN accepts repeated identical FastCGI locations.
   *
   * Mixed targets remain fail-closed.
   */
  public function testPlanNginxGateUsesNormalizedUniqueFastcgiTargets(): void {
    $multi = $this->executePlan(
      5 * 1024 * 1024,
      ['php8.5-readline'],
      FALSE,
      'PASS',
      'ABSENT',
      [],
      [
        'unix:/run/php/php8.4-fpm-agency-preprod.sock',
        'unix:/run/php/php8.4-fpm-agency-preprod.sock',
      ],
    );
    self::assertSame(0, $multi['status'], $multi['output']);
    $multiReceipt = json_decode($multi['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertSame(
      ['unix:/run/php/php8.4-fpm-agency-preprod.sock'],
      $multiReceipt['NGINX_FASTCGI_PASS_VALUES'],
    );
    self::assertNotContains(
      'nginx_vhost_php84_socket_match',
      $multiReceipt['FAILED_CHECKS'],
    );

    $mixed = $this->executePlan(
      5 * 1024 * 1024,
      ['php8.5-readline'],
      FALSE,
      'PASS',
      'ABSENT',
      [],
      [
        'unix:/run/php/php8.4-fpm-agency-preprod.sock',
        '127.0.0.1:9000',
      ],
    );
    self::assertSame(65, $mixed['status'], $mixed['output']);
    $mixedReceipt = json_decode($mixed['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertContains(
      'nginx_vhost_php84_socket_match',
      $mixedReceipt['FAILED_CHECKS'],
    );

    $none = $this->executePlan(
      5 * 1024 * 1024,
      ['php8.5-readline'],
      FALSE,
      'PASS',
      'ABSENT',
      [],
      [],
    );
    self::assertSame(65, $none['status'], $none['output']);
    $noneReceipt = json_decode($none['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertContains(
      'nginx_vhost_php84_socket_match',
      $noneReceipt['FAILED_CHECKS'],
    );
  }

  /**
   * APPLY globally replaces the approved socket and fails closed on drift.
   */
  public function testApplyNginxSocketDeltaSupportsMultipleLocationsAndFailsClosed(): void {
    $apply = $this->source(self::APPLY);
    self::assertSame(
      1,
      preg_match(
        '/# Derive the candidate vhost.*?<<\'PY\'\n(.*?)\nPY\n\npython3 - "\$NGINX_VHOST" "\$work_root\/nginx\.candidate" <<\'PY\'\n(.*?)\nPY/s',
        $apply,
        $matches,
      ),
    );

    $directory = sys_get_temp_dir() . '/agency-1336-nginx-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $generator = $directory . '/generate.py';
      $validator = $directory . '/validate.py';
      file_put_contents($generator, $matches[1] . "\n");
      file_put_contents($validator, $matches[2] . "\n");

      $old = '/run/php/php8.4-fpm-agency-preprod.sock';
      $new = '/run/php/php8.5-fpm-agency-preprod.sock';
      $source = $directory . '/source.conf';
      $candidate = $directory . '/candidate.conf';
      $multi = "location /index {\n  fastcgi_pass unix:$old;\n}\n"
        . "location /update {\n  fastcgi_pass unix:$old;\n}\n";
      file_put_contents($source, $multi);

      $output = [];
      $status = 1;
      exec(
        'python3 ' . escapeshellarg($generator) . ' '
        . escapeshellarg($source) . ' ' . escapeshellarg($candidate) . ' 2>&1',
        $output,
        $status,
      );
      self::assertSame(0, $status, implode("\n", $output));
      $candidateBytes = (string) file_get_contents($candidate);
      self::assertSame(str_replace($old, $new, $multi), $candidateBytes);
      self::assertSame(0, substr_count($candidateBytes, $old));
      self::assertSame(2, substr_count($candidateBytes, $new));

      $output = [];
      $status = 1;
      exec(
        'python3 ' . escapeshellarg($validator) . ' '
        . escapeshellarg($source) . ' ' . escapeshellarg($candidate) . ' 2>&1',
        $output,
        $status,
      );
      self::assertSame(0, $status, implode("\n", $output));

      foreach ([
        "server { return 200; }\n",
        "location / {\n  fastcgi_pass unix:$new;\n}\n",
        "location /a {\n  fastcgi_pass unix:$old;\n}\n"
        . "location /b {\n  fastcgi_pass 127.0.0.1:9000;\n}\n",
      ] as $invalid) {
        file_put_contents($source, $invalid);
        @unlink($candidate);
        $output = [];
        $status = 0;
        exec(
          'python3 ' . escapeshellarg($generator) . ' '
          . escapeshellarg($source) . ' ' . escapeshellarg($candidate) . ' 2>&1',
          $output,
          $status,
        );
        self::assertNotSame(0, $status, implode("\n", $output));
      }
    }
    finally {
      foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($directory);
    }
  }

  /**
   * PLAN summary uses literal formatting without shell command substitution.
   */
  public function testPlanSummaryRendersLiteralPopulatedValues(): void {
    $workflow = $this->source(self::WORKFLOW);

    self::assertStringContainsString('printf -v body', $workflow);
    self::assertStringNotContainsString(
      'body="$(cat <<EOF_BODY' . "\n"
      . '          #1336 PREPROD PHP 8.5 migration PLAN evidence preserved.',
      $workflow,
    );
    foreach ([
      '`MODE=%s`',
      '`RUN=%s`',
      '`PLAN_ID=%s`',
      '`PLAN_STATUS=%s`',
      '`PLAN_DIGEST=%s`',
      '`FAILED_CHECKS=%s`',
      '`TARGET=%s`',
      '`PREPROD_MUTATION=%s`',
      '`PHP84_REMOVAL=%s`',
    ] as $literalField) {
      self::assertStringContainsString($literalField, $workflow);
    }
  }

  /**
   * Candidate extraction remains pipefail-safe and consumes the full stream.
   */
  public function testAptCandidateParserConsumesToEofUnderPipefail(): void {
    $plan = $this->source(self::PLAN);

    self::assertStringContainsString(
      '/Candidate:/ { candidate = $2 }',
      $plan,
    );
    self::assertStringContainsString(
      'END { if (candidate != "") print candidate }',
      $plan,
    );
    self::assertStringNotContainsString(
      "/Candidate:/ {print $2; exit}",
      $plan,
    );
    self::assertSame(
      1,
      preg_match(
        '/candidate="\\$\\(apt-cache policy.*?\\n  \'\\)"$/ms',
        $plan,
        $candidateParser,
      ),
    );
    self::assertStringNotContainsString('exit', $candidateParser[0]);
    self::assertStringNotContainsString('head -n 1', $candidateParser[0]);
    self::assertStringNotContainsString('grep -m1', $candidateParser[0]);

    $script = <<<'BASH'
set -o pipefail
candidate="$(
  {
    printf '%s\n' 'Package: php8.5-cli'
    printf '%s\n' '  Candidate: 8.5.11-1'
    i=0
    while [ "$i" -lt 20000 ]; do
      printf '  Version table trailing-line-%05d\n' "$i"
      i=$((i + 1))
    done
  } | awk '
    /Candidate:/ { candidate = $2 }
    END { if (candidate != "") print candidate }
  '
)"
status=$?
printf 'PIPELINE_STATUS=%s\n' "$status"
printf 'CANDIDATE=%s\n' "$candidate"
exit "$status"
BASH;

    $output = [];
    $status = 1;
    exec('bash -c ' . escapeshellarg($script) . ' 2>&1', $output, $status);
    $result = implode("\n", $output);

    self::assertSame(0, $status, $result);
    self::assertStringContainsString('PIPELINE_STATUS=0', $result);
    self::assertStringContainsString('CANDIDATE=8.5.11-1', $result);
  }

  /**
   * Candidate gaps preserve a NOT_RUN simulation receipt and fail closed.
   */
  public function testCandidateGapPreservesNotRunSimulationReceipt(): void {
    $result = $this->executePlan(
      5 * 1024 * 1024,
      ['php8.5-readline'],
      TRUE,
      'NOT_RUN',
    );

    self::assertSame(65, $result['status']);
    $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertSame('FAIL', $receipt['STATUS']);
    self::assertSame('FAIL', $receipt['SAFETY_GATE']);
    self::assertSame('NOT_RUN', $receipt['PHP85_INSTALL_SIMULATION']);
    self::assertContains('php85_candidates_present', $receipt['FAILED_CHECKS']);
    self::assertContains(
      'php85_install_simulation_pass',
      $receipt['FAILED_CHECKS'],
    );
  }

  /**
   * Simulation command failure preserves a FAIL receipt and exit 65.
   */
  public function testSimulationFailurePreservesFailedReceipt(): void {
    $result = $this->executePlan(
      5 * 1024 * 1024,
      ['php8.5-readline'],
      FALSE,
      'FAIL',
    );

    self::assertSame(65, $result['status']);
    $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertSame('FAIL', $receipt['STATUS']);
    self::assertSame('FAIL', $receipt['SAFETY_GATE']);
    self::assertSame('FAIL', $receipt['PHP85_INSTALL_SIMULATION']);
    self::assertContains(
      'php85_install_simulation_pass',
      $receipt['FAILED_CHECKS'],
    );
  }

  /**
   * Actual shell apt simulation failure survives through receipt generation.
   */
  public function testActualAptSimulationFailurePathEmitsFailedReceipt(): void {
    $source = $this->source(self::PLAN);

    self::assertSame(
      1,
      preg_match(
        '/(: >"\\$work_root\\/install-sim\\.raw"\\n.*?)(?=\\n\\nexport MAIN_SHA PLAN_ID ISSUE TARGET MODE)/s',
        $source,
        $shellMatch,
      ),
    );
    self::assertSame(
      1,
      preg_match("/python3 - <<'PY'\\n(.*?)\\nPY\\n/s", $source, $pythonMatch),
    );

    $directory = sys_get_temp_dir() . '/agency-1336-shell-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    self::assertTrue(mkdir($bin, 0700, TRUE));

    try {
      $packages = [
        'php8.5-bcmath', 'php8.5-cli', 'php8.5-common', 'php8.5-curl',
        'php8.5-fpm', 'php8.5-gd', 'php8.5-intl', 'php8.5-mbstring',
        'php8.5-mysql', 'php8.5-xml', 'php8.5-zip',
      ];
      $candidateLines = [];
      $packageSpecs = [];
      foreach ($packages as $package) {
        $candidateLines[] = $package . "\t8.5.11-1";
        $packageSpecs[] = $package . '=8.5.11-1';
      }
      file_put_contents(
        $directory . '/candidates.tsv',
        implode("\n", $candidateLines) . "\n",
      );
      file_put_contents($directory . '/failed.raw', '');
      file_put_contents($directory . '/reboot-required-packages.raw', '');
      file_put_contents(
        $directory . '/nginx-fastcgi-pass.raw',
        "unix:/run/php/php8.4-fpm-agency-preprod.sock\n",
      );

      $aptLog = $directory . '/apt.log';
      $aptStub = <<<'BASH'
#!/usr/bin/env bash
set -eu
printf '%s\n' "$*" > "$APT_LOG"
printf '%s\n' 'synthetic apt simulation failure' >&2
exit 42
BASH;
      file_put_contents($bin . '/apt-get', $aptStub . "\n");
      chmod($bin . '/apt-get', 0700);

      $quotedSpecs = implode(
        ' ',
        array_map(static fn (string $spec): string => escapeshellarg($spec), $packageSpecs),
      );
      $script = <<<'BASH'
set -Eeuo pipefail
work_root=__WORK_ROOT__
candidate_gap='NO'
package_specs=(__PACKAGE_SPECS__)
export PATH=__BIN__:"$PATH"
export APT_LOG=__APT_LOG__
__SIMULATION_BLOCK__
printf 'APT_SIMULATION_EXECUTED=%s\n' "$(test -s "$APT_LOG" && echo YES || echo NO)" > "$work_root/shell-state"
printf 'APT_SIMULATION_RC=%s\n' "$install_sim_rc" >> "$work_root/shell-state"
printf 'PHP85_INSTALL_SIMULATION=%s\n' "$php85_install_simulation" >> "$work_root/shell-state"

export MAIN_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export PLAN_ID=plan-1336-shell-failure
export ISSUE=1336
export TARGET=PREPROD
export MODE=PLAN
export OS_PRETTY_NAME='Ubuntu 24.04.5 LTS'
export VERSION_ID=24.04
export KERNEL_RUNNING=6.8.0-139-generic
export REBOOT_REQUIRED=NO
export REBOOT_REQUIRED_PACKAGES_SOURCE=ABSENT
export CURRENT_PHP_CLI=8.4.25
export CURRENT_PHP_FPM='PHP 8.4.25 (fpm-fcgi)'
export CURRENT_PHP_FPM_SERVICE=active
export CURRENT_PREPROD_SOCKET=/run/php/php8.4-fpm-agency-preprod.sock
export NGINX_SERVICE=active
export MARIADB_SERVICE=active
export MARIADB_VERSION='mariadb  Ver 15.1 Distrib 11.8.9-MariaDB'
export DRUPAL_HEALTH=PASS
export PUBLIC_HEALTH=PASS
export DISK_AVAILABLE_KB=5242880
export PHP84_PACKAGES_PRESENT=YES
export PHP84_SERVICE_ACTIVE=YES
export NGINX_VHOST_PHP84_SOCKET_MATCH=YES
export FPM84_POOL_CONTRACT=YES
export SENDMAIL_SAFETY_CONTRACT=YES
export NGINX_VHOST_SHA256=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
export FPM84_POOL_SHA256=cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
export CANDIDATE_GAP="$candidate_gap"
export PHP85_INSTALL_SIMULATION="$php85_install_simulation"
export WORK_ROOT="$work_root"

python3 - <<'PY'
__PYTHON_BLOCK__
PY
BASH;

      $script = str_replace(
        [
          '__WORK_ROOT__',
          '__PACKAGE_SPECS__',
          '__BIN__',
          '__APT_LOG__',
          '__SIMULATION_BLOCK__',
          '__PYTHON_BLOCK__',
        ],
        [
          escapeshellarg($directory),
          $quotedSpecs,
          escapeshellarg($bin),
          escapeshellarg($aptLog),
          $shellMatch[1],
          $pythonMatch[1],
        ],
        $script,
      );

      $scriptPath = $directory . '/failure-path.sh';
      file_put_contents($scriptPath, $script . "\n");
      chmod($scriptPath, 0700);

      $output = [];
      $status = 1;
      exec('bash ' . escapeshellarg($scriptPath) . ' 2>&1', $output, $status);
      $receiptJson = implode("\n", $output);

      self::assertSame(65, $status, $receiptJson);
      self::assertSame(
        "--simulate install " . implode(' ', $packageSpecs),
        trim((string) file_get_contents($aptLog)),
      );

      $shellState = (string) file_get_contents($directory . '/shell-state');
      self::assertStringContainsString('APT_SIMULATION_EXECUTED=YES', $shellState);
      self::assertStringContainsString('APT_SIMULATION_RC=42', $shellState);
      self::assertStringContainsString(
        'PHP85_INSTALL_SIMULATION=FAIL',
        $shellState,
      );

      $receipt = json_decode($receiptJson, TRUE, 32, JSON_THROW_ON_ERROR);
      self::assertSame('FAIL', $receipt['STATUS']);
      self::assertSame('FAIL', $receipt['SAFETY_GATE']);
      self::assertSame('FAIL', $receipt['PHP85_INSTALL_SIMULATION']);
      self::assertContains(
        'php85_install_simulation_pass',
        $receipt['FAILED_CHECKS'],
      );
    }
    finally {
      foreach (glob($bin . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($bin);
      foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
      }
      @rmdir($directory);
    }
  }

  /**
   * Failed safety gates preserve bounded evidence and remain failed.
   */
  public function testFailedPlanReceiptIsBoundedAndFailsClosed(): void {
    $result = $this->executePlan(
      5 * 1024 * 1024,
      ['unexpected-runtime-package'],
    );

    self::assertSame(65, $result['status']);
    $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($receipt);
    self::assertSame('FAIL', $receipt['STATUS']);
    self::assertSame('FAIL', $receipt['SAFETY_GATE']);
    self::assertSame(
      ['transitive_additions_php85_only'],
      $receipt['FAILED_CHECKS'],
    );

    foreach ([
      'PHP85_PACKAGE_CANDIDATES',
      'REBOOT_REQUIRED',
      'NGINX_VHOST_PHP84_SOCKET_MATCH',
      'PACKAGE_ADDITIONS',
      'TRANSITIVE_ADDITIONS',
      'PACKAGE_UPGRADES',
      'PACKAGE_REMOVALS',
      'PHP84_PACKAGES_PRESENT',
      'PHP84_SERVICE_ACTIVE',
      'FPM84_POOL_CONTRACT',
      'SENDMAIL_SAFETY_CONTRACT',
    ] as $field) {
      self::assertArrayHasKey($field, $receipt);
    }
    self::assertContains(
      'unexpected-runtime-package',
      array_column($receipt['TRANSITIVE_ADDITIONS'], 'name'),
    );
    self::assertSame([], $receipt['PACKAGE_UPGRADES']);
    self::assertSame([], $receipt['PACKAGE_REMOVALS']);
    self::assertSame('YES', $receipt['PHP84_PACKAGES_PRESENT']);
    self::assertSame('YES', $receipt['PHP84_SERVICE_ACTIVE']);
  }

  /**
   * Workflow preserves failed PLAN evidence without making it APPLY-eligible.
   */
  public function testFailedPlanArtifactIsUploadedWhilePlanRemainsFailure(): void {
    $source = $this->source(self::WORKFLOW);

    foreach ([
      'receipt_valid=true',
      'test "$plan_rc" -eq 65',
      'exit "$plan_rc"',
      "steps.plan_result.outputs.receipt_valid == 'true'",
      '.STATUS == "FAIL"',
      '.SAFETY_GATE == "FAIL"',
      '(.FAILED_CHECKS | length) > 0',
      'Artifact publication preserves evidence only.',
      'test "$(jq -r \'.conclusion\' <<<"$run_json")" = \'success\'',
      'and .FAILED_CHECKS == []',
      'and .PHP85_INSTALL_SIMULATION == "PASS"',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringContainsString(
      'if: ${{ always() && steps.plan_result.outputs.receipt_valid == \'true\' }}',
      $source,
    );
  }

  /**
   * Workflow gates and safely summarizes the PHP 8.5 OPcache APPLY result.
   */
  public function testApplyWorkflowRequiresOpcacheAndUsesLiteralSummary(): void {
    $workflow = $this->source(self::WORKFLOW);

    self::assertStringContainsString(
      'and .PHP85_OPCACHE_AVAILABLE == "PASS"',
      $workflow,
    );

    self::assertStringContainsString(
      '#1336 PREPROD PHP 8.5 migration APPLY completed.',
      $workflow,
    );
    self::assertStringContainsString('printf -v body', $workflow);
    self::assertStringNotContainsString(
      'body="$(cat <<EOF_BODY' . "\n"
      . '          #1336 PREPROD PHP 8.5 migration APPLY completed.',
      $workflow,
    );

    foreach ([
      '`STATUS=%s`',
      '`RUN=%s`',
      '`PREPROD_PHP=%s`',
      '`PHP85_OPCACHE_AVAILABLE=%s`',
      '`PHP84_FPM=%s`',
      '`NGINX_SOCKET_ONLY_DELTA=%s`',
      '`PUBLIC_HEALTH=%s`',
      '`PROD_ACCESS=%s`',
    ] as $literalField) {
      self::assertStringContainsString($literalField, $workflow);
    }
  }

  /**
   * APPLY preserves PHP 8.4 and limits Nginx to the socket-only delta.
   */
  public function testApplyPreservesPhp84AndRestrictsNginxToSocketOnlyDelta(): void {
    $apply = $this->source(self::APPLY);
    foreach ([
      'STALE_PLAN',
      'current_digest',
      '[[ "$current_digest" == "$EXPECTED_DIGEST" ]]',
      'apt-get --simulate install',
      'REQUESTED_PACKAGE_ALLOWLIST',
      '[[ "${#package_specs[@]}" -eq 11 ]]',
      'actual_additions != approved[\'PACKAGE_ADDITIONS\']',
      'actual_upgrades != approved[\'PACKAGE_UPGRADES\']',
      'actual_removals != approved[\'PACKAGE_REMOVALS\']',
      'apt-get install -y',
      'dpkg-query -W',
      'systemctl is-active --quiet php8.4-fpm',
      '/run/php/php8.4-fpm-agency-preprod.sock',
      '/run/php/php8.5-fpm-agency-preprod.sock',
      'php-fpm8.5 -t',
      'extension_loaded("Zend OPcache")',
      'PHP85_OPCACHE_AVAILABLE:"PASS"',
      'systemctl enable --now php8.5-fpm',
      'NGINX_SOCKET_ONLY_DELTA failed',
      'nginx -t',
      'systemctl reload nginx',
      'side_effects=PASS',
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
    self::assertStringNotContainsString('127.0.0.1:18087', $apply);
    self::assertStringNotContainsString('INTERNAL_READINESS', $apply);
    self::assertStringNotContainsString('chmod -R go-rwx "$backup_root"', $apply);
    self::assertStringContainsString('install -d -m 700 "$backup_root"', $apply);
    self::assertStringContainsString(
      'stat -c \'%a\' "$backup_root/nginx-agency-preprod.before"',
      $apply,
    );

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

  /**
   * REBOOT PRE/POST PLAN IDs satisfy the strict remote PLAN contract.
   */
  public function testRebootPlanIdsRespectStrictRemotePlanContract(): void {
    $workflow = $this->source(self::WORKFLOW);
    $plan = $this->source(self::PLAN);

    $pre = 'plan-1336-reboot-pre-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}';
    $post = 'plan-1336-reboot-post-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}';

    self::assertStringContainsString($pre, $workflow);
    self::assertStringContainsString($post, $workflow);
    self::assertStringNotContainsString(
      "'reboot-pre-\${GITHUB_RUN_ID}-\${GITHUB_RUN_ATTEMPT}'",
      $workflow,
    );
    self::assertStringNotContainsString(
      "'reboot-post-\${GITHUB_RUN_ID}-\${GITHUB_RUN_ATTEMPT}'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$PLAN_ID" =~ ^plan-1336-[A-Za-z0-9._-]{8,80}$ ]]',
      $plan,
    );

    foreach ([
      'plan-1336-reboot-pre-36362656030-1',
      'plan-1336-reboot-post-36362656030-1',
    ] as $planId) {
      self::assertSame(
        1,
        preg_match('/^plan-1336-[A-Za-z0-9._-]{8,80}$/', $planId),
      );
    }
  }

  /**
   * REBOOT is exact, one-shot, reboot-only and fully post-validated.
   */
  public function testRebootWorkflowIsBoundedOneShotAndFailClosed(): void {
    $workflow = $this->source(self::WORKFLOW);

    foreach ([
      "mode='REBOOT'",
      'COMMENT_ID: ${{ github.event.comment.id }}',
      'AGENCY_PREPROD_PHP85_1336_REBOOT_CONSUMED',
      'main_sha=$main_sha comment_id=$COMMENT_ID',
      "needs.validate-authority.outputs.mode == 'REBOOT'",
      '.FAILED_CHECKS == ["reboot_not_required"]',
      '.REBOOT_REQUIRED == "YES"',
      '.REBOOT_REQUIRED_PACKAGES_SOURCE == "PRESENT"',
      '["linux-base","linux-image-6.8.0-142-generic"]',
      '.KERNEL_RUNNING == "6.8.0-139-generic"',
      '.PHP85_INSTALL_SIMULATION == "PASS"',
      '(.REQUESTED_PACKAGE_ALLOWLIST | length) == 11',
      '.NGINX_VHOST_PHP84_SOCKET_MATCH == "YES"',
      '["unix:/run/php/php8.4-fpm-agency-preprod.sock"]',
      '.PHP84_PACKAGES_PRESENT == "YES"',
      '.PHP84_SERVICE_ACTIVE == "YES"',
      '.FPM84_POOL_CONTRACT == "YES"',
      '.SENDMAIL_SAFETY_CONTRACT == "YES"',
      '.FAILED_SYSTEMD_UNITS == []',
      '.DRUPAL_HEALTH == "PASS"',
      '.PUBLIC_HEALTH == "PASS"',
      '"systemctl reboot"',
      "host_went_down='NO'",
      'for attempt in $(seq 1 30)',
      "host_reachable='NO'",
      'for attempt in $(seq 1 90)',
      'SECOND_REBOOT:"NONE"',
      '.STATUS == "PASS"',
      '.SAFETY_GATE == "PASS"',
      '.FAILED_CHECKS == []',
      '.KERNEL_RUNNING == "6.8.0-142-generic"',
      '.REBOOT_REQUIRED == "NO"',
      '(.CURRENT_PHP_CLI | startswith("8.4."))',
      '(.CURRENT_PHP_FPM | startswith("PHP 8.4."))',
      'preprod-php85-1336-reboot-${{ github.run_id }}-${{ github.run_attempt }}',
      'reconciliation.json',
      'REBOOT_COMMAND_ATTEMPTED:"NO"',
      '.REBOOT_COMMAND_ATTEMPTED = "YES"',
      '.HOST_WENT_DOWN = "YES"',
      '.HOST_REACHABLE_AFTER_REBOOT = "YES"',
      'POST_REBOOT_VALIDATION:"PASS"',
      'PREPROD_MUTATION:"REBOOT_ONLY"',
      'PACKAGE_MUTATION:"NONE"',
      'NGINX_MUTATION:"NONE"',
      'PHP_MUTATION:"NONE"',
      'DRUPAL_MUTATION:"NONE"',
      'DB_MUTATION:"NONE"',
      'PROD_ACCESS:"NONE"',
      'NEW_PHP85_PLAN:"NONE"',
      'PHP85_APPLY:"NONE"',
      '#1336 PREPROD reboot-only evidence preserved.',
      'printf -v body',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertSame(1, substr_count($workflow, '"systemctl reboot"'));
    self::assertStringContainsString(
      'if: ${{ always() }}',
      $workflow,
    );
    self::assertStringNotContainsString(
      "steps.reboot_result.outputs.receipt_valid == 'true'",
      $workflow,
    );
    self::assertStringContainsString(
      'artifacts/preprod-php85-1336/reboot/pre-plan.json',
      $workflow,
    );
    self::assertStringContainsString(
      'artifacts/preprod-php85-1336/reboot/reconciliation.json',
      $workflow,
    );
    self::assertStringContainsString(
      'artifacts/preprod-php85-1336/reboot/post-plan.json',
      $workflow,
    );
    self::assertStringContainsString(
      'artifacts/preprod-php85-1336/reboot/result.json',
      $workflow,
    );
    self::assertStringNotContainsString(
      'artifacts/preprod-php85-1336/reboot/reboot.stdout',
      $this->extractRebootArtifactUploadBlock($workflow),
    );
    self::assertStringNotContainsString(
      'artifacts/preprod-php85-1336/reboot/reboot.stderr',
      $this->extractRebootArtifactUploadBlock($workflow),
    );

    self::assertStringNotContainsString(
      'body="$(cat <<EOF_BODY' . "\n"
      . '          #1336 PREPROD reboot-only evidence preserved.',
      $workflow,
    );

    self::assertSame(
      1,
      preg_match(
        "/\n  reboot:\n(.*)\z/s",
        $workflow,
        $match,
      ),
    );
    $rebootJob = $match[1];
    foreach ([
      'apt-get update',
      'apt-get install',
      'apt-get upgrade',
      'apt upgrade',
      'full-upgrade',
      'dist-upgrade',
      'apt-get remove',
      'apt-get purge',
      'systemctl reload nginx',
      'systemctl restart php',
      'drush cim',
      'drush updb',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $rebootJob);
    }
  }

  /**
   * PLAN exposes canonical vhost metadata and gates it strictly.
   */
  public function testPlanGatesCanonicalVhostMetadata(): void {
    $plan = $this->source(self::PLAN);
    foreach ([
      'NGINX_VHOST_OWNER',
      'NGINX_VHOST_GROUP',
      'NGINX_VHOST_MODE',
      "os.environ['NGINX_VHOST_OWNER'] == 'root'",
      "os.environ['NGINX_VHOST_GROUP'] == 'root'",
      "os.environ['NGINX_VHOST_MODE'] == '0644'",
      "'nginx_vhost_metadata_exact'",
    ] as $required) {
      self::assertStringContainsString($required, $plan);
    }
    self::assertStringContainsString(
      '[[ -f "$NGINX_VHOST" && ! -L "$NGINX_VHOST" && -r "$NGINX_VHOST" ]]',
      $plan,
    );
  }

  /**
   * Rollback backup metadata remains canonical and current readiness only.
   */
  public function testApplyPreservesRollbackMetadataAndDropsLegacyReadiness(): void {
    $apply = $this->source(self::APPLY);
    self::assertStringContainsString('install -d -m 700 "$backup_root"', $apply);
    self::assertStringContainsString(
      'cp --preserve=all "$NGINX_VHOST" "$backup_root/nginx-agency-preprod.before"',
      $apply,
    );
    self::assertStringContainsString(
      '[[ "$(stat -c \'%a\' "$backup_root/nginx-agency-preprod.before")" == \'644\' ]]',
      $apply,
    );
    self::assertStringContainsString(
      '[[ "$(stat -c \'%a\' "$NGINX_VHOST")" == \'644\' ]]',
      $apply,
    );
    self::assertStringNotContainsString('chmod -R go-rwx "$backup_root"', $apply);
    self::assertStringNotContainsString('127.0.0.1:18087', $apply);
    self::assertStringNotContainsString('INTERNAL_READINESS', $apply);
    foreach ([
      'side_effects=PASS',
      '/health/live',
      '/health/ready',
      'php-fpm8.5',
      'PHP85_OPCACHE_AVAILABLE:"PASS"',
      'WEB_RUNTIME_PHP85:"PASS"',
      'systemctl is-active --quiet php8.4-fpm',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }
  }

  /**
   * Metadata repair is exact, one-shot and mutation-bounded.
   */
  public function testVhostMetadataRepairIsExactOneShotAndBounded(): void {
    $workflow = $this->source(self::WORKFLOW);
    foreach ([
      "mode='REPAIR'",
      "'/agency-preprod-php85-1336 repair-vhost-metadata'",
      'AGENCY_PREPROD_PHP85_1336_VHOST_METADATA_REPAIR_CONSUMED',
      "needs.validate-authority.outputs.mode == 'REPAIR'",
      '50456ed2925ad4eb0543d127128e781eb814258cb036a5e38804f89ee0a26ac3',
      'unix:/run/php/php8.4-fpm-agency-preprod.sock',
      'chmod 0644 "$vhost"',
      'nginx -t >/dev/null',
      '/health/live',
      '/health/ready',
      'preprod-php85-1336-vhost-metadata-repair-',
      'CONTENT_MUTATION:"NONE"',
      'NGINX_RELOAD:"NONE"',
      'PACKAGE_MUTATION:"NONE"',
      'PHP_MUTATION:"NONE"',
      'DRUPAL_MUTATION:"NONE"',
      'DB_MUTATION:"NONE"',
      'PROD_ACCESS:"NONE"',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
    self::assertSame(1, substr_count($workflow, 'chmod 0644 "$vhost"'));
    self::assertSame(
      1,
      preg_match('/\n  repair:\n(.*)\z/s', $workflow, $match),
    );
    $repair = $match[1];
    foreach ([
      'chown ',
      'systemctl reload nginx',
      'systemctl restart',
      'apt-get install',
      'apt-get remove',
      'drush cim',
      'drush updb',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $repair);
    }
  }

  /**
   * Both remote scripts have valid Bash syntax.
   */
  public function testRemoteScriptsHaveValidBashSyntax(): void {
    foreach ([self::PLAN, self::APPLY] as $relativePath) {
      $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
      $output = [];
      $status = 1;
      exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
      self::assertSame(0, $status, implode("\n", $output));
    }
  }

  /**
   * Executes the embedded PLAN evaluator on a valid PHP 8.5 closure.
   */
  private function evaluatePlan(int $diskAvailableKb): array {
    $result = $this->executePlan($diskAvailableKb);
    self::assertSame(0, $result['status'], $result['output']);
    $receipt = json_decode($result['output'], TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($receipt);
    return $receipt;
  }

  /**
   * Executes the embedded PLAN evaluator on deterministic synthetic inputs.
   *
   * @param int $diskAvailableKb
   *   Synthetic root filesystem free space in KiB.
   * @param string[] $extraAdditions
   *   Additional simulated APT package additions.
   * @param bool $candidateGap
   *   Whether one requested candidate is missing.
   * @param string $simulationState
   *   Synthetic install simulation state.
   * @param string $rebootPackagesSource
   *   Synthetic reboot package evidence source.
   * @param string[] $rebootPackages
   *   Synthetic reboot-required package names.
   * @param string[] $nginxFastcgiValues
   *   Synthetic normalized Nginx fastcgi_pass values.
   *
   * @return array{status:int,output:string}
   *   Process status and combined output.
   */
  private function executePlan(
    int $diskAvailableKb,
    array $extraAdditions = ['php8.5-readline'],
    bool $candidateGap = FALSE,
    string $simulationState = 'PASS',
    string $rebootPackagesSource = 'ABSENT',
    array $rebootPackages = [],
    array $nginxFastcgiValues = ['unix:/run/php/php8.4-fpm-agency-preprod.sock'],
  ): array {
    $source = $this->source(self::PLAN);
    self::assertSame(
      1,
      preg_match("/python3 - <<'PY'\\n(.*?)\\nPY\\n/s", $source, $matches),
    );

    $directory = sys_get_temp_dir() . '/agency-1336-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($directory, 0700, TRUE));
    try {
      $packages = [
        'php8.5-bcmath', 'php8.5-cli', 'php8.5-common', 'php8.5-curl',
        'php8.5-fpm', 'php8.5-gd', 'php8.5-intl', 'php8.5-mbstring',
        'php8.5-mysql', 'php8.5-xml', 'php8.5-zip',
      ];
      $candidateLines = [];
      $simulationLines = [];
      foreach ($packages as $index => $package) {
        $candidate = $candidateGap && $index === 0 ? 'NONE' : '8.5.11-1';
        $candidateLines[] = $package . "\t" . $candidate;
        if (!$candidateGap && $simulationState === 'PASS') {
          $simulationLines[] = 'Inst ' . $package . ' (8.5.11-1 repo [amd64])';
        }
      }
      if (!$candidateGap && $simulationState === 'PASS') {
        foreach ($extraAdditions as $package) {
          $simulationLines[] = 'Inst ' . $package . ' (8.5.11-1 repo [amd64])';
        }
      }
      file_put_contents(
        $directory . '/candidates.tsv',
        implode("\n", $candidateLines) . "\n",
      );
      file_put_contents(
        $directory . '/install-sim.raw',
        implode("\n", $simulationLines) . "\n",
      );
      file_put_contents($directory . '/failed.raw', '');
      file_put_contents(
        $directory . '/reboot-required-packages.raw',
        $rebootPackages === [] ? '' : implode("\n", $rebootPackages) . "\n",
      );
      file_put_contents(
        $directory . '/nginx-fastcgi-pass.raw',
        $nginxFastcgiValues === [] ? '' : implode("\n", $nginxFastcgiValues) . "\n",
      );
      $script = $directory . '/plan.py';
      file_put_contents($script, $matches[1] . "\n");

      $environment = [
        'WORK_ROOT' => $directory,
        'MAIN_SHA' => str_repeat('a', 40),
        'PLAN_ID' => 'plan-1336-deterministic-fixture-r2',
        'OS_PRETTY_NAME' => 'Ubuntu 24.04.5 LTS',
        'VERSION_ID' => '24.04',
        'KERNEL_RUNNING' => '6.8.0-139-generic',
        'REBOOT_REQUIRED' => 'NO',
        'REBOOT_REQUIRED_PACKAGES_SOURCE' => $rebootPackagesSource,
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
        'NGINX_VHOST_OWNER' => 'root',
        'NGINX_VHOST_GROUP' => 'root',
        'NGINX_VHOST_MODE' => '0644',
        'FPM84_POOL_CONTRACT' => 'YES',
        'SENDMAIL_SAFETY_CONTRACT' => 'YES',
        'NGINX_VHOST_SHA256' => str_repeat('b', 64),
        'FPM84_POOL_SHA256' => str_repeat('c', 64),
        'CANDIDATE_GAP' => $candidateGap ? 'YES' : 'NO',
        'PHP85_INSTALL_SIMULATION' => $simulationState,
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
   * Extracts the bounded reboot artifact upload step.
   */
  private function extractRebootArtifactUploadBlock(string $workflow): string {
    self::assertSame(
      1,
      preg_match(
        '/- name: Upload immutable bounded reboot evidence\n(.*?)(?=\n      - name: Publish bounded reboot summary)/s',
        $workflow,
        $match,
      ),
    );
    return $match[1];
  }

  /**
   * Parses one workflow file.
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

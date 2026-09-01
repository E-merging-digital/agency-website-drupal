<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded #943 recovery capability for the failed #940 request.
 *
 * @group agency_project_tests
 * @group preproduction_refresh
 */
final class PreproductionRefresh940RecoveryTest extends TestCase {

  /**
   * The route and remote capability are hard-bound to the incident residue.
   */
  public function testRecoveryIsBoundToExact943Incident(): void {
    $workflow = $this->workflow();
    $capability = $this->capability();

    foreach ([
      'github.event.issue.number == 943',
      "github.event.comment.user.login == 'E-merging-digital'",
      "github.actor == 'E-merging-digital'",
      '/agency-preprod-refresh-940-recovery plan',
      '/agency-preprod-refresh-940-recovery cleanup',
      'test "$ISSUE_NUMBER" = 943',
      'issues/943',
      'persist-credentials: false',
      'JIT validate exact #943 recovery authority before root SSH secret',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      "REQUEST_ID: Final = 'apply-940-first-real-s2s-r1'",
      "EXPECTED_FAILED_MAIN: Final = "
      . "'0b61d56264ad0163cd3bdbd5ea6e07253a155fbb'",
      "ROOT_STAGE: Final = Path('/run/agency-preprod-refresh/412fc11485c5')",
      "REQUEST_DIR: Final = JOB_ROOT / REQUEST_ID",
    ] as $required) {
      self::assertStringContainsString($required, $capability);
    }

    self::assertStringNotContainsString(
      'ROOT_STAGE = Path(sys.argv',
      $capability,
    );
    self::assertStringNotContainsString('REQUEST_ID = sys.argv', $capability);
  }

  /**
   * Root execution receives only repository-owned fixed PLAN/CLEANUP programs.
   */
  public function testNoGenericRootCommandOrCallerControlledPath(): void {
    $workflow = $this->workflow();

    foreach ([
      "'python3 -I - PLAN' < \"\$capability\"",
      "'python3 -I - CLEANUP' < \"\$capability\"",
      '"root@$PREPROD_SERVER_HOST"',
      'timeout 30s ssh',
      'PREPROD_ROOT_KEY: ${{ secrets.PREPROD_PROVISIONING_SSH_PRIVATE_KEY }}',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      '"root@$PREPROD_SERVER_HOST" "$COMMENT_BODY"',
      'bash -c "$COMMENT_BODY"',
      'eval ',
      'workflow_dispatch:',
      'secrets: inherit',
      'scp ',
      'rsync ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * PLAN reaches observation/emission only; mutation belongs to CLEANUP only.
   */
  public function testPlanPathIsStrictlyReadOnly(): void {
    $capability = $this->capability();
    $plan = strpos($capability, "if sys.argv[1] == 'PLAN':");
    $cleanup = strpos($capability, 'def cleanup_fixed_stage()');
    $rmtree = strpos($capability, 'shutil.rmtree(ROOT_STAGE)');

    self::assertIsInt($plan);
    self::assertIsInt($cleanup);
    self::assertIsInt($rmtree);
    self::assertLessThan($rmtree, $cleanup);
    self::assertLessThan($plan, $rmtree);
    self::assertStringContainsString(
      "if sys.argv[1] == 'PLAN':\n"
      . "        current = observe()\n"
      . "        emit(current, 'PLAN')",
      $capability,
    );

    foreach ([
      'os.kill(',
      'signal.',
      'subprocess.Popen(',
      'drush ',
      'maint:set',
      'state:set',
      'chmod(',
      'chown(',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $capability);
    }
  }

  /**
   * Secret and log contents are never read or emitted.
   */
  public function testSecretAndBootstrapContentsRemainOpaque(): void {
    $capability = $this->capability();

    foreach ([
      "PROD_READ_KEY: Final = ROOT_STAGE / 'prod-read.key'",
      "BOOTSTRAP_LOG: Final = ROOT_STAGE / 'bootstrap.log'",
      'metadata = os.lstat(PROD_READ_KEY)',
      'metadata = os.lstat(BOOTSTRAP_LOG)',
      "return 'PRESENT', str(metadata.st_size)",
    ] as $required) {
      self::assertStringContainsString($required, $capability);
    }

    foreach ([
      'PROD_READ_KEY.read_',
      'BOOTSTRAP_LOG.read_',
      'open(PROD_READ_KEY',
      'open(BOOTSTRAP_LOG',
      'sha256_file(PROD_READ_KEY)',
      'private_key_sha',
      'bootstrap_content',
      'cat bootstrap.log',
      'tail bootstrap.log',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $capability);
    }
  }

  /**
   * The fixed PLAN exposes the exact root-cause proof dimensions.
   */
  public function testPlanSchemaCoversRootCauseAndSafetyEvidence(): void {
    $capability = $this->capability();

    foreach ([
      'worker_process',
      'request_dir',
      'refresh_jobs_root',
      'refresh_jobs_root_owner',
      'refresh_jobs_root_mode',
      'root_stage_owner_mode',
      'root_stage_inventory',
      'prod_read_identity_file',
      'bootstrap_log_bytes',
      'raw_staging_scope',
      'refresh_lock',
      'deploy_lock',
      'cleanup_eligible',
      'REFRESH_JOBS_ROOT_MISSING',
      'REFRESH_JOBS_ROOT_UNSAFE',
      'EARLY_BOOTSTRAP_OTHER',
      'UNPROVEN',
    ] as $required) {
      self::assertStringContainsString($required, $capability);
    }
  }

  /**
   * Raw staging proof reuses only the installed read-only absence action.
   */
  public function testRawStagingProofIsFixedReadOnlyAndLocal(): void {
    $capability = $this->capability();

    self::assertStringContainsString(
      "[str(STAGING_HELPER), 'VERIFY_ABSENCE', REQUEST_ID, '0']",
      $capability,
    );
    self::assertStringContainsString(
      "EXPECTED_STAGING_HELPER_SHA256: Final = "
      . "'a3eaf545abc448004f7c1136bf4e19a5728b1e16784c700ffca24e91e2e82b71'",
      $capability,
    );
    self::assertStringNotContainsString("'IMPORT'", $capability);
    self::assertStringNotContainsString("'IMPORT_SANITIZE_PROVE'", $capability);
    self::assertStringNotContainsString('mariadb-dump', $capability);
    self::assertStringNotContainsString('/usr/bin/ssh', $capability);
  }

  /**
   * Every ambiguous or active condition makes cleanup ineligible.
   */
  public function testCleanupEligibilityFailsClosed(): void {
    $capability = $this->capability();

    foreach ([
      "('worker_process', 'ALIVE')",
      "('request_dir', 'PRESENT')",
      "('refresh_lock', 'HELD')",
      "('refresh_lock', 'UNOBSERVABLE')",
      "('deploy_lock', 'HELD')",
      "('root_stage_inventory', 'UNEXPECTED')",
      "('raw_staging_scope', 'PRESENT')",
      "('raw_staging_scope', 'UNOBSERVABLE')",
    ] as $required) {
      self::assertStringContainsString($required, $capability);
    }

    foreach ([
      "observation['worker_process'] == 'DEAD'",
      "observation['request_dir'] == 'ABSENT'",
      "observation['refresh_lock'] == 'FREE'",
      "observation['deploy_lock'] == 'FREE'",
      "observation['root_stage'] == 'PRESENT'",
      "observation['root_stage_owner_mode'] == 'EXPECTED'",
      "observation['root_stage_inventory'] == 'EXACT_EXPECTED_SET'",
      "observation['raw_staging_scope'] == 'ABSENT'",
    ] as $required) {
      self::assertStringContainsString($required, $capability);
    }
  }

  /**
   * Cleanup deletes exactly one fixed directory and proves it absent afterward.
   */
  public function testCleanupTargetAndPostconditionAreExact(): void {
    $capability = $this->capability();

    self::assertSame(1, substr_count($capability, 'shutil.rmtree(ROOT_STAGE)'));
    self::assertStringContainsString(
      "if str(ROOT_STAGE) != '/run/agency-preprod-refresh/412fc11485c5':",
      $capability,
    );
    self::assertStringContainsString('second = observe()', $capability);
    self::assertStringContainsString(
      "if after != 'ABSENT':",
      $capability,
    );
    self::assertStringContainsString(
      "emit(second, 'CLEANUP', 'COMPLETED', 'ABSENT')",
      $capability,
    );

    foreach ([
      'os.remove(',
      'os.unlink(',
      'Path.unlink(',
      'shutil.move(',
      'DROP DATABASE',
      'DROP USER',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $capability);
    }
  }

  /**
   * Workflow owns no PROD credential or PREPROD runtime DB mutation route.
   */
  public function testWorkflowHasNoProdAccessOrDbMutationAuthority(): void {
    $workflow = $this->workflow();

    foreach ([
      'secrets.SSH_PRIVATE_KEY',
      'secrets.SERVER_HOST',
      'secrets.SERVER_USER',
      'PREPROD_SSH_PRIVATE_KEY',
      'agency-preprod@',
      'drush ',
      'sql:',
      'mariadb ',
      'mysql ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Returns the dedicated reusable workflow source.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/preprod-refresh-940-recovery.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

  /**
   * Returns the fixed repository-owned root capability.
   */
  private function capability(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/preproduction-refresh/governed-successor/'
      . 'recover-940-residual-root-stage.py';

    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

}

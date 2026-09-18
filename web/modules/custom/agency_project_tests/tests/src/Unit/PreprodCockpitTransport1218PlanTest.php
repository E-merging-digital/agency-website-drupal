<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the read-only PREPROD cockpit transport PLAN for #1218.
 *
 * @group agency_project_tests
 */
final class PreprodCockpitTransport1218PlanTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  private const WORKFLOW = '.github/workflows/preprod-cockpit-transport-1218.yml';

  private const PLAN = 'scripts/preproduction-cockpit-transport-1218/remote-plan.sh';

  /**
   * Proves the workflow remains PLAN-only, pinned and owner-bound.
   */
  public function testWorkflowIsPlanOnlyPinnedAndOwnerBound(): void {
    $dispatcher = $this->source(self::DISPATCHER);
    $workflow = $this->source(self::WORKFLOW);

    foreach ([
      "github.event_name == 'issue_comment'",
      "github.event.action == 'created'",
      'github.event.issue.pull_request == null',
      'github.event.issue.number == 1218',
      "github.event.comment.author_association == 'OWNER'",
      "github.event.comment.user.login == 'E-merging-digital'",
      'github.event.comment.performed_via_github_app == null',
      "github.event.comment.body == '/agency-cockpit-transport-1218 plan'",
      'uses: ./.github/workflows/preprod-cockpit-transport-1218.yml',
      'PREPROD_SSH_PRIVATE_KEY: ${{ secrets.PREPROD_SSH_PRIVATE_KEY }}',
      'PREPROD_SERVER_HOST: ${{ secrets.PREPROD_SERVER_HOST }}',
    ] as $required) {
      self::assertStringContainsString($required, $dispatcher);
    }

    foreach ([
      'workflow_call:',
      "test \"\$EVENT_NAME\" = 'issue_comment'",
      "test \"\$EVENT_ACTION\" = 'created'",
      "test \"\$ISSUE_NUMBER\" = '1218'",
      "test \"\$COMMENT_LOGIN\" = 'E-merging-digital'",
      "test \"\$COMMENT_ASSOCIATION\" = 'OWNER'",
      "test \"\$COMMENT_VIA_APP\" = 'false'",
      "test \"\$COMMENT_BODY\" = '/agency-cockpit-transport-1218 plan'",
      'test "$WORKFLOW_SHA" = "$main_sha"',
      'secrets.PREPROD_SSH_PRIVATE_KEY',
      'secrets.PREPROD_SERVER_HOST',
      'scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION',
      'StrictHostKeyChecking=yes',
      'agency-preprod@$PREPROD_SERVER_HOST',
      'PREPROD_MUTATION == "NONE"',
      'PROD_ACCESS == "NONE"',
      'SECRET_CONTENT_EXPOSED == false',
      'actions/upload-artifact@v4',
      '.STATE.legacy_failed_staging.path == "/root/agency-1218-35221860275-1"',
      'LEGACY_FAILED_STAGE_PRESENCE',
      'LEGACY_FAILED_STAGE_ACCESS',
      'LEGACY_FAILED_STAGE_TYPE',
      'LEGACY_FAILED_STAGE_OWNER',
      'LEGACY_FAILED_STAGE_MODE',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'issue_comment:',
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'root@$PREPROD_SERVER_HOST',
      'sudo ',
      ' scp ',
      ' rsync ',
      'workflow_dispatch:',
      '/agency-cockpit-transport-1218 apply',
      'chatgpt-codex-connector',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Proves the remote PLAN observes PREPROD without mutation authority.
   */
  public function testRemotePlanObservesWithoutMutatingPreprod(): void {
    $plan = $this->source(self::PLAN);

    foreach ([
      "HOSTNAME='preprod.emergingdigital.be'",
      "PATH_ONLY='/api/agency-operations/v1/environment-data-state'",
      'ENDPOINT="https://$HOSTNAME$PATH_ONLY"',
      'SETTINGS_FILE="$PROJECT_ROOT/shared/settings/settings.php"',
      "NGINX_SITE='/etc/nginx/sites-available/agency-preprod'",
      "TOKEN_FILE='/etc/agency-preprod/cockpit-state-token'",
      'settings_reader=',
      'machine_location_count',
      'auth_basic_off',
      'authorization_forwarded',
      'invalid-cockpit-token-1218',
      'PLAN_DIGEST',
      'PREPROD_MUTATION:"NONE"',
      'PROD_ACCESS:"NONE"',
      'SECRET_CONTENT_EXPOSED:false',
      "LEGACY_FAILED_RUN_DIR='/root/agency-1218-35221860275-1'",
      'legacy_failed_staging',
      'presence:$legacy_stage_presence',
      'access:$legacy_stage_access',
      'type:$legacy_stage_type',
      'owner:$legacy_stage_owner',
      'mode:$legacy_stage_mode',
    ] as $required) {
      self::assertStringContainsString($required, $plan);
    }

    foreach ([
      'sudo ',
      'systemctl ',
      'service ',
      'nginx -s',
      'sed -i',
      'tee ',
      'scp ',
      'rsync ',
      'openssl rand',
      'cat "$TOKEN_FILE"',
      'sha256sum "$TOKEN_FILE"',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $plan);
    }
  }

  /**
   * Reads a repository source file used by the contract tests.
   */
  private function source(string $relativePath): string {
    $root = dirname(DRUPAL_ROOT);
    $source = file_get_contents($root . '/' . $relativePath);
    self::assertIsString($source);
    return $source;
  }

}

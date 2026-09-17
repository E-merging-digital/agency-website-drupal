<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the governed PREPROD cockpit transport APPLY for #1218.
 *
 * @group agency_project_tests
 */
final class PreprodCockpitTransport1218ApplyTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  private const WORKFLOW = '.github/workflows/preprod-cockpit-transport-1218-apply.yml';

  private const APPLY = 'scripts/preproduction-cockpit-transport-1218/remote-apply-root.sh';

  /**
   * Proves APPLY remains owner-gated, plan-bound and root-key bounded.
   */
  public function testWorkflowRequiresExactHumanApprovedPlan(): void {
    $dispatcher = $this->source(self::DISPATCHER);
    $workflow = $this->source(self::WORKFLOW);

    foreach ([
      'github.event.issue.number == 1218',
      "github.event.comment.author_association == 'OWNER'",
      "github.event.comment.user.login == 'E-merging-digital'",
      'github.event.comment.performed_via_github_app == null',
      "startsWith(github.event.comment.body, '/agency-cockpit-transport-1218 apply ')",
      'uses: ./.github/workflows/preprod-cockpit-transport-1218-apply.yml',
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY: ${{ secrets.PREPROD_PROVISIONING_SSH_PRIVATE_KEY }}',
      'PREPROD_SERVER_HOST: ${{ secrets.PREPROD_SERVER_HOST }}',
    ] as $required) {
      self::assertStringContainsString($required, $dispatcher);
    }

    foreach ([
      'workflow_call:',
      'performed_via_github_app',
      'plan_run=',
      'plan_digest=',
      'main_sha=',
      'actions/runs/$PLAN_RUN',
      'preprod-cockpit-transport-1218-plan-${PLAN_RUN}-',
      'secrets.PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'StrictHostKeyChecking=yes',
      'root@$PREPROD_SERVER_HOST',
      'remote-apply-root.sh',
      'STALE_PLAN == "PASS"',
      'TOKEN_PERSISTED == false',
      'PROD_ACCESS == "NONE"',
      'SECRET_CONTENT_EXPOSED == false',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'workflow_dispatch:',
      'PREPROD_SSH_PRIVATE_KEY',
      'secrets.SSH_PRIVATE_KEY',
      'secrets.SERVER_HOST',
      'secrets.SERVER_USER',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Proves root APPLY stays bounded and rollback-capable.
   */
  public function testRemoteApplyConvergesOnlyApprovedTransportSurfaces(): void {
    $apply = $this->source(self::APPLY);

    foreach ([
      "SETTINGS_FILE=\"\$PROJECT_ROOT/shared/settings/settings.php\"",
      "NGINX_SITE='/etc/nginx/sites-available/agency-preprod'",
      "TOKEN_FILE='/etc/agency-preprod/cockpit-state-token'",
      'STALE_PLAN',
      'nginx -t',
      'systemctl reload nginx',
      'openssl rand -hex 32',
      'root:www-data:640',
      "'401|JSON'",
      "'200|JSON'",
      'TOKEN_PERSISTED: false',
      'DB_MUTATION: "NONE"',
      'SECRET_CONTENT_EXPOSED: false',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    foreach ([
      'drush ',
      'mysql ',
      'mysqldump ',
      'composer ',
      'git ',
      'echo "$bearer"',
      'printf "$bearer"',
      'cat "$TOKEN_FILE"',
      'sha256sum "$TOKEN_FILE"',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $apply);
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

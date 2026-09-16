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

  private const WORKFLOW = '.github/workflows/preprod-cockpit-transport-1218.yml';

  private const PLAN = 'scripts/preproduction-cockpit-transport-1218/remote-plan.sh';

  public function testWorkflowIsPlanOnlyPinnedAndOwnerBound(): void {
    $workflow = $this->source(self::WORKFLOW);

    foreach ([
      'issue_comment:',
      "github.event.issue.number == 1218",
      "test \"\$COMMENT_LOGIN\" = 'E-merging-digital'",
      "test \"\$COMMENT_ASSOCIATION\" = 'OWNER'",
      "test \"\$COMMENT_BODY\" = '/agency-cockpit-transport-1218 plan'",
      "chatgpt-codex-connector",
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
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'root@$PREPROD_SERVER_HOST',
      'sudo ',
      ' scp ',
      ' rsync ',
      'workflow_dispatch:',
      '/agency-cockpit-transport-1218 apply',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  public function testRemotePlanObservesWithoutMutatingPreprod(): void {
    $plan = $this->source(self::PLAN);

    foreach ([
      "ENDPOINT='https://preprod.emergingdigital.be/api/agency-operations/v1/environment-data-state'",
      "SETTINGS_FILE=\"$PROJECT_ROOT/shared/settings/settings.php\"",
      "NGINX_SITE='/etc/nginx/sites-available/agency-preprod'",
      "TOKEN_FILE='/etc/agency-preprod/cockpit-state-token'",
      'settings_reader=',
      'machine_location_count',
      'auth_basic_off',
      'authorization_forwarded',
      'invalid-cockpit-token-1218',
      'PLAN_DIGEST',
      'PREPROD_MUTATION: "NONE"',
      'PROD_ACCESS: "NONE"',
      'SECRET_CONTENT_EXPOSED: false',
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

  private function source(string $relativePath): string {
    $root = dirname(DRUPAL_ROOT);
    $source = file_get_contents($root . '/' . $relativePath);
    self::assertIsString($source);
    return $source;
  }

}

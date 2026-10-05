<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the read-only PREPROD cockpit recovery diagnostic for #1223.
 *
 * @group agency_project_tests
 */
final class PreprodCockpitTransport1223RecoveryDiagnosticTest extends TestCase {

  private const PLAN = 'scripts/preproduction-cockpit-transport-1218/remote-plan.sh';

  /**
   * Proves the recovery PLAN observes routing layers without mutation.
   */
  public function testRecoveryPlanSeparatesPublicAndLocalNginxPaths(): void {
    $plan = $this->source(self::PLAN);

    foreach ([
      "HOSTNAME='preprod.emergingdigital.be'",
      'WWW_AUTH',
      'LOCAL_HTTP',
      'LOCAL_HTTPS',
      '--resolve "$HOSTNAME:443:127.0.0.1"',
      'NGINX_DIAGNOSTIC_B64',
      'nginx_effective_route',
      'effective_preprod_tls_server_block',
      'effective_preprod_http_server_block',
      'legacy_first_root_insertion_server_block',
      'nginx_topology',
      'PREPROD_MUTATION:"NONE"',
      'PROD_ACCESS:"NONE"',
      'SECRET_CONTENT_EXPOSED:false',
      "LEGACY_FAILED_RUN_DIR='/root/agency-1218-35221860275-1'",
      "stat -c '%F|%U|%G|%a'",
      "LEGACY_STAGE_PRESENCE='UNKNOWN'",
      "LEGACY_STAGE_ACCESS='DENIED'",
      'legacy_failed_staging',
      'presence:$legacy_stage_presence',
      'access:$legacy_stage_access',
      'owner:$legacy_stage_owner',
      'mode:$legacy_stage_mode',
    ] as $required) {
      self::assertStringContainsString($required, $plan);
    }

    foreach ([
      'sudo ',
      'systemctl ',
      'nginx -s ',
      'nginx -t',
      'openssl rand',
      'mv -f ',
      'cp -a ',
      'rm -f -- "$TOKEN_FILE"',
      'drush ',
      'mysql ',
      'ls /root',
      'find /root',
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
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

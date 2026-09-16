<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the PREPROD runtime-only cockpit bearer boundary.
 *
 * @group agency_project_tests
 */
final class PreproductionCockpitBearerRuntimeTest extends TestCase {

  /**
   * Drupal reads the optional bearer from server-owned state, not FPM env.
   */
  public function testSettingsUseServerOwnedOptionalBearerFile(): void {
    $root = dirname(DRUPAL_ROOT);
    $settings = (string) file_get_contents(
      $root . '/scripts/preproduction/settings.php.template',
    );

    foreach ([
      '/etc/agency-preprod/cockpit-state-token',
      '!is_link($agency_cockpit_token_file)',
      'is_file($agency_cockpit_token_file)',
      'is_readable($agency_cockpit_token_file)',
      "agency_operations_cockpit_state_token'] = \$agency_cockpit_token",
    ] as $expected) {
      self::assertStringContainsString($expected, $settings);
    }

    self::assertStringNotContainsString('getenv(', $settings);
    self::assertStringNotContainsString('AGENCY_COCKPIT_STATE_TOKEN', $settings);
  }

  /**
   * Provisioning is explicit, root-owned and never generates a credential.
   */
  public function testProvisioningHelperIsBoundedAndNonGenerating(): void {
    $root = dirname(DRUPAL_ROOT);
    $helper = (string) file_get_contents(
      $root . '/scripts/preproduction/provision-cockpit-state-token.sh',
    );

    foreach ([
      '[[ "$(id -u)" -eq 0 ]]',
      'Pass the bearer through stdin/prompt, never as an argument.',
      'install -d -m 750 -o root -g "$TOKEN_GROUP" "$TOKEN_DIR"',
      'read -r -s',
      'read -r token',
      '[[ ${#token} -ge 32 ]]',
      'chown root:"$TOKEN_GROUP" "$TOKEN_TMP"',
      'chmod 640 "$TOKEN_TMP"',
      'mv -f "$TOKEN_TMP" "$TOKEN_FILE"',
      'root:$TOKEN_GROUP:640',
    ] as $expected) {
      self::assertStringContainsString($expected, $helper);
    }

    foreach (['openssl rand', 'uuidgen', '/dev/urandom'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $helper);
    }
  }

  /**
   * Ordinary candidate deployment remains independent of bearer presence.
   */
  public function testCandidateDeploymentDoesNotRequireCockpitBearer(): void {
    $root = dirname(DRUPAL_ROOT);
    $deploy = (string) file_get_contents(
      $root . '/scripts/preproduction/deploy-candidate.sh',
    );

    self::assertStringNotContainsString('AGENCY_COCKPIT_STATE_TOKEN', $deploy);
    self::assertStringNotContainsString('cockpit-state-token', $deploy);
  }

}

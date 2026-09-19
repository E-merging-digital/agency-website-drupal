<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects #1260 hardening of the canonical #1261 broker.
 *
 * @group agency_project_tests
 * @group preprod_cockpit_consumer_proof_1260
 */
final class PreprodCockpitConsumerProof1260Test extends TestCase {

  private const WORKFLOW = '.github/workflows/infrastructure-cockpit-consumer-proof.yml';

  private const HELPER = 'scripts/preproduction-cockpit-consumer-proof-1260/remote-lease-root.sh';

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  /**
   * Proves the canonical broker route remains unique.
   */
  public function testCanonicalBrokerRouteRemainsUnique(): void {
    $dispatcher = $this->source(self::DISPATCHER);

    self::assertStringContainsString('INFRA_COCKPIT_CONSUMER_PROOF', $dispatcher);
    self::assertStringContainsString('/agency-infra-cockpit-consumer ', $dispatcher);
    self::assertStringNotContainsString('COCKPIT_CONSUMER_PROOF', str_replace('INFRA_COCKPIT_CONSUMER_PROOF', '', $dispatcher));
    self::assertStringNotContainsString('/agency-cockpit-consumer-proof ', $dispatcher);

    self::assertFileDoesNotExist(
      $this->path('.github/workflows/preprod-cockpit-consumer-proof-1260.yml'),
    );
    self::assertFileDoesNotExist(
      $this->path('scripts/preproduction-cockpit-consumer-proof-1260/run-responder.sh'),
    );
    self::assertFileDoesNotExist(
      $this->path('scripts/preproduction-cockpit-consumer-proof-1260/validate-request.py'),
    );
  }

  /**
   * Proves exact session ownership and bounded 600-second lease semantics.
   */
  public function testLeaseHelperOwnershipAndExpiryContract(): void {
    $helper = $this->source(self::HELPER);

    foreach ([
      '[[ "$session" =~ ^[0-9a-f]{16}$ ]]',
      'lease_marker="/run/${unit}.lease"',
      'root:root:600',
      '[[ "$lease_seconds" == \'600\' ]]',
      'Refusing to overwrite an existing token.',
      'Lease marker already exists.',
      'Token exists without this session owning a lease.',
      'Lease marker session mismatch.',
      'systemd-run --quiet',
      '--on-active="${lease_seconds}s"',
      '"$self_path" EXPIRE "$session"',
      'systemctl is-active --quiet "${unit}.timer"',
      "if [[ \"$mode\" == 'EXPIRE' ]]",
      "if [[ \"$mode\" == 'CLEANUP' ]]",
      'TOKEN_FILE_STATE=ABSENT',
      'LEASE_MARKER_STATE=ABSENT',
      'TRANSIENT_TIMER_STATE=INACTIVE',
      'TRANSIENT_SERVICE_STATE=INACTIVE',
      'OWNERSHIP_CLEANUP=PASS',
      'HARD_EXPIRY=PASS',
    ] as $required) {
      self::assertStringContainsString($required, $helper);
    }

    $marker = strpos($helper, 'install -m 600 -o root -g root /dev/null "$lease_marker"');
    $timer = strpos($helper, 'systemd-run --quiet');
    $armed = strpos($helper, "printf 'EXPIRY_ARMED=PASS");
    self::assertIsInt($marker);
    self::assertIsInt($timer);
    self::assertIsInt($armed);
    self::assertTrue($marker < $timer);
    self::assertTrue($timer < $armed);
  }

  /**
   * Proves expiry is armed before bearer generation and publication.
   */
  public function testWorkflowOrderingAndSecretSafety(): void {
    $workflow = $this->source(self::WORKFLOW);
    $start = strpos($workflow, 'Provision, encrypt, await private consumer proof, and cleanup');
    self::assertIsInt($start);
    $live = substr($workflow, $start);

    foreach ([
      'lease_seconds=600',
      "'$remote_helper' ARM '$SESSION' '$lease_seconds'",
      "grep -Fxq 'EXPIRY_ARMED=PASS'",
      'token="$(openssl rand -hex 32)"',
      'echo "::add-mask::$token"',
      'printf \'%s\\n\' "$token" | ssh',
      "grep -Fxq 'Public-Key: (3072 bit)'",
      'rsa_padding_mode:oaep',
      'rsa_oaep_md:sha256',
      'rsa_mgf1_md:sha256',
      "'$remote_helper' CLEANUP '$SESSION'",
      'lease_marker_cleanup:"PASS"',
      'transient_timer:"INACTIVE"',
      'transient_service:"INACTIVE"',
    ] as $required) {
      self::assertStringContainsString($required, $live);
    }

    $arm = strpos($live, "'$remote_helper' ARM '$SESSION' '$lease_seconds'");
    $generate = strpos($live, 'token="$(openssl rand -hex 32)"');
    $mask = strpos($live, 'echo "::add-mask::$token"');
    $provision = strpos($live, '"bash \'$remote_dir/provision-cockpit-state-token.sh\'"');
    $cipher = strpos($live, 'INFRA_CONSUMER_CIPHERTEXT=');

    foreach ([$arm, $generate, $mask, $provision, $cipher] as $position) {
      self::assertIsInt($position);
    }

    self::assertTrue($arm < $generate);
    self::assertTrue($generate < $mask);
    self::assertTrue($mask < $provision);
    self::assertTrue($provision < $cipher);

    self::assertStringNotContainsString('set -x', $live);
    self::assertStringNotContainsString(
      'rm -f -- /etc/agency-preprod/cockpit-state-token',
      $live,
    );
  }

  /**
   * Resolves one repository-relative path.
   */
  private function path(string $relative): string {
    return dirname(DRUPAL_ROOT) . '/' . $relative;
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relative): string {
    return (string) file_get_contents($this->path($relative));
  }

}

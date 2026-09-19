<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the encrypted one-shot Infrastructure cockpit consumer proof.
 *
 * @group agency_project_tests
 * @group infrastructure_cockpit_consumer_proof
 */
final class InfrastructureCockpitConsumerProofWorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/infrastructure-cockpit-consumer-proof.yml';

  private const INFRA_SHA = '65c7670d37e679efccc15aaa96595ea6aeb18c60';

  private const DEPLOYED_AGENCY_SHA = 'a096d7acc682a355720e9102dd84d40780bae2f8';

  /**
   * Proves the PR path is secret-free and the live route stays reusable-only.
   */
  public function testWorkflowHasSecretFreePullRequestCryptoSelftestAndReusableLiveRoute(): void {
    $workflow = $this->parsed();
    $source = $this->source();

    $on = $workflow['on'] ?? NULL;
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayHasKey('pull_request', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertArrayNotHasKey('workflow_dispatch', $on);

    $secrets = $on['workflow_call']['secrets'] ?? [];
    self::assertSame(
      ['PREPROD_PROVISIONING_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($secrets),
    );

    $jobs = $workflow['jobs'] ?? [];
    $selftest = $jobs['encrypted-envelope-selftest'] ?? NULL;
    self::assertIsArray($selftest);
    self::assertSame(['contents' => 'read'], $selftest['permissions'] ?? NULL);
    self::assertArrayNotHasKey('secrets', $selftest);
    self::assertStringContainsString(
      "github.event_name == 'pull_request'",
      (string) ($selftest['if'] ?? ''),
    );

    foreach ([
      'RSA-OAEP',
      'rsa_keygen_bits:3072',
      'rsa_padding_mode:oaep',
      'rsa_oaep_md:sha256',
      'rsa_mgf1_md:sha256',
      'AGENCY_ENCRYPTED_ENVELOPE_SELFTEST=PASS',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringNotContainsString('E-merging-digital/infrastructure/.github/actions/', $source);
    self::assertStringNotContainsString('secrets: inherit', $source);
    self::assertStringNotContainsString('INFRASTRUCTURE_REPO_TOKEN', $source);
    self::assertStringNotContainsString('PERSONAL_ACCESS_TOKEN', $source);
    self::assertStringNotContainsString('GH_PAT', $source);
  }

  /**
   * Proves live execution requires exact human authority and broker metadata.
   */
  public function testLiveRouteRequiresExactHumanAuthorityAndBrokerSession(): void {
    $source = $this->source();

    foreach ([
      "test \"\$EVENT_NAME\" = 'issue_comment'",
      "test \"\$EVENT_ACTION\" = 'created'",
      "test \"\$ISSUE_NUMBER\" = '1261'",
      "test \"\$IS_PULL_REQUEST\" = 'false'",
      "test \"\$COMMENT_LOGIN\" = 'E-merging-digital'",
      "test \"\$COMMENT_USER_TYPE\" = 'User'",
      "test \"\$COMMENT_ASSOCIATION\" = 'OWNER'",
      "test \"\$COMMENT_VIA_APP\" = 'false'",
      'WORKFLOW_SHA',
      'requested_main',
      'requested_infra',
      'requested_release',
      'requested_session',
      'requested_pubkey',
      'PROJECT_LEAD_INFRA_SESSION=',
      'performed_via_github_app.slug == "chatgpt-codex-connector"',
      'INFRA_BROKER_SHA: ' . self::INFRA_SHA,
      'DEPLOYED_AGENCY_SHA: ' . self::DEPLOYED_AGENCY_SHA,
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringContainsString(
      '^/agency-infra-cockpit-consumer\\ prove\\ main=([0-9a-f]{40})\\ infra=([0-9a-f]{40})\\ release=([0-9a-f]{40})\\ session=([0-9a-f]{16})\\ pubkey=([0-9a-f]{64})$',
      $source,
    );
    self::assertStringContainsString(
      'ref: ${{ steps.authority.outputs.release_sha }}',
      $source,
    );
  }

  /**
   * Proves root credentials stay local while only ciphertext is published.
   */
  public function testLiveRouteKeepsRootSecretLocalAndPublishesOnlyCiphertext(): void {
    $source = $this->source();

    foreach ([
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'PREPROD_SERVER_HOST',
      'scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION',
      'agency-1261-root.key',
      'chmod 600',
      'remote-lease-root.sh',
      'lease_seconds=600',
      "grep -Fxq 'Public-Key: (3072 bit)'",
      "EXPIRY_ARMED=PASS",
      'echo "::add-mask::$token"',
      'printf \'%s\\n\' "$token" | ssh',
      'root:www-data:640',
      'openssl pkeyutl -encrypt',
      'rsa_padding_mode:oaep',
      'rsa_oaep_md:sha256',
      'rsa_mgf1_md:sha256',
      'INFRA_CONSUMER_CIPHERTEXT=',
      'PROJECT_LEAD_INFRA_PARTIAL=',
      'chatgpt-codex-connector',
      'AGENCY_CONSUMER_CLEANUP_RECEIPT=',
      'token_cleanup:"PASS"',
      'token_persisted:false',
      'secret_content_exposed:false',
      'if: ${{ always() }}',
      'rm -f -- "$RUNNER_TEMP/agency-1261-root.key"',
      'exit 97',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringNotContainsString('echo "$PREPROD_ROOT_KEY"', $source);
    self::assertStringNotContainsString('echo "$token"', $source);
    self::assertStringNotContainsString('cat "$RUNNER_TEMP/agency-1261-root.key"', $source);
    self::assertStringNotContainsString('SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}', $source);
    self::assertStringNotContainsString('SERVER_HOST: ${{ secrets.SERVER_HOST }}', $source);
    self::assertStringNotContainsString('SERVER_USER', $source);
    self::assertStringNotContainsString('PROD_SSH', $source);
    self::assertStringNotContainsString(
      'rm -f -- /etc/agency-preprod/cockpit-state-token',
      $source,
    );
  }

  /**
   * Proves independent expiry is armed before bearer provisioning and cleanup.
   */
  public function testServerTokenCleanupIsArmedBeforeProvisioningAndReceiptIsRequired(): void {
    $source = $this->source();
    $liveStart = strpos($source, 'Provision, encrypt, await private consumer proof, and cleanup');
    self::assertIsInt($liveStart);
    $live = substr($source, $liveStart);

    $trap = strpos($live, 'trap cleanup EXIT');
    $arm = strpos($live, "'$remote_helper' ARM '$SESSION' '$lease_seconds'");
    $armed = strpos($live, "grep -Fxq 'EXPIRY_ARMED=PASS'");
    $generate = strpos($live, 'token="$(openssl rand -hex 32)"');
    $mask = strpos($live, 'echo "::add-mask::$token"');
    $provision = strpos($live, '"bash \'$remote_dir/provision-cockpit-state-token.sh\'"');
    $cipher = strpos($live, 'INFRA_CONSUMER_CIPHERTEXT=');
    $partial = strpos($live, 'PROJECT_LEAD_INFRA_PARTIAL=');
    $cleanup = strpos($live, "'$remote_helper' CLEANUP '$SESSION'");
    $cleanupReceipt = strpos($live, 'AGENCY_CONSUMER_CLEANUP_RECEIPT=');

    foreach ([$trap, $arm, $armed, $generate, $mask, $provision, $cipher, $partial, $cleanup, $cleanupReceipt] as $position) {
      self::assertIsInt($position);
    }

    self::assertTrue($trap < $arm);
    self::assertTrue($arm < $armed);
    self::assertTrue($armed < $generate);
    self::assertTrue($generate < $mask);
    self::assertTrue($mask < $provision);
    self::assertTrue($provision < $cipher);
    self::assertTrue($cipher < $partial);
    self::assertTrue($partial < $cleanup);
    self::assertTrue($cleanup < $cleanupReceipt);
  }

  /**
   * Parses the governed consumer-proof workflow.
   */
  private function parsed(): array {
    $parsed = Yaml::parseFile(dirname(DRUPAL_ROOT) . '/' . self::WORKFLOW);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads the governed consumer-proof workflow source.
   */
  private function source(): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . self::WORKFLOW);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the encrypted one-shot Infrastructure cockpit consumer proof.
 *
 * @group agency_project_tests
 * @group infrastructure_cockpit_consumer_proof
 */
final class InfrastructureCockpitConsumerProofWorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/infrastructure-cockpit-consumer-proof.yml';

  private const INFRA_SHA = '5e1002410100eca12b94a0e903eeecb9f0a03cb9';

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
    self::assertStringContainsString(
      'ref: ${{ steps.authority.outputs.main_sha }}',
      $source,
    );
    self::assertStringContainsString(
      'path: .agency-control',
      $source,
    );
  }

  /**
   * Proves consumed sessions and duplicate human commands fail closed pre-secret.
   */
  public function testReplayGuardsPrecedeSecretMaterialization(): void {
    $source = $this->source();

    foreach ([
      'COMMENT_ID: ${{ github.event.comment.id }}',
      '.performed_via_github_app == null',
      '.body == $body',
      'test "$(jq \'length\' <<<"$command_ids")" -eq 1',
      'test "$(jq -r \'.[0]\' <<<"$command_ids")" = "$COMMENT_ID"',
      'INFRA_CONSUMER_CIPHERTEXT=',
      'AGENCY_CONSUMER_CLEANUP_RECEIPT=',
      'prior_execution_count',
      'test "$prior_execution_count" -eq 0',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    $comments = strpos($source, 'comments="$(gh api "repos/$GITHUB_REPOSITORY/issues/1261/comments?per_page=100")"');
    $commandGuard = strpos($source, 'test "$(jq \'length\' <<<"$command_ids")" -eq 1');
    $sessionGuard = strpos($source, 'test "$prior_execution_count" -eq 0');
    $secret = strpos($source, 'Materialize existing PREPROD root identity and pinned trust');

    foreach ([$comments, $commandGuard, $sessionGuard, $secret] as $position) {
      self::assertIsInt($position);
    }

    self::assertTrue($comments < $commandGuard);
    self::assertTrue($commandGuard < $sessionGuard);
    self::assertTrue($sessionGuard < $secret);

    $shell = <<<'BASH'
set -euo pipefail

command_body='/agency-infra-cockpit-consumer prove main=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa infra=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb release=cccccccccccccccccccccccccccccccccccccccc session=1111111111111111 pubkey=dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd'
first='[{"id":101,"user":{"login":"E-merging-digital","type":"User"},"author_association":"OWNER","performed_via_github_app":null,"body":"'"$command_body"'"}]'
duplicate='[{"id":101,"user":{"login":"E-merging-digital","type":"User"},"author_association":"OWNER","performed_via_github_app":null,"body":"'"$command_body"'"},{"id":102,"user":{"login":"E-merging-digital","type":"User"},"author_association":"OWNER","performed_via_github_app":null,"body":"'"$command_body"'"}]'
consumed='[{"id":103,"user":{"login":"E-merging-digital","type":"User"},"author_association":"OWNER","performed_via_github_app":null,"body":"'"$command_body"'"},{"id":201,"body":"INFRA_CONSUMER_CIPHERTEXT={\"session\":\"1111111111111111\"}"}]'

command_count() {
  jq -r --arg body "$command_body" '[.[] | select(.user.login == "E-merging-digital" and .user.type == "User" and .author_association == "OWNER" and .performed_via_github_app == null and .body == $body)] | length'
}
prior_count() {
  jq -r --arg session '1111111111111111' '[
    .[]
    | .body
    | (
        if startswith("INFRA_CONSUMER_CIPHERTEXT=") then sub("^INFRA_CONSUMER_CIPHERTEXT="; "")
        elif startswith("AGENCY_CONSUMER_CLEANUP_RECEIPT=") then sub("^AGENCY_CONSUMER_CLEANUP_RECEIPT="; "")
        else empty
        end
      )
    | (try fromjson catch empty)
    | select(.session == $session)
  ] | length'
}

test "$(printf '%s' "$first" | command_count)" -eq 1
test "$(printf '%s' "$first" | prior_count)" -eq 0
test "$(printf '%s' "$duplicate" | command_count)" -eq 2
test "$(printf '%s' "$consumed" | prior_count)" -eq 1
BASH;

    $process = new Process(['bash', '-uc', $shell]);
    $process->run();

    self::assertSame(
      0,
      $process->getExitCode(),
      $process->getErrorOutput() . $process->getOutput(),
    );
  }

  /**
   * Proves partial receipts bind the exact current Agency run and ciphertext.
   */
  public function testPartialReceiptBindsCurrentAgencyRunAndCipher(): void {
    $source = $this->source();

    foreach ([
      '--argjson agency_run "$GITHUB_RUN_ID"',
      '--arg cipher_sha "$cipher_sha"',
      'and .agency_run == $agency_run',
      'and .cipher_sha256 == $cipher_sha',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    $shell = <<<'BASH'
set -euo pipefail
comments='[
  {"body":"PROJECT_LEAD_INFRA_PARTIAL={\"session\":\"1111111111111111\",\"infra_run\":10,\"agency_run\":20,\"cipher_sha256\":\"good\",\"status\":\"PASS\"}"},
  {"body":"PROJECT_LEAD_INFRA_PARTIAL={\"session\":\"1111111111111111\",\"infra_run\":10,\"agency_run\":30,\"cipher_sha256\":\"stale\",\"status\":\"PASS\"}"},
  {"body":"PROJECT_LEAD_INFRA_PARTIAL={\"session\":\"1111111111111111\",\"infra_run\":10,\"agency_run\":30,\"cipher_sha256\":\"good\",\"status\":\"PASS\"}"}
]'
matched="$(jq -rc --arg session '1111111111111111' --argjson infra_run 10 --argjson agency_run 30 --arg cipher_sha 'good' '[
  .[]
  | .body
  | select(startswith("PROJECT_LEAD_INFRA_PARTIAL="))
  | sub("^PROJECT_LEAD_INFRA_PARTIAL="; "")
  | (try fromjson catch empty)
  | select(.session == $session and .infra_run == $infra_run and .agency_run == $agency_run and .cipher_sha256 == $cipher_sha and .status == "PASS")
] | if length == 1 then .[0] else empty end' <<<"$comments")"
test -n "$matched"
test "$(jq -r '.agency_run' <<<"$matched")" -eq 30
test "$(jq -r '.cipher_sha256' <<<"$matched")" = 'good'
BASH;

    $process = new Process(['bash', '-uc', $shell]);
    $process->run();

    self::assertSame(
      0,
      $process->getExitCode(),
      $process->getErrorOutput() . $process->getOutput(),
    );
  }

  /**
   * Proves split source identity before PREPROD secrets.
   *
   * Governance main and deployed release must match their exact expected SHAs.
   */
  public function testSplitSourceIdentityIsVerifiedBeforeSecretMaterialization(): void {
    $source = $this->source();

    foreach ([
      'Checkout exact deployed Agency application source',
      'Checkout exact current Agency control source',
      'path: .agency-control',
      'Verify split source identities before PREPROD secrets',
      'test "$(git rev-parse HEAD)" = "$EXPECTED_RELEASE_SHA"',
      'test "$(git -C .agency-control rev-parse HEAD)" = "$EXPECTED_MAIN_SHA"',
      'scripts/preproduction/provision-cockpit-state-token.sh',
      '.agency-control/scripts/preproduction-cockpit-consumer-proof-1260/remote-lease-root.sh',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    $releaseCheckout = strpos($source, 'Checkout exact deployed Agency application source');
    $controlCheckout = strpos($source, 'Checkout exact current Agency control source');
    $identity = strpos($source, 'Verify split source identities before PREPROD secrets');
    $secret = strpos($source, 'Materialize existing PREPROD root identity and pinned trust');

    foreach ([$releaseCheckout, $controlCheckout, $identity, $secret] as $position) {
      self::assertIsInt($position);
    }

    self::assertTrue($releaseCheckout < $controlCheckout);
    self::assertTrue($controlCheckout < $identity);
    self::assertTrue($identity < $secret);
  }

  /**
   * Proves remote SHA extraction is local, equality-gated, and set -u safe.
   */
  public function testRemoteShaVerificationIsSetuSafe(): void {
    $source = $this->source();
    $lines = explode("\n", $source);
    $remoteShaLine = NULL;
    $remoteHelperShaLine = NULL;

    foreach ($lines as $line) {
      $trimmed = trim($line);
      if (str_starts_with($trimmed, 'remote_sha=')) {
        $remoteShaLine = $trimmed;
      }
      if (str_starts_with($trimmed, 'remote_helper_sha=')) {
        $remoteHelperShaLine = $trimmed;
      }
    }

    self::assertIsString($remoteShaLine);
    self::assertIsString($remoteHelperShaLine);
    self::assertStringContainsString(
      "\"sha256sum '\$remote_dir/provision-cockpit-state-token.sh'\" | awk '{print \$1}'",
      $remoteShaLine,
    );
    self::assertStringContainsString(
      "\"chmod 700 '\$remote_helper'; sha256sum '\$remote_helper'\" | awk '{print \$1}'",
      $remoteHelperShaLine,
    );
    self::assertStringNotContainsString('\\$1', $remoteShaLine);
    self::assertStringNotContainsString('\\$1', $remoteHelperShaLine);
    self::assertStringContainsString(
      'test "$local_sha" = "$remote_sha"',
      $source,
    );
    self::assertStringContainsString(
      'test "$helper_sha" = "$remote_helper_sha"',
      $source,
    );

    $shell = <<<'BASH'
set -u
ssh() {
  case "$*" in
    *provision-cockpit-state-token.sh*)
      printf '%s  %s\n' 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' 'provision-cockpit-state-token.sh'
      ;;
    *remote-lease-root.sh*)
      printf '%s  %s\n' 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' 'remote-lease-root.sh'
      ;;
    *)
      return 91
      ;;
  esac
}
ssh_opts=()
remote='root@example.invalid'
remote_dir='/root/stage'
remote_helper='/root/stage/remote-lease-root.sh'
local_sha='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
helper_sha='bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'
remote_sha="$(ssh "${ssh_opts[@]}" "$remote" "sha256sum '$remote_dir/provision-cockpit-state-token.sh'" | awk '{print $1}')"
test "$local_sha" = "$remote_sha"
remote_helper_sha="$(ssh "${ssh_opts[@]}" "$remote" "chmod 700 '$remote_helper'; sha256sum '$remote_helper'" | awk '{print $1}')"
test "$helper_sha" = "$remote_helper_sha"
BASH;

    $process = new Process(['bash', '-uc', $shell]);
    $process->run();

    self::assertSame(
      0,
      $process->getExitCode(),
      $process->getErrorOutput() . $process->getOutput(),
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
    $arm = strpos($live, '\'$remote_helper\' ARM \'$SESSION\' \'$lease_seconds\'');
    $armed = strpos($live, "grep -Fxq 'EXPIRY_ARMED=PASS'");
    $generate = strpos($live, 'token="$(openssl rand -hex 32)"');
    $mask = strpos($live, 'echo "::add-mask::$token"');
    $provision = strpos($live, '"bash \'$remote_dir/provision-cockpit-state-token.sh\'"');
    $cipher = strpos($live, 'INFRA_CONSUMER_CIPHERTEXT=');
    $partial = strpos($live, 'PROJECT_LEAD_INFRA_PARTIAL=');
    $cleanup = strrpos($live, '\'$remote_helper\' CLEANUP \'$SESSION\'');
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

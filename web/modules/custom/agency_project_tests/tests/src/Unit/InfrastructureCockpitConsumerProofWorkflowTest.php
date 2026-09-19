<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the one-shot Infrastructure cockpit consumer proof boundary.
 *
 * @group agency_project_tests
 * @group infrastructure_cockpit_consumer_proof
 */
final class InfrastructureCockpitConsumerProofWorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/infrastructure-cockpit-consumer-proof.yml';

  private const INFRA_SHA = '78d0238d6018c2c0bcf29fdafb29ed5aae7d77ac';

  private const DEPLOYED_AGENCY_SHA = 'a096d7acc682a355720e9102dd84d40780bae2f8';

  public function testWorkflowHasSecretFreePullRequestSelftestAndReusableLiveRoute(): void {
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
    $selftest = $jobs['cross-repo-selftest'] ?? NULL;
    self::assertIsArray($selftest);
    self::assertSame(['contents' => 'read'], $selftest['permissions'] ?? NULL);
    self::assertArrayNotHasKey('secrets', $selftest);
    self::assertStringContainsString(
      "github.event_name == 'pull_request'",
      (string) ($selftest['if'] ?? ''),
    );

    self::assertStringContainsString(
      'uses: E-merging-digital/infrastructure/.github/actions/agency-preprod-consumer-proof@' . self::INFRA_SHA,
      $source,
    );
    self::assertSame(2, substr_count(
      $source,
      'E-merging-digital/infrastructure/.github/actions/agency-preprod-consumer-proof@' . self::INFRA_SHA,
    ));
    self::assertStringContainsString('mode: selftest', $source);
    self::assertStringNotContainsString('secrets: inherit', $source);
    self::assertStringNotContainsString('INFRASTRUCTURE_REPO_TOKEN', $source);
    self::assertStringNotContainsString('PERSONAL_ACCESS_TOKEN', $source);
    self::assertStringNotContainsString('GH_PAT', $source);
  }

  public function testLiveRouteRequiresExactHumanAuthorityAndImmutableIdentities(): void {
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
      'INFRA_COCKPIT_CONSUMER_PROOF_RECEIPT=',
      'test "$receipt_count" -eq 0',
      'INFRA_ACTION_SHA: ' . self::INFRA_SHA,
      'DEPLOYED_AGENCY_SHA: ' . self::DEPLOYED_AGENCY_SHA,
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringContainsString(
      '^/agency-infra-cockpit-consumer\\ prove\\ main=([0-9a-f]{40})\\ infra=([0-9a-f]{40})\\ release=([0-9a-f]{40})$',
      $source,
    );
    self::assertStringContainsString(
      'ref: ${{ steps.authority.outputs.release_sha }}',
      $source,
    );
    self::assertStringContainsString(
      'infra_sha: ${{ steps.authority.outputs.infra_sha }}',
      $source,
    );
    self::assertStringContainsString(
      'expected_agency_sha: ${{ steps.authority.outputs.release_sha }}',
      $source,
    );
  }

  public function testLiveRouteKeepsAgencySecretsLocalAndPublishesOnlyBoundedReceipt(): void {
    $source = $this->source();

    foreach ([
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'PREPROD_SERVER_HOST',
      'scripts/preproduction-ssh-trust/manage-known-host.sh PROVISION',
      'agency-1261-root.key',
      'chmod 600',
      'token_cleanup == "PASS"',
      'token_persisted == false',
      'remote_source == "agency_preprod_http_projection"',
      'cockpit_status == "available"',
      'render_remote_source_marker == true',
      'post_cleanup_reason == "projection_transport_not_configured"',
      'secret_content_exposed == false',
      'prod_access == "NONE"',
      'db_access == "NONE"',
      'actions/upload-artifact@v4',
      'INFRA_COCKPIT_CONSUMER_PROOF_RECEIPT=',
      'if: ${{ always() }}',
      'rm -f -- "$RUNNER_TEMP/agency-1261-root.key"',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    self::assertStringNotContainsString('SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}', $source);
    self::assertStringNotContainsString('SERVER_HOST: ${{ secrets.SERVER_HOST }}', $source);
    self::assertStringNotContainsString('SERVER_USER', $source);
    self::assertStringNotContainsString('PROD_SSH', $source);
    self::assertStringNotContainsString('echo "$PREPROD_ROOT_KEY"', $source);
    self::assertStringNotContainsString('cat "$RUNNER_TEMP/agency-1261-root.key"', $source);
  }

  public function testSecretMaterializationOccursAfterAuthorityValidation(): void {
    $source = $this->source();
    $authority = strpos($source, 'Validate exact direct-human #1261 authority');
    $checkout = strpos($source, 'Checkout exact deployed Agency application source');
    $secret = strpos($source, 'Materialize existing PREPROD root identity and pinned trust');
    $consumer = strpos($source, 'Execute immutable Infrastructure consumer proof');

    self::assertIsInt($authority);
    self::assertIsInt($checkout);
    self::assertIsInt($secret);
    self::assertIsInt($consumer);
    self::assertLessThan($checkout, $authority);
    self::assertLessThan($secret, $checkout);
    self::assertLessThan($consumer, $secret);
  }

  private function parsed(): array {
    $parsed = Yaml::parseFile(dirname(DRUPAL_ROOT) . '/' . self::WORKFLOW);
    self::assertIsArray($parsed);
    return $parsed;
  }

  private function source(): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . self::WORKFLOW);
  }

}

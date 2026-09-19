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
      'if: ${{ always() }}',
      'remote_dir="/root/agency-1218-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"',
      '.POST_RELOAD_ACTIVE_CONFIG == "PASS"',
      '.TRANSPORT_CLASSIFICATION == "CONVERGED"',
      '.HTTP.local_https.no_auth.status == "401"',
      '.HTTP.public_https.real_bearer.status == "200"',
      "hashFiles('artifacts/preprod-cockpit-transport-1218/apply/result.json')",
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
      "NGINX_ENABLED_SITE='/etc/nginx/sites-enabled/agency-preprod'",
      'prove_post_reload_active_config()',
      "POST_RELOAD_ACTIVE_CONFIG='PASS'",
      'POST_RELOAD_ACTIVE_CONFIG:$post_reload_active',
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
      "LEGACY_FAILED_RUN_DIR='/root/agency-1218-35221860275-1'",
      'runuser -u agency-preprod -- bash -s',
      "del(.legacy_failed_staging)",
      'approved_operational_digest',
      'live_operational_digest',
      '[[ ! -L "$LEGACY_FAILED_RUN_DIR" ]]',
      'rm -rf -- "$LEGACY_FAILED_RUN_DIR"',
      '--resolve "$HOSTNAME:443:127.0.0.1"',
      'TRANSPORT_CLASSIFICATION',
      'LOCAL_HTTPS_BASIC_INTERCEPTION',
      'PUBLIC_HTTPS_BASIC_INTERCEPTION',
      'www_authenticate',
      'local_https',
      'public_https',
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
      'rm -rf -- /root/agency-1218-*',
      'find /root',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $apply);
    }
  }

  /**
   * Proves idempotent settings convergence and explicit rollback failure.
   *
   * Rollback verification failures must never be masked as successful recovery.
   */
  public function testRemoteApplyPreservesCanonicalReaderAndVerifiesRollback(): void {
    $apply = $this->source(self::APPLY);

    foreach ([
      'APPROVED_CURRENT_RELEASE="$(jq -r',
      'APPROVED_SETTINGS_SHA="$(jq -r',
      'APPROVED_NGINX_SHA="$(jq -r',
      'live settings canonical cockpit token reader differs from approved template',
      'live settings contains duplicate cockpit token reader blocks',
      'live settings contains conflicting or partial cockpit token reader logic',
      'live settings contains conflicting cockpit token reader logic outside the canonical block',
      'Canonical reader is already converged. Preserve it exactly and never',
      'restored_settings_sha',
      'restored_nginx_sha',
      'restored_current_release',
      'token_absent',
      'STATUS:"ROLLBACK_FAILURE"',
      'current_release_match',
      'settings_sha_match',
      'nginx_sha_match',
      'nginx_test_rc',
      'nginx_reload_rc',
      'exit 97',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    foreach ([
      'live settings unexpectedly already contains the cockpit token reader',
      'nginx -t >/dev/null 2>&1 || true',
      'systemctl reload nginx >/dev/null 2>&1 || true',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $apply);
    }

    self::assertSame(
      1,
      substr_count(
        $apply,
        "live_path.write_text(live[:pos] + block + live[pos:], encoding='utf-8')",
      ),
      'The token reader must have exactly one insertion path.',
    );
  }

  /**
   * Proves APPLY stale-check stages and passes every current PLAN input.
   */
  public function testApplyStaleCheckUsesCurrentPlanInputContract(): void {
    $workflow = $this->source(self::WORKFLOW);
    $apply = $this->source(self::APPLY);

    foreach ([
      'scripts/preproduction-cockpit-transport-1218/nginx-effective-route-diagnostic.py',
      'scripts/preproduction-cockpit-transport-1218/nginx-active-config-identity.py',
      'scripts/preproduction/nginx-agency-preprod.conf.template',
      "'\$remote_dir/nginx-effective-route-diagnostic.py'",
      "'\$remote_dir/nginx-active-config-identity.py'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'NGINX_ACTIVE_IDENTITY="${9:-}"',
      'nginx_diagnostic_b64="$(base64 -w0 "$NGINX_DIAGNOSTIC")"',
      'nginx_template_b64="$(base64 -w0 "$NGINX_TEMPLATE")"',
      'nginx_active_identity_b64="$(base64 -w0 "$NGINX_ACTIVE_IDENTITY")"',
      '"$EXPECTED_MAIN" \'plan-1218-stale-check\'',
      '"$nginx_diagnostic_b64" "$nginx_template_b64" "$nginx_active_identity_b64"',
      "'.STATE | del(.legacy_failed_staging)'",
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    self::assertStringNotContainsString(
      'bash -s -- "$EXPECTED_MAIN" \'plan-1218-stale-check\' < "$PLAN_SCRIPT"',
      $apply,
    );
  }

  /**
   * Proves all local APPLY probes use the trusted loopback contract.
   */
  public function testAllLocalHttpsProbesUseTrustedLoopbackReceipt(): void {
    $apply = $this->source(self::APPLY);

    foreach ([
      'probe_local_https()',
      "--noproxy '*' --resolve \"\$HOSTNAME:443:127.0.0.1\"",
      '%{http_code}|%{remote_ip}',
      "== '127.0.0.1'",
      'LOCAL_PROBE_INVALID: HTTPS remote IP is not loopback.',
      '.proxy_bypass = true',
      'local_no_auth="$(probe_local_https)"',
      'local_fake_bearer="$(probe_local_https -H',
      'local_real_bearer="$(probe_local_https -H',
      'local_cleanup="$(probe_local_https)"',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    self::assertSame(
      1,
      substr_count($apply, "--noproxy '*'"),
      'The shared local HTTPS wrapper must be the single no-proxy boundary.',
    );
    self::assertSame(
      1,
      substr_count($apply, '--resolve "$HOSTNAME:443:127.0.0.1"'),
      'The shared local HTTPS wrapper must be the single loopback resolve boundary.',
    );
    self::assertSame(
      4,
      substr_count($apply, '"$(probe_local_https'),
      'No-auth, fake, real and post-cleanup probes must all use the trusted wrapper.',
    );
  }

  /**
   * Proves transport probes cannot run before post-reload active identity.
   */
  public function testPostReloadActiveConfigIsProvenBeforeTransportProbes(): void {
    $apply = $this->source(self::APPLY);

    foreach ([
      'ps -C nginx -o args=',
      'python3 "$NGINX_ACTIVE_IDENTITY" main-config',
      'python3 "$NGINX_ACTIVE_IDENTITY" diagnose',
      '.canonical_site.sha256 == $candidate',
      '.enabled_site.sha256 == $candidate',
      '.include_chain.status == "PROVEN"',
      '.enabled_equals_loaded == true',
      '.loaded_preprod_tls_match_count == 1',
      '.duplicate_tls == "NONE"',
      '.candidate.candidate_sha == $candidate',
      '.machine_route_count == 1',
      'POST_RELOAD_ACTIVE_CONFIG_UNPROVEN',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    $reload = strrpos($apply, 'systemctl reload nginx');
    $active = strrpos(
      $apply,
      "prove_post_reload_active_config\nPOST_RELOAD_ACTIVE_CONFIG='PASS'",
    );
    $firstProbe = strpos($apply, 'local_no_auth="$(probe_local_https)"');

    self::assertIsInt($reload);
    self::assertIsInt($active);
    self::assertIsInt($firstProbe);
    self::assertLessThan($active, $reload);
    self::assertLessThan($firstProbe, $active);

    self::assertStringNotContainsString('sleep ', $apply);
  }

  /**
   * Proves receipts expose only bounded redirect/connection evidence.
   */
  public function testApplyProbeReceiptIsBoundedAndPublicPathRemainsSeparate(): void {
    $apply = $this->source(self::APPLY);
    $workflow = $this->source(self::WORKFLOW);

    foreach ([
      'location_header_kind:$redirect.location_header_kind',
      'normalized_path:$redirect.normalized_path',
      'redirect_origin:$redirect_origin',
      'remote_ip:$remote_ip',
      'proxy_bypass:false',
      'python3 "$NGINX_DIAGNOSTIC" redirect',
      'python3 "$NGINX_DIAGNOSTIC" origin',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    foreach ([
      '.proxy_bypass == true',
      '.remote_ip == "127.0.0.1"',
      'same-host|language-prefix|scheme|host-change|other|none',
      'NGINX_REDIRECT|DRUPAL_REDIRECT|UNKNOWN',
      '.normalized_path | contains("?")',
      '.proxy_bypass == false',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'public_no_auth="$(probe_http "$ENDPOINT")"',
      'public_fake_bearer="$(probe_http "$ENDPOINT" -H',
      'public_real_bearer="$(probe_http "$ENDPOINT" -H',
      'public_cleanup="$(probe_http "$ENDPOINT")"',
    ] as $required) {
      self::assertStringContainsString($required, $apply);
    }

    self::assertStringNotContainsString('location_header:$location_header', $apply);
    self::assertStringNotContainsString('Location: $location_header', $apply);
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

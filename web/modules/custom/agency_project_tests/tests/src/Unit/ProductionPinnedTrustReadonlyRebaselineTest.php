<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the #832 pinned-trust PROD read-only rebaseline boundary.
 *
 * @group agency_project_tests
 * @group production_pinned_trust_readonly_rebaseline
 */
final class ProductionPinnedTrustReadonlyRebaselineTest extends TestCase {

  /**
   * The route is owner-bound, one-shot, exact-main and runner constrained.
   */
  public function testAuthorityAndRunnerAreBounded(): void {
    $workflow = $this->workflow();
    $parsed = DrupalYaml::decode($workflow);
    self::assertIsArray($parsed);

    self::assertSame(
      'ubuntu-24.04',
      $parsed['jobs']['validate-authority']['runs-on'],
    );
    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency'],
      $parsed['jobs']['rebaseline']['runs-on'],
    );

    foreach ([
      'github.event.issue.number == 832',
      '/agency-prod-readonly-rebaseline prove ',
      "GITHUB_ACTOR\" == 'E-merging-digital'",
      "GITHUB_RUN_ATTEMPT\" == '1'",
      'request_id must be fresh',
      'Read-only rebaseline must execute tooling from live main.',
      "RUNNER_NAME\" == 'agency-browser-runner-01'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString("\n  schedule:\n", $workflow);
  }

  /**
   * Pinned host verification must happen before the first SSH connection.
   */
  public function testPinnedTrustPrecedesStrictSsh(): void {
    $workflow = $this->workflow();
    $verify = strpos(
      $workflow,
      'manage-known-host.sh VERIFY_ONLY',
    );
    $ssh = strpos($workflow, "\n            ssh \\");

    self::assertNotFalse($verify);
    self::assertNotFalse($ssh);
    self::assertLessThan($ssh, $verify);

    foreach ([
      'StrictHostKeyChecking=yes',
      'UserKnownHostsFile="$HOME/.ssh/known_hosts"',
      'IdentitiesOnly=yes',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'ssh-keyscan',
      'StrictHostKeyChecking=no',
      'StrictHostKeyChecking=accept-new',
      'accept-new',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The credential is session-specific and removed on always cleanups.
   */
  public function testTransientSshCredentialIsAlwaysCleaned(): void {
    $workflow = $this->workflow();

    foreach ([
      'agency-prod-readonly-rebaseline-${GITHUB_RUN_ID}.key',
      'AGENCY_PROD_READONLY_SSH_KEY',
      'chmod 600 "$key_path"',
      '-i "$AGENCY_PROD_READONLY_SSH_KEY"',
      'Remove transient SSH credential after read-only session',
      'Finalize transient read-only session material',
      'rm -f -- "$AGENCY_PROD_READONLY_SSH_KEY"',
      'ssh_credential_cleanup=PASS',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertGreaterThanOrEqual(
      2,
      substr_count($workflow, 'if: ${{ always() }}'),
    );
    self::assertStringNotContainsString('~/.ssh/id_ed25519', $workflow);
  }

  /**
   * Release identity comes only from the active release promotion receipt.
   */
  public function testReleaseIdentityIsReceiptBoundAndReadOnly(): void {
    $remote = $this->remoteScript();

    foreach ([
      'if [[ "$#" -ne 1 ]]',
      "PROJECT_ROOT='/var/www/agency'",
      'CURRENT_RELEASE="$PROJECT_ROOT/current"',
      'PROMOTIONS_DIR="$PROJECT_ROOT/shared/promotions"',
      'current_release="$(readlink -f "$CURRENT_RELEASE")"',
      "'^release_path='",
      "'^candidate_sha='",
      '[[ "$matched_receipts" -eq 1 ]]',
      'PROMOTION_RECEIPT_MATCH_COUNT=1',
      'ACTIVE_RELEASE_PATH=RESOLVED',
      'ACTIVE_PROD_RELEASE_MATCH=PASS',
    ] as $required) {
      self::assertStringContainsString($required, $remote);
    }

    foreach ([
      'git ',
      'drush ',
      'sql:',
      'mysql ',
      'mariadb ',
      'scp ',
      'rsync ',
      'PREPROD_',
    ] as $forbidden) {
      if ($forbidden === 'PREPROD_') {
        continue;
      }
      self::assertStringNotContainsString($forbidden, $remote);
    }

    self::assertStringContainsString('PROD_DB_READ=NONE', $remote);
    self::assertStringContainsString('PROD_WRITE=NONE', $remote);
    self::assertStringContainsString('PREPROD_MUTATION=NONE', $remote);
  }

  /**
   * Public probes and evidence expose fixed, metadata-only outcomes.
   */
  public function testFixedPublicProbesAndMetadataOnlyEvidence(): void {
    $workflow = $this->workflow();

    foreach ([
      'https://emergingdigital.be/health/live',
      'https://emergingdigital.be/health/ready',
      'https://emergingdigital.be/fr/blog',
      'pinned_ssh_trust=PASS',
      'strict_host_key_checking=YES',
      'active_release_path=RESOLVED',
      'promotion_receipt_match_count=1',
      'real_prod_snapshot=NOT_PERFORMED',
      'prod_db_read=NONE',
      'prod_write=NONE',
      'prod_deploy=NONE',
      'prod_scheduler_mutation=NONE',
      'preprod_mutation=NONE',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'command_input',
      'sql_input',
      'path_input',
      'url_input',
      "printf 'server_host=",
      "printf 'SERVER_HOST=",
      'deploy-preproduction',
      'deploy-production',
      'drush ',
      'sql:',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Returns the governed #832 workflow.
   */
  private function workflow(): string {
    return $this->file(
      '.github/workflows/prod-pinned-trust-readonly-rebaseline.yml',
      TRUE,
    );
  }

  /**
   * Returns the fixed remote receipt inspection operation.
   */
  private function remoteScript(): string {
    return $this->file(
      'scripts/production-ssh-trust/remote-readonly-rebaseline.sh',
    );
  }

  /**
   * Reads a repository file and optionally validates YAML syntax.
   */
  private function file(string $relative, bool $yaml = FALSE): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/' . $relative;
    self::assertFileExists($path);
    if ($yaml) {
      self::assertIsArray(Yaml::parseFile($path));
    }
    return (string) file_get_contents($path);
  }

}

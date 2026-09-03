<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects governed same-artifact production promotion.
 *
 * @group agency_project_tests
 * @group production_promotion
 */
final class ProductionPromotionSafetyTest extends TestCase {

  /**
   * Promotion keeps exact owner GO authority inside the reusable workflow.
   */
  public function testWorkflowRequiresExactHumanGoIdentity(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString('workflow_call:', $workflow);
    self::assertStringNotContainsString('issue_comment:', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString("\n  push:\n", $workflow);
    self::assertStringContainsString(
      "github.event.comment.user.login == 'E-merging-digital'",
      $workflow,
    );
    self::assertStringContainsString(
      "github.event.comment.author_association == 'OWNER'",
      $workflow,
    );
    self::assertStringContainsString(
      '/agency-production-promote\\ go\\ sha=',
      $workflow,
    );
    foreach ([
      'artifact=',
      'composer=',
      'build=',
      'preprod=',
      'auth_body_sha',
      'comment_id',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Builder and PREPROD provenance must both be terminal and exact.
   */
  public function testWorkflowBindsBuilderAndPreprodProofToCandidate(): void {
    $workflow = $this->workflow();

    foreach ([
      '.github/workflows/build-release-candidate.yml',
      "'.conclusion'",
      "'.head_sha'",
      'agency-release-candidate-${{ steps.auth.outputs.candidate_sha }}',
      '.github/workflows/deploy-preproduction.yml',
      'agency-preproduction-evidence-${{ steps.auth.outputs.candidate_sha }}-${{ steps.auth.outputs.preprod_run }}',
      'expected_artifact_sha256=$ARTIFACT_SHA',
      'composer_lock_sha256=$COMPOSER_SHA',
      'governed_content=PASS',
      'side_effects=PASS',
      'basic_auth_credentials=PASS',
      'http_smoke=PASS',
      "'.visual_desktop'",
      "'.visual_mobile'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Functional staleness fails closed during the #812 tooling cutover.
   */
  public function testWorkflowRejectsFunctionalChangesAfterCandidateBaseline(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString('git merge-base', $workflow);
    self::assertStringContainsString('git diff --name-only', $workflow);
    self::assertStringContainsString(
      'Candidate is stale: live main changed functional path',
      $workflow,
    );
    foreach ([
      '.github/workflows/promote-production.yml',
      '.github/workflows/deploy-production.yml',
      'scripts/production-promotion/*',
      'docs/operations/preproduction.md',
    ] as $allowedCutoverPath) {
      self::assertStringContainsString($allowedCutoverPath, $workflow);
    }
  }

  /**
   * Same-artifact production scripts may never rebuild the candidate.
   */
  public function testPromotionScriptsContainNoRebuildPath(): void {
    foreach ([
      'scripts/production-promotion/promote-candidate.sh',
      'scripts/production-promotion/launch-promotion.sh',
      'scripts/production-promotion/inspect-promotion.sh',
    ] as $path) {
      $script = $this->script($path);
      self::assertStringNotContainsString('git clone', $script);
      self::assertStringNotContainsString('composer install', $script);
      self::assertStringNotContainsString('git pull', $script);
    }
  }

  /**
   * The server re-verifies payload identity and writes an anti-replay receipt.
   */
  public function testServerPromotionVerifiesIdentityAndPreventsReplay(): void {
    $script = $this->script('scripts/production-promotion/promote-candidate.sh');

    foreach ([
      'EXPECTED_SHA="${1:-}"',
      'EXPECTED_ARTIFACT_SHA256="${2:-}"',
      'EXPECTED_COMPOSER_LOCK_SHA256="${3:-}"',
      'AUTH_COMMENT_ID="${4:-}"',
      'AUTH_BODY_SHA256="${5:-}"',
      'candidate.json SHA mismatch.',
      'candidate.json artifact digest mismatch.',
      'candidate.json Composer lock digest mismatch.',
      'Extracted composer.lock digest mismatch.',
      'This exact candidate artifact already has a successful production promotion receipt.',
      'rollback_boundary=PREVIOUS_RELEASE_PLUS_DB_BACKUP',
      'authorization_comment_id=',
      'authorization_body_sha256=',
      'mv -f "$receipt_tmp" "$RECEIPT"',
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }
  }

  /**
   * Production operational invariants remain part of same-artifact activation.
   */
  public function testServerPromotionPreservesOperationalSequence(): void {
    $script = $this->script('scripts/production-promotion/promote-candidate.sh');

    foreach ([
      'vendor/bin/drush sql:dump --gzip',
      'system.maintenance_mode 1',
      'ln -sfn "$NEW_RELEASE" "$CURRENT_LINK"',
      '"$CURRENT_LINK/vendor/bin/drush" updb -y',
      '"$CURRENT_LINK/vendor/bin/drush" cim -y',
      'config/splits/production',
      'emerging:governed-content:validate',
      'emerging:governed-content --all --dry-run',
      'emerging:governed-content --all',
      '"$CURRENT_LINK/vendor/bin/drush" cr',
      'system.maintenance_mode 0',
      'No automatic database rollback is attempted after schema/config mutation.',
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }
  }

  /**
   * Issue #985 reuses the #983 helper from the exact candidate before activation.
   */
  public function testPromotionConvergesConfigSyncFromExactCandidateBeforeActivation(): void {
    $promotion = $this->script('scripts/production-promotion/promote-candidate.sh');
    $legacyDeploy = $this->script('scripts/deploy-production.sh');

    foreach ([
      'SETTINGS_FILE="$SHARED_DIR/settings/settings.php"',
      'CONFIG_SYNC_CONVERGER="$NEW_RELEASE/scripts/production-settings/converge-config-sync-directory.sh"',
      '[[ -r "$CONFIG_SYNC_CONVERGER" && -x "$CONFIG_SYNC_CONVERGER" ]]',
      'bash -n "$CONFIG_SYNC_CONVERGER"',
      '"$CONFIG_SYNC_CONVERGER" "$SETTINGS_FILE"',
    ] as $required) {
      self::assertStringContainsString($required, $promotion);
    }

    $preflight = strpos($promotion, 'Preflight active production Drupal and database.');
    $helper = strpos($promotion, 'CONFIG_SYNC_CONVERGER="$NEW_RELEASE/scripts/production-settings/converge-config-sync-directory.sh"');
    $syntax = strpos($promotion, 'bash -n "$CONFIG_SYNC_CONVERGER"');
    $convergence = strpos($promotion, '"$CONFIG_SYNC_CONVERGER" "$SETTINGS_FILE"');
    $backup = strpos($promotion, 'vendor/bin/drush sql:dump --gzip');
    $maintenance = strpos($promotion, 'system.maintenance_mode 1');
    $newReleaseBootstrap = strpos($promotion, '(cd "$NEW_RELEASE" && vendor/bin/drush status --fields=bootstrap >/dev/null)');
    $switch = strpos($promotion, 'ln -sfn "$NEW_RELEASE" "$CURRENT_LINK"');

    foreach ([
      $preflight,
      $helper,
      $syntax,
      $convergence,
      $backup,
      $maintenance,
      $newReleaseBootstrap,
      $switch,
    ] as $position) {
      self::assertIsInt($position);
    }

    self::assertTrue($preflight < $helper);
    self::assertTrue($helper < $syntax);
    self::assertTrue($syntax < $convergence);
    self::assertTrue($convergence < $backup);
    self::assertTrue($backup < $maintenance);
    self::assertTrue($maintenance < $newReleaseBootstrap);
    self::assertTrue($newReleaseBootstrap < $switch);

    self::assertStringContainsString('set -Eeuo pipefail', $promotion);
    self::assertMatchesRegularExpression(
      '~^"\$CONFIG_SYNC_CONVERGER" "\$SETTINGS_FILE"$~m',
      $promotion,
    );
    self::assertStringNotContainsString(
      '"$CONFIG_SYNC_CONVERGER" "$SETTINGS_FILE" ||',
      $promotion,
    );
    self::assertStringNotContainsString('preg_replace(', $promotion);

    self::assertStringContainsString(
      'PRODUCTION_SETTINGS_CONVERGER="$NEW_RELEASE/scripts/production-settings/converge-config-sync-directory.sh"',
      $legacyDeploy,
    );
    self::assertStringContainsString(
      'bash "$PRODUCTION_SETTINGS_CONVERGER" "$PRODUCTION_SETTINGS_FILE"',
      $legacyDeploy,
    );
  }

  /**
   * Detached worker shares the same server lock as the emergency lane.
   */
  public function testPromotionWorkerIsDetachedLockedAndIdentityBound(): void {
    $launcher = $this->script('scripts/production-promotion/launch-promotion.sh');
    $inspector = $this->script('scripts/production-promotion/inspect-promotion.sh');

    foreach ([
      'LOCK_FILE="/var/www/agency/shared/deploy.lock"',
      'flock -n 9',
      'write_result LOCKED 75',
      'nohup setsid --wait',
      'expected_artifact_sha256=',
      'expected_composer_lock_sha256=',
      'authorization_comment_id=',
      'authorization_body_sha256=',
    ] as $required) {
      self::assertStringContainsString($required, $launcher);
    }

    foreach ([
      'result_artifact_mismatch',
      'result_composer_mismatch',
      'result_authorization_comment_mismatch',
      'result_authorization_digest_mismatch',
      'worker_exited_without_result',
      'worker_pid_reused',
    ] as $required) {
      self::assertStringContainsString($required, $inspector);
    }

    foreach (['drush ', 'composer ', 'git clone', 'sudo ', 'systemctl '] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $inspector);
    }
  }

  /**
   * Post-deploy health, smoke and browser evidence are mandatory.
   */
  public function testWorkflowRequiresTerminalProductionEvidence(): void {
    $workflow = $this->workflow();

    foreach ([
      'Validate production health and canonical smoke',
      'for endpoint in live ready; do',
      '"$PROD_URL/health/$endpoint"',
      '/fr/blog',
      'monitoring_signal=PASS',
      'Run production Playwright desktop and mobile proof',
      'artifacts/browser-validation/result.json',
      'Publish non-sensitive production promotion receipt',
      'previous release + pre-promotion DB backup',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Returns and validates the governed promotion workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/promote-production.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

  /**
   * Returns a repository-owned promotion script.
   */
  private function script(string $relativePath): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/' . $relativePath;
    self::assertFileExists($path);

    return (string) file_get_contents($path);
  }

}

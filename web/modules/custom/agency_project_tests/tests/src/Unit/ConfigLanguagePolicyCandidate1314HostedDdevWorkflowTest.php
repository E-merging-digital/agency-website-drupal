<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #1314 hosted-DDEV replay contract.
 *
 * @group agency_project_tests
 * @group configuration_language_candidate_1314
 */
final class ConfigLanguagePolicyCandidate1314HostedDdevWorkflowTest extends TestCase {

  /**
   * The hosted proof is exact-head, GitHub-hosted and environment-isolated.
   */
  public function testHostedProofExecutionBoundary(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString('runs-on: ubuntu-24.04', $workflow);
    self::assertStringContainsString(
      "github.event.pull_request.head.ref == 'fix/1314-prove-site-default-language-lock-policy'",
      $workflow,
    );
    self::assertStringContainsString(
      'ref: ${{ github.event.pull_request.head.sha }}',
      $workflow,
    );
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringNotContainsString('runs-on: self-hosted', $workflow);
    self::assertStringNotContainsString('secrets.', $workflow);
    self::assertStringNotContainsString('ssh ', $workflow);
  }

  /**
   * Candidate A is temporary and historical/policy surfaces remain protected.
   */
  public function testTemporaryCandidateAndProtectedSurfaces(): void {
    $workflow = $this->workflow();

    self::assertStringContainsString(
      'config/sync/config_language_lock.settings.yml',
      $workflow,
    );
    self::assertStringContainsString(
      'locked_langcode: fr',
      $workflow,
    );
    self::assertStringContainsString(
      'follow_site_default: true',
      $workflow,
    );
    self::assertStringContainsString(
      '.github/workflows/config-language-lock-609-hosted-ddev.yml',
      $workflow,
    );
    self::assertStringContainsString(
      'docs/configuration-language-policy.yml',
      $workflow,
    );
    self::assertStringContainsString(
      'docs/decisions/ADR-002-configuration-language-governance.md',
      $workflow,
    );
    self::assertStringContainsString('AGENTS.md', $workflow);
    self::assertStringContainsString(
      'git diff --quiet HEAD -- config/sync',
      $workflow,
    );
    self::assertStringContainsString(
      'CONFIG_SYNC_WORKTREE_UNCHANGED',
      $workflow,
    );
  }

  /**
   * One control import is allowed, while export discovery stays forbidden.
   */
  public function testSingleControlImportAndNoExportDiscovery(): void {
    $workflow = $this->workflow();

    self::assertSame(1, substr_count($workflow, 'ddev drush cim -y'));
    self::assertStringNotContainsString('ddev drush cex', $workflow);
    self::assertStringContainsString('CONTROL_CIM_COUNT: 1', $workflow);
    self::assertStringContainsString('CONTROL_CIM_RESULT', $workflow);
  }

  /**
   * Observed counts are captured, never encoded as pass constants.
   */
  public function testObservedCountsAreNotHardCoded(): void {
    $workflow = $this->workflow();

    self::assertStringNotContainsString('353', $workflow);
    self::assertStringNotContainsString('194', $workflow);
    self::assertStringNotContainsString('515', $workflow);
    self::assertStringContainsString(
      'MODULE_NORMALIZATION_CHANGED_COUNT',
      $workflow,
    );
    self::assertStringContainsString('FIRST_CONFIG_STATUS_TOTAL', $workflow);
    self::assertStringContainsString('SECOND_CONFIG_STATUS_TOTAL', $workflow);
    self::assertStringContainsString(
      'ACTIVE_FR_COLLECTION_COUNT_BEFORE_CONTROL_IMPORT',
      $workflow,
    );
    self::assertStringContainsString(
      'ACTIVE_EN_COLLECTION_COUNT_AFTER_CONTROL_IMPORT',
      $workflow,
    );
  }

  /**
   * The helper emits bounded metadata and no collection names/config values.
   */
  public function testRuntimeHelperIsBounded(): void {
    $helper = (string) file_get_contents(
      dirname(DRUPAL_ROOT)
      . '/scripts/runner/config-language-policy-candidate-1314-runtime-proof.php',
    );

    self::assertStringContainsString(
      'ConfigLanguageLockRequirementsHooks::class',
      $helper,
    );
    self::assertStringContainsString('names_sha256', $helper);
    self::assertStringContainsString("'count' => count(\$names)", $helper);
    self::assertStringContainsString("'und' =>", $helper);
    self::assertStringContainsString("'zxx' =>", $helper);
    self::assertStringContainsString(
      "'config_values_exposed' => FALSE",
      $helper,
    );
    self::assertStringNotContainsString("'names' => \$names", $helper);
  }

  /**
   * Loads the new hosted-DDEV workflow.
   */
  private function workflow(): string {
    return (string) file_get_contents(
      dirname(DRUPAL_ROOT)
      . '/.github/workflows/config-language-policy-candidate-1314-hosted-ddev.yml',
    );
  }

}

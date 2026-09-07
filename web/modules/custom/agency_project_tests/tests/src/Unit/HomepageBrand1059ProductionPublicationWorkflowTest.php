<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #1091 / #1059 EN-only PROD publication route.
 *
 * @group agency_project_tests
 * @group homepage_brand_1059_prod
 */
final class HomepageBrand1059ProductionPublicationWorkflowTest extends TestCase {

  private const WORKFLOW = '.github/workflows/trusted-homepage-brand-1059-production-publication.yml';
  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const RUNNER = 'scripts/runner/run-homepage-brand-1059-production-publication.sh';
  private const PROFILE = 'scripts/runner/homepage-brand-1059.php';
  private const OLD_WORKFLOW = '.github/workflows/trusted-homepage-brand-1015-production-publication.yml';
  private const SHA = 'e7f6e184a31048b4fcea7f126b6e24e9aa23db46522cd2537f441fd2f3218390';

  /**
   * Proves the route is fixed to #1091 controlling #1059 EN only.
   */
  public function testWorkflowIsBoundTo1091AndExact1059Identity(): void {
    $workflow = $this->source(self::WORKFLOW);

    self::assertStringContainsString('github.event.issue.number == 1091', $workflow);
    self::assertStringContainsString("CONTROL_ISSUE: '1091'", $workflow);
    self::assertStringContainsString("CONTENT_ISSUE: '1059'", $workflow);
    self::assertStringContainsString("PROFILE_SHA256: '" . self::SHA . "'", $workflow);
    self::assertStringContainsString(
      "PREPROD_CANDIDATE_SHA: 'd07994a2c54b5e87bedf123b44a91d341c34fe49'",
      $workflow,
    );
    self::assertStringContainsString("PREPROD_DEPLOY_RUN: '34147665186'", $workflow);
    self::assertStringContainsString("BROWSER_RUN: '34150107474'", $workflow);
    self::assertStringContainsString("BROWSER_ARTIFACT: '10029063144'", $workflow);
    self::assertStringContainsString(
      '/agency-homepage-brand-1059-prod dry-run',
      $workflow,
    );
    self::assertStringContainsString('/agency-homepage-brand-1059-prod apply', $workflow);
    self::assertStringContainsString("LANGUAGE_MODE: 'FR_EN_APPROVED'", $workflow);
    self::assertStringContainsString("PROD_MUTATION: 'EN_ONLY'", $workflow);
    self::assertStringNotContainsString('FR_ONLY_EXCEPTION_APPROVED', $workflow);
  }

  /**
   * Proves direct human approval is exact and cannot be synthesized.
   */
  public function testDirectHumanApprovalIsFailClosed(): void {
    $workflow = $this->source(self::WORKFLOW);

    foreach ([
      '<!-- agency-human-homepage-1059-prod-approval:v1 -->',
      'control_issue=1091',
      'content_issue=1059',
      'candidate_sha=$PREPROD_CANDIDATE_SHA',
      'deploy_run=$PREPROD_DEPLOY_RUN',
      'browser_run=$BROWSER_RUN',
      'browser_artifact=$BROWSER_ARTIFACT',
      'preprod_fr_url=$PREPROD_FR_URL',
      'preprod_en_url=$PREPROD_EN_URL',
      'human_render_fr=APPROVED',
      'human_render_en=APPROVED',
      'language_mode=$LANGUAGE_MODE',
      'prod_mutation=$PROD_MUTATION',
      '.user.login == $owner',
      '.author_association == "OWNER"',
      '.performed_via_github_app == null',
      '.created_at == .updated_at',
      "'length'",
    ] as $required) {
      if ($required === "'length'") {
        self::assertStringContainsString("jq 'length'", $workflow);
        continue;
      }
      self::assertStringContainsString($required, $workflow, $required);
    }

    self::assertStringNotContainsString('--body "$expected_body"', $workflow);
    self::assertStringNotContainsString(
      'gh issue comment "$CONTROL_ISSUE" --body "$expected_body"',
      $workflow,
    );
  }

  /**
   * Proves dry-run/apply separation and exact prior dry-run binding.
   */
  public function testApplyRequiresExactDryRunAndBackupBeforeWrite(): void {
    $workflow = $this->source(self::WORKFLOW);
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      'Require exact prior PROD dry-run before apply',
      $workflow,
    );
    self::assertStringContainsString(
      "if: \${{ steps.request.outputs.mode == 'apply' }}",
      $workflow,
    );
    foreach ([
      'control_issue_created_at=',
      'profile_sha256=',
      'trusted_main=',
      'human_approval_comment=',
      'candidate_sha=',
      'deploy_run=',
      'browser_run=',
      'browser_artifact=',
      'language=en',
      'prod_mutation=EN_ONLY',
      'content_sync_before=RELEASED',
      'content_sync_after=RELEASED',
      'content_sync_mutation=NONE',
      'prod_write=NONE',
    ] as $binding) {
      self::assertStringContainsString($binding, $workflow, $binding);
    }

    $apply = strpos($runner, 'if [[ "$MODE" == \'apply\' ]]; then');
    $backup = strpos($runner, 'vendor/bin/drush sql:dump');
    $profileExecution = strpos($runner, 'AGENCY_HOMEPAGE_BRAND_1059_MODE=');
    $cacheRebuild = strpos($runner, 'vendor/bin/drush cr');
    self::assertIsInt($apply);
    self::assertIsInt($backup);
    self::assertIsInt($profileExecution);
    self::assertIsInt($cacheRebuild);
    self::assertLessThan($backup, $apply);
    self::assertLessThan($profileExecution, $backup);
    self::assertLessThan($cacheRebuild, $profileExecution);
  }

  /**
   * Proves the #1059 profile stays EN-only with Content Sync released.
   */
  public function testProfileAndRunnerPreserveEnOnlyReleasedContract(): void {
    $profile = $this->source(self::PROFILE);
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      "private const PROFILE = 'homepage-brand-1059';",
      $profile,
    );
    self::assertStringContainsString(
      "private const PROFILE_SHA256 = '" . self::SHA . "';",
      $profile,
    );
    self::assertStringContainsString("private const LANGCODE = 'en';", $profile);
    self::assertStringContainsString(
      'Homepage must remain editor-owned with released Content Sync lifecycle.',
      $profile,
    );
    self::assertStringContainsString(
      'Homepage FR approved #1015 baseline changed; #1059 refuses to rewrite FR.',
      $profile,
    );
    self::assertStringContainsString(
      'Homepage FR translation or Paragraph references changed during EN apply.',
      $profile,
    );

    self::assertStringContainsString('homepage-brand-1059.php', $runner);
    self::assertStringContainsString('content_sync_before: "RELEASED"', $runner);
    self::assertStringContainsString('content_sync_after: "RELEASED"', $runner);
    self::assertStringContainsString('content_sync_mutation: "NONE"', $runner);
    self::assertStringContainsString('fr_mutation: "FORBIDDEN"', $runner);
    self::assertStringNotContainsString('homepage-brand-1015.php', $runner);
    self::assertStringNotContainsString('ACTIVE_RECONCILIATION_REQUIRED', $runner);
    self::assertStringNotContainsString('content_sync_reconciliation', $runner);
    self::assertStringNotContainsString('markReleased', $runner);
  }

  /**
   * Proves bounded public FR/EN verification is apply-only and exact.
   */
  public function testPostApplyVerificationPreservesFrAndConvergesEn(): void {
    $runner = $this->source(self::RUNNER);

    self::assertStringContainsString(
      "FR_H1='Créer, améliorer ou moderniser votre plateforme web'",
      $runner,
    );
    self::assertStringContainsString(
      "EN_H1='Create, improve or modernise your web platform'",
      $runner,
    );
    self::assertStringContainsString(
      "probe_public 'https://emergingdigital.be/fr'",
      $runner,
    );
    self::assertStringContainsString(
      "probe_public 'https://emergingdigital.be/en'",
      $runner,
    );
    self::assertStringContainsString(
      "fr_database_snapshot='UNCHANGED_BY_PROFILE_ASSERTION'",
      $runner,
    );
    self::assertStringContainsString("en_profile='CONVERGED'", $runner);
    self::assertStringContainsString('.public_verification.fr.h1 == $fr_h1', $runner);
    self::assertStringContainsString('.public_verification.en.h1 == $en_h1', $runner);
  }

  /**
   * Proves exact dispatcher integration and preservation of the #1015 route.
   */
  public function testDispatcherCommandsAreExactAndOld1015RouteRemains(): void {
    $dispatcher = $this->source(self::DISPATCHER);
    $oldWorkflow = $this->source(self::OLD_WORKFLOW);

    self::assertStringContainsString(
      'homepage-brand-1059-production-publication:',
      $dispatcher,
    );
    self::assertStringContainsString('github.event.issue.number == 1091', $dispatcher);
    self::assertStringContainsString(
      '/agency-homepage-brand-1059-prod dry-run',
      $dispatcher,
    );
    self::assertStringContainsString('/agency-homepage-brand-1059-prod apply', $dispatcher);
    self::assertStringContainsString(
      'uses: ./.github/workflows/trusted-homepage-brand-1059-production-publication.yml',
      $dispatcher,
    );

    self::assertStringContainsString(
      'homepage-brand-1015-production-publication:',
      $dispatcher,
    );
    self::assertStringContainsString(
      'uses: ./.github/workflows/trusted-homepage-brand-1015-production-publication.yml',
      $dispatcher,
    );
    self::assertStringContainsString('FR_ONLY_EXCEPTION_APPROVED', $oldWorkflow);
  }

  /**
   * Proves the bounded shell is syntactically valid.
   */
  public function testRunnerShellSyntax(): void {
    $path = dirname(DRUPAL_ROOT) . '/' . self::RUNNER;
    $output = [];
    $status = 1;
    exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
  }

  /**
   * Reads one repository source file as text.
   */
  private function source(string $relativePath): string {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    return (string) file_get_contents($path);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #1012 closed PREPROD route without widening #959.
 *
 * @group agency_project_tests
 * @group drupal_2027_preprod_candidate
 */
#[Group('drupal_2027_preprod_candidate')]
final class Drupal2027PreprodCandidateWorkflowTest extends TestCase {

  /**
   * The new workflow is bound to one issue/profile/target and dry-run receipt.
   */
  public function testWorkflowIsClosedAndHashBound(): void {
    $workflow = $this->repositoryFile(
      '.github/workflows/trusted-drupal-2027-preprod-candidate.yml',
    );

    self::assertStringContainsString('github.event.issue.number == 1012', $workflow);
    self::assertStringContainsString('/agency-drupal-2027-candidate dry-run', $workflow);
    self::assertStringContainsString('/agency-drupal-2027-candidate apply', $workflow);
    self::assertStringContainsString('candidate_sha256=', $workflow);
    self::assertStringContainsString('trusted_main=', $workflow);
    self::assertStringContainsString('source_updated_at', $workflow);
    self::assertStringContainsString('PREPROD', $workflow);
    self::assertStringContainsString('/fr/drupal-2027', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * The runtime helper has fixed page/fr/alias constants and no SQL path.
   */
  public function testProfileIsNotGenericWriter(): void {
    $profile = $this->repositoryFile(
      'scripts/runner/drupal-2027-preprod-candidate.php',
    );
    $shell = $this->repositoryFile(
      'scripts/runner/run-drupal-2027-preprod-candidate.sh',
    );

    self::assertStringContainsString("private const BUNDLE = 'page';", $profile);
    self::assertStringContainsString("private const LANGCODE = 'fr';", $profile);
    self::assertStringContainsString("private const PUBLIC_ALIAS = '/fr/drupal-2027';", $profile);
    self::assertStringContainsString("private const DRUPAL_ALIAS = '/drupal-2027';", $profile);
    self::assertStringContainsString('ParagraphInterface', $profile);
    self::assertStringContainsString("getStorage('node')->create", $profile);
    self::assertStringContainsString("'pathauto' => 0", $profile);
    self::assertStringContainsString('FAIL_CLOSED', $profile);
    self::assertStringNotContainsString('database()', $profile);
    self::assertStringNotContainsString('->query(', $profile);
    self::assertStringContainsString('agency-preprod@', $shell);
    self::assertStringContainsString('verify-preprod-pinned-trust.sh', $shell);
    self::assertStringContainsString('validate-runtime.sh', $shell);
  }

  /**
   * #959 remains Article-only with its mandatory EN translation contract.
   */
  public function testExisting959ArticleOnlyContractIsPreserved(): void {
    $article = $this->repositoryFile(
      'scripts/runner/editorial-preprod-candidate.php',
    );
    $finalizer = $this->repositoryFile(
      'web/modules/custom/emerging_digital_content/src/Service/EditorialPathautoFinalizer.php',
    );

    self::assertStringContainsString("private const BUNDLE = 'article';", $article);
    self::assertStringContainsString("private const TRANSLATION_LANGCODE = 'en';", $finalizer);
    self::assertStringContainsString('requires the EN translation', $finalizer);
  }

  /**
   * The real browser contract covers the required review surface.
   */
  public function testBrowserContractIsRegistered(): void {
    $config = $this->repositoryFile('playwright.config.mjs');
    $spec = $this->repositoryFile('tests/browser/drupal-2027-preprod.spec.mjs');

    self::assertStringContainsString("'drupal-2027-preprod.json'", $config);
    self::assertStringContainsString('services-page-hero.svg', $spec);
    self::assertStringContainsString('drupal-lifecycle-diagnostic', $spec);
    self::assertStringContainsString('accessibility_baseline', $spec);
    self::assertStringContainsString('hreflangEn', $spec);
    self::assertStringContainsString('noindex', $spec);
  }

  private function repositoryFile(string $path): string {
    $file = dirname(DRUPAL_ROOT) . '/' . ltrim($path, '/');
    $content = file_get_contents($file);
    self::assertIsString($content, sprintf('Unable to read %s.', $path));
    return $content;
  }

}

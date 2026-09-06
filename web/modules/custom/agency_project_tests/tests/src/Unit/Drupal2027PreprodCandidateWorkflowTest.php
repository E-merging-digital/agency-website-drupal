<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Locks the bounded #1046 Drupal 2027 FR+EN routes.
 *
 * @group agency_project_tests
 * @group drupal_2027_preprod_candidate
 */
#[Group('drupal_2027_preprod_candidate')]
final class Drupal2027PreprodCandidateWorkflowTest extends TestCase {

  private const REVISION = '5555544679';
  private const SHA = 'ac96465c5717f78af76e368d8598399cbe997ed63d7cc753d575337c9321af83';

  /**
   * The PREPROD route stays bound to the exact frozen FR+EN candidate.
   */
  public function testPreprodRouteIsBoundToExactFrEnCandidate(): void {
    $workflow = $this->source('.github/workflows/trusted-drupal-2027-preprod-candidate.yml');
    $materializer = $this->source('scripts/runner/drupal-2027-preprod-candidate.php');
    $shell = $this->source('scripts/runner/run-drupal-2027-preprod-candidate.sh');
    $contract = $this->source('tests/browser/contracts/drupal-2027-preprod.json');
    $spec = $this->source('tests/browser/drupal-2027-preprod.spec.mjs');

    self::assertStringContainsString('github.event.issue.number == 1046', $workflow);
    self::assertStringContainsString("CANDIDATE_COMMENT_ID: '" . self::REVISION . "'", $workflow);
    self::assertStringContainsString("CANDIDATE_SHA256: '" . self::SHA . "'", $workflow);
    self::assertStringContainsString('agency-drupal-2027-payload:v2', $workflow);
    self::assertStringContainsString('language_mode=FR_EN', $workflow);
    self::assertStringContainsString('fr_url=https://preprod.emergingdigital.be/fr/drupal-2027', $workflow);
    self::assertStringContainsString('en_url=https://preprod.emergingdigital.be/en/drupal-2027', $workflow);
    self::assertStringContainsString('BEGIN DRUPAL_2027_RECEIPT_PARSER', $workflow);
    self::assertStringContainsString("private const CANDIDATE_ID = 'agency-drupal-2027-landing-1046';", $materializer);
    self::assertStringContainsString("private const TRANSLATION_LANGCODE = 'en';", $materializer);
    self::assertStringContainsString("private const EXPECTED_PAYLOAD_SHA256 = '" . self::SHA . "';", $materializer);
    self::assertStringContainsString('agency-drupal-2027-landing-1046', $shell);
    self::assertStringContainsString('route_status=$?', $shell);
    self::assertStringContainsString("test -f '$remote_result'", $shell);
    self::assertStringContainsString('exit "$route_status"', $shell);
    self::assertStringContainsString('"target_en": "/en/drupal-2027"', $contract);
    self::assertStringContainsString("lang: 'fr'", $spec);
    self::assertStringContainsString("lang: 'en'", $spec);
    self::assertStringContainsString('ed-section__content--contact-form', $spec);
  }

  /**
   * The future PROD route retains #1043 and uses FR_EN, never FR-only.
   */
  public function testFutureProdRoutePreservesHumanGateAndFrEnBinding(): void {
    $workflow = $this->source('.github/workflows/trusted-drupal-2027-production-publication.yml');
    $shell = $this->source('scripts/runner/run-drupal-2027-production-publication.sh');

    self::assertStringContainsString("APPROVED_CANDIDATE_COMMENT: '" . self::REVISION . "'", $workflow);
    self::assertStringContainsString("APPROVED_CANDIDATE_SHA256: '" . self::SHA . "'", $workflow);
    self::assertStringContainsString('scripts/validation/editorial-human-approval.php', $workflow);
    self::assertStringContainsString('--language-mode="$LANGUAGE_MODE"', $workflow);
    self::assertStringContainsString('language_mode=FR_EN', $workflow);
    self::assertStringContainsString('https://preprod.emergingdigital.be/en/drupal-2027', $workflow);
    self::assertStringNotContainsString('FR_ONLY_EXCEPTION_APPROVED', $workflow);
    self::assertStringContainsString('agency-drupal-2027-landing-1046', $shell);
    self::assertStringContainsString(self::SHA, $shell);
    self::assertStringContainsString('.aliases.en == "/en/drupal-2027"', $shell);
  }

  /**
   * The diagnostic block reuses the existing Contact shell and CSS contract.
   */
  public function testDiagnosticReusesExistingContactShellWithoutNewCss(): void {
    $template = $this->source('web/themes/custom/emerging_digital/templates/block/block--emerging-digital-drupal-lifecycle-diagnostic.html.twig');
    $css = $this->source('web/themes/custom/emerging_digital/css/components.css');

    self::assertStringContainsString('ed-section__content--contact-form', $template);
    self::assertStringContainsString('.ed-section__content--contact-form form', $css);
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $path): string {
    $content = file_get_contents(dirname(DRUPAL_ROOT) . '/' . $path);
    self::assertIsString($content, $path);
    return $content;
  }

}

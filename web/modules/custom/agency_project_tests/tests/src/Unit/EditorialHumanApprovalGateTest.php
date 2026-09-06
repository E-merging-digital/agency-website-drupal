<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Proves the direct-human fail-closed publication approval gate.
 *
 * @group agency_project_tests
 * @group editorial_human_approval
 */
final class EditorialHumanApprovalGateTest extends TestCase {

  private const OWNER = 'E-merging-digital';
  private const REVISION = '5553858896';
  private const SHA = '07fb10ab4a54371d877fbfc6b3f185eda41085ae3bd5080de2d695843c9d049e';
  private const FR_URL = 'https://preprod.emergingdigital.be/fr/drupal-2027';
  private const EN_URL = 'https://preprod.emergingdigital.be/en/drupal-2027';

  /**
   * A direct unedited OWNER comment for the exact candidate is accepted.
   */
  public function testExactDirectHumanApprovalIsAccepted(): void {
    $comment = $this->validHumanComment();
    self::assertSame(0, $this->runGate([$comment]));
  }

  /**
   * One Markdown copy/paste blank line after the marker is accepted.
   */
  public function testSingleBlankLineAfterMarkerIsAccepted(): void {
    $comment = $this->validHumanComment();
    $comment['body'] = str_replace(
      '<!-- agency-human-prod-approval:v1 -->' . "\n",
      '<!-- agency-human-prod-approval:v1 -->' . "\n\n",
      (string) $comment['body'],
    );
    self::assertSame(0, $this->runGate([$comment]));
  }

  /**
   * GitHub App-mediated owner comments are not human approvals.
   */
  public function testGithubAppMediatedApprovalIsRejected(): void {
    $comment = $this->validHumanComment();
    $comment['performed_via_github_app'] = [
      'id' => 1144995,
      'slug' => 'chatgpt-codex-connector',
    ];
    self::assertNotSame(0, $this->runGate([$comment]));
  }

  /**
   * Bot and ambiguous provenance are rejected fail-closed.
   */
  public function testBotAndMissingProvenanceAreRejected(): void {
    $bot = $this->validHumanComment();
    $bot['user'] = ['login' => 'github-actions[bot]', 'type' => 'Bot'];
    self::assertNotSame(0, $this->runGate([$bot]));

    $ambiguous = $this->validHumanComment();
    unset($ambiguous['performed_via_github_app']);
    self::assertNotSame(0, $this->runGate([$ambiguous]));
  }

  /**
   * Candidate drift cannot reuse an older human approval.
   */
  public function testWrongCandidateApprovalIsRejected(): void {
    $comment = $this->validHumanComment();
    self::assertNotSame(0, $this->runGate([$comment], [
      '--candidate-revision' => '5553858897',
    ]));
  }

  /**
   * Edited approvals are rejected so a changed candidate needs a new comment.
   */
  public function testEditedApprovalIsRejected(): void {
    $comment = $this->validHumanComment();
    $comment['updated_at'] = '2026-09-06T00:01:00Z';
    self::assertNotSame(0, $this->runGate([$comment]));
  }

  /**
   * Both current editorial PROD routes must invoke the shared gate.
   */
  public function testProductionRoutesWireTheHumanGate(): void {
    $root = dirname(DRUPAL_ROOT);
    $generic = (string) file_get_contents(
      $root . '/.github/workflows/trusted-editorial-publication.yml',
    );
    $drupal2027 = (string) file_get_contents(
      $root . '/.github/workflows/trusted-drupal-2027-production-publication.yml',
    );

    foreach ([$generic, $drupal2027] as $workflow) {
      self::assertStringContainsString(
        'scripts/validation/editorial-human-approval.php',
        $workflow,
      );
    }
    self::assertStringContainsString(
      '### Agency editorial PREPROD candidate apply PASS',
      $generic,
    );
    self::assertStringContainsString(
      '### Agency Drupal 2027 PREPROD candidate apply PASS',
      $drupal2027,
    );
  }

  /**
   * Builds one direct human approval comment fixture.
   *
   * @return array<string, mixed>
   *   The exact human approval comment fixture.
   */
  private function validHumanComment(): array {
    return [
      'id' => 6000000000,
      'user' => [
        'login' => self::OWNER,
        'type' => 'User',
      ],
      'author_association' => 'OWNER',
      'created_at' => '2026-09-06T00:00:00Z',
      'updated_at' => '2026-09-06T00:00:00Z',
      'performed_via_github_app' => NULL,
      'body' => implode("\n", [
        '<!-- agency-human-prod-approval:v1 -->',
        'intent=APPROVE_THIS_EXACT_PREPROD_CANDIDATE_FOR_PROD',
        'candidate_revision=' . self::REVISION,
        'payload_sha256=' . self::SHA,
        'preprod_fr_url=' . self::FR_URL,
        'preprod_en_url=' . self::EN_URL,
        'language_mode=FR_EN',
      ]),
    ];
  }

  /**
   * Executes the approval validator with bounded fixture input.
   *
   * @param array<int, array<string, mixed>> $comments
   *   GitHub issue comments to expose to the validator.
   * @param array<string, string> $overrides
   *   Exact CLI argument overrides for one test scenario.
   */
  private function runGate(array $comments, array $overrides = []): int {
    $root = dirname(DRUPAL_ROOT);
    $script = $root . '/scripts/validation/editorial-human-approval.php';
    self::assertFileExists($script);

    $tmp = tempnam(sys_get_temp_dir(), 'agency-human-approval-');
    self::assertIsString($tmp);
    file_put_contents($tmp, json_encode($comments, JSON_THROW_ON_ERROR));

    $args = [
      '--comments' => $tmp,
      '--owner' => self::OWNER,
      '--candidate-revision' => self::REVISION,
      '--payload-sha256' => self::SHA,
      '--preprod-fr-url' => self::FR_URL,
      '--preprod-en-url' => self::EN_URL,
      '--language-mode' => 'FR_EN',
    ];
    foreach ($overrides as $name => $value) {
      $args[$name] = $value;
    }

    $command = 'php ' . escapeshellarg($script);
    foreach ($args as $name => $value) {
      $command .= ' ' . escapeshellarg($name) . '=' . escapeshellarg($value);
    }
    $command .= ' >/dev/null 2>&1';

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    @unlink($tmp);

    return $exitCode;
  }

}

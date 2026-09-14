<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Proves causal Service approval and post-approval dry-run selection.
 *
 * @group agency_project_tests
 * @group editorial_promotion_governance
 */
#[Group('editorial_promotion_governance')]
final class ServiceEditorialApprovalSelectionTest extends TestCase {

  private const ISSUE = 1117;
  private const REVISION = 'b4583c78ff6cfa083461d3394667887383135187';
  private const PAYLOAD_SHA = 'f8a8dcb23f46086ec6e0b7b3c6b8f26cd53fc7335c5239b97391f3ec1826804a';
  private const MAIN_SHA = 'af463fb3b378640dc13674a2ec4d8529d603b1ab';
  private const OLD_MAIN_SHA = '1b2166e9a111eb54650827033ad4306a0940482a';
  private const STALE_APPROVAL_ID = 5664680160;
  private const CURRENT_PREPROD_RECEIPT_ID = 5665275522;
  private const CURRENT_APPROVAL_ID = 5665300000;
  private const CURRENT_PROD_DRY_RUN_ID = 5665400000;

  /**
   * Historical pre-approval proof is ignored and the causal post proof wins.
   */
  public function testPreAndPostApprovalDryRunSelectsPostOnly(): void {
    [$exitCode, $result] = $this->runValidator([
      $this->preprodReceipt(100),
      $this->dryRunReceipt(150),
      $this->approvalComment(200),
      $this->dryRunReceipt(300),
    ]);

    self::assertSame(0, $exitCode);
    self::assertSame('AUTHORIZED', $result['verdict'] ?? NULL);
    self::assertSame(200, $result['approval_comment_id'] ?? NULL);
    self::assertSame(300, $result['prod_dry_run_comment_id'] ?? NULL);
  }

  /**
   * A stale same-main approval before current PREPROD evidence is ignored.
   */
  public function testStaleSameMainApprovalBeforePreprodSelectsCurrentApproval(): void {
    [$exitCode, $result] = $this->runValidator([
      $this->approvalComment(self::STALE_APPROVAL_ID),
      $this->preprodReceipt(self::CURRENT_PREPROD_RECEIPT_ID),
      $this->approvalComment(self::CURRENT_APPROVAL_ID),
      $this->dryRunReceipt(self::CURRENT_PROD_DRY_RUN_ID),
    ]);

    self::assertLessThan(self::CURRENT_PREPROD_RECEIPT_ID, self::STALE_APPROVAL_ID);
    self::assertLessThan(self::CURRENT_APPROVAL_ID, self::CURRENT_PREPROD_RECEIPT_ID);
    self::assertLessThan(self::CURRENT_PROD_DRY_RUN_ID, self::CURRENT_APPROVAL_ID);
    self::assertSame(0, $exitCode);
    self::assertSame('AUTHORIZED', $result['verdict'] ?? NULL);
    self::assertSame(self::CURRENT_APPROVAL_ID, $result['approval_comment_id'] ?? NULL);
    self::assertSame(self::CURRENT_PROD_DRY_RUN_ID, $result['prod_dry_run_comment_id'] ?? NULL);
  }

  /**
   * A same-main approval that predates current PREPROD evidence is insufficient.
   */
  public function testOnlyStaleSameMainApprovalBeforePreprodIsRefused(): void {
    [$exitCode] = $this->runValidator([
      $this->approvalComment(self::STALE_APPROVAL_ID),
      $this->preprodReceipt(self::CURRENT_PREPROD_RECEIPT_ID),
      $this->dryRunReceipt(self::CURRENT_PROD_DRY_RUN_ID),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * An exact dry-run that predates the exact approval is insufficient.
   */
  public function testOnlyPreApprovalDryRunIsRefused(): void {
    [$exitCode] = $this->runValidator([
      $this->preprodReceipt(100),
      $this->dryRunReceipt(150),
      $this->approvalComment(200),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * Historical approval for an older main does not ambiguate current authority.
   */
  public function testOldMainApprovalDoesNotAmbiguateCurrentMain(): void {
    [$exitCode, $result] = $this->runValidator([
      $this->preprodReceipt(100),
      $this->approvalComment(150, self::OLD_MAIN_SHA),
      $this->approvalComment(200),
      $this->dryRunReceipt(300),
    ]);

    self::assertSame(0, $exitCode);
    self::assertSame(200, $result['approval_comment_id'] ?? NULL);
    self::assertSame(300, $result['prod_dry_run_comment_id'] ?? NULL);
  }

  /**
   * No direct exact approval for current main remains fail-closed.
   */
  public function testNoCurrentExactApprovalIsRefused(): void {
    [$exitCode] = $this->runValidator([
      $this->preprodReceipt(100),
      $this->approvalComment(150, self::OLD_MAIN_SHA),
      $this->dryRunReceipt(300),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * GitHub App-mediated owner authority remains forbidden.
   */
  public function testAppAuthoredApprovalIsRefused(): void {
    $approval = $this->approvalComment(200);
    $approval['performed_via_github_app'] = [
      'id' => 1144995,
      'slug' => 'chatgpt-codex-connector',
    ];

    [$exitCode] = $this->runValidator([
      $this->preprodReceipt(100),
      $approval,
      $this->dryRunReceipt(300),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * Stale candidate authority remains forbidden.
   */
  public function testStaleCandidateApprovalIsRefused(): void {
    $approval = $this->approvalComment(200);
    $approval['body'] = str_replace(
      self::REVISION,
      str_repeat('b', 40),
      (string) $approval['body'],
    );

    [$exitCode] = $this->runValidator([
      $this->preprodReceipt(100),
      $approval,
      $this->dryRunReceipt(300),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * Wrong candidate id remains fail-closed.
   */
  public function testWrongCandidateApprovalIsRefused(): void {
    $approval = $this->approvalComment(200);
    $approval['body'] = str_replace(
      'CANDIDATE_ID = agency-service-1117',
      'CANDIDATE_ID = agency-service-9999',
      (string) $approval['body'],
    );

    [$exitCode] = $this->runValidator([
      $this->preprodReceipt(100),
      $approval,
      $this->dryRunReceipt(300),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * Wrong payload hash remains fail-closed.
   */
  public function testWrongPayloadApprovalIsRefused(): void {
    $approval = $this->approvalComment(200);
    $approval['body'] = str_replace(
      self::PAYLOAD_SHA,
      str_repeat('a', 64),
      (string) $approval['body'],
    );

    [$exitCode] = $this->runValidator([
      $this->preprodReceipt(100),
      $approval,
      $this->dryRunReceipt(300),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * Multiple exact current approvals are ambiguous and fail closed.
   */
  public function testDuplicateCurrentExactApprovalIsRefused(): void {
    [$exitCode] = $this->runValidator([
      $this->preprodReceipt(100),
      $this->approvalComment(200),
      $this->approvalComment(210),
      $this->dryRunReceipt(300),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * Multiple exact post-approval dry-runs are ambiguous and fail closed.
   */
  public function testDuplicatePostApprovalDryRunIsRefused(): void {
    [$exitCode] = $this->runValidator([
      $this->preprodReceipt(100),
      $this->approvalComment(200),
      $this->dryRunReceipt(300),
      $this->dryRunReceipt(310),
    ]);

    self::assertSame(1, $exitCode);
  }

  /**
   * Builds exact immutable PREPROD Service evidence.
   *
   * @return array<string, mixed>
   *   Bot receipt fixture.
   */
  private function preprodReceipt(int $id): array {
    return $this->botComment($id, implode("\n", [
      '### Agency editorial PREPROD candidate apply PASS',
      '',
      'target: `PREPROD`',
      'candidate_id: `agency-service-1117`',
      'candidate_revision: `' . self::REVISION . '`',
      'payload_sha256: `' . self::PAYLOAD_SHA . '`',
      'trusted_main: `' . self::MAIN_SHA . '`',
      'run_id: `34349261619`',
      'verdict: `REPAIRED`',
      'node_id: `41`',
      'revision_id: `48`',
      'prod_write: `NONE`',
    ]));
  }

  /**
   * Builds one exact current-identity PROD dry-run receipt.
   *
   * @return array<string, mixed>
   *   Bot receipt fixture.
   */
  private function dryRunReceipt(int $id): array {
    return $this->botComment($id, implode("\n", [
      '### Agency editorial dry-run PASS',
      '',
      'candidate_kind: `service`',
      'candidate_id: `agency-service-1117`',
      'candidate_revision: `' . self::REVISION . '`',
      'payload_sha256: `' . self::PAYLOAD_SHA . '`',
      'trusted_main: `' . self::MAIN_SHA . '`',
      'run_id: `' . $id . '`',
      'route_outcome: `success`',
      'verdict: `READY`',
    ]));
  }

  /**
   * Builds one direct owner approval for a specific trusted main.
   *
   * @return array<string, mixed>
   *   Human approval fixture.
   */
  private function approvalComment(int $id, string $main = self::MAIN_SHA): array {
    return [
      'id' => $id,
      'user' => [
        'login' => 'E-merging-digital',
        'type' => 'User',
      ],
      'author_association' => 'OWNER',
      'performed_via_github_app' => NULL,
      'body' => implode("\n", [
        '## PROJECT LEAD — HUMAN APPROVAL / exact #1117 candidate approved for PROD promotion',
        '',
        'CANDIDATE_ID = agency-service-1117',
        'CANDIDATE_REVISION = ' . self::REVISION,
        'PAYLOAD_SHA256 = ' . self::PAYLOAD_SHA,
        'TRUSTED_MAIN = ' . $main,
        'PREPROD_NODE_ID = 41',
        'PREPROD_REVISION_ID = 48',
        'FR_PREPROD_URL = https://preprod.emergingdigital.be/fr/audit-site-web',
        'EN_PREPROD_URL = https://preprod.emergingdigital.be/en/website-audit',
        'HUMAN_REVIEW = PASS',
        'CONTENT = APPROVED',
        'EXACT_CANDIDATE_PROMOTION_TO_PROD = AUTHORIZED',
        'CONTENT_CHANGE_AFTER_APPROVAL = INVALIDATES_APPROVAL',
      ]),
    ];
  }

  /**
   * Runs the repository validator against bounded comment fixtures.
   *
   * @param array<int, array<string, mixed>> $comments
   *   GitHub comments exposed to the validator.
   *
   * @return array{0:int,1:array<string,mixed>}
   *   Exit code and decoded result.
   */
  private function runValidator(array $comments): array {
    $dir = sys_get_temp_dir() . '/agency-service-selection-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($dir, 0700, TRUE));
    $commentsPath = $dir . '/comments.b64';
    $resultPath = $dir . '/result.json';
    $encoded = [];
    foreach ($comments as $comment) {
      $encoded[] = base64_encode((string) json_encode($comment, JSON_UNESCAPED_SLASHES));
    }
    file_put_contents($commentsPath, implode("\n", $encoded) . "\n");

    $script = dirname(DRUPAL_ROOT)
      . '/scripts/runner/validate-editorial-promotion-approval.py';
    $command = implode(' ', [
      'python3', escapeshellarg($script),
      '--candidate-kind', 'service',
      '--comments-b64', escapeshellarg($commentsPath),
      '--issue-number', (string) self::ISSUE,
      '--candidate-revision', self::REVISION,
      '--payload-sha256', self::PAYLOAD_SHA,
      '--trusted-main', self::MAIN_SHA,
      '--output', escapeshellarg($resultPath),
      '2>&1',
    ]);
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    $result = [];
    if (is_file($resultPath)) {
      $decoded = json_decode((string) file_get_contents($resultPath), TRUE);
      if (is_array($decoded)) {
        $result = $decoded;
      }
    }

    @unlink($commentsPath);
    @unlink($resultPath);
    @rmdir($dir);
    return [$exitCode, $result];
  }

  /**
   * Builds one GitHub Actions bot receipt comment.
   *
   * @return array<string, mixed>
   *   Receipt fixture.
   */
  private function botComment(int $id, string $body): array {
    return [
      'id' => $id,
      'user' => ['login' => 'github-actions[bot]'],
      'author_association' => 'CONTRIBUTOR',
      'body' => $body,
    ];
  }

}

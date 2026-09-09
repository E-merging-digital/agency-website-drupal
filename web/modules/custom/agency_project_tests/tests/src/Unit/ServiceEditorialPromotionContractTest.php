<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the explicit #1117 Service extension of governed PROD editorial.
 *
 * @group agency_project_tests
 * @group editorial_promotion_governance
 */
#[Group('editorial_promotion_governance')]
final class ServiceEditorialPromotionContractTest extends TestCase {

  private const ISSUE = 1117;
  private const REVISION = 'b4583c78ff6cfa083461d3394667887383135187';
  private const PAYLOAD_SHA = 'f8a8dcb23f46086ec6e0b7b3c6b8f26cd53fc7335c5239b97391f3ec1826804a';
  private const MAIN_SHA = 'b8717d68778abb043f6cae3de4da289a00563720';

  /**
   * Service reuses the route without weakening the existing Article branch.
   */
  public function testWorkflowIsExplicitServiceExtensionAndArticleRegression(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-editorial-publication.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));
    $workflow = (string) file_get_contents($path);

    self::assertStringContainsString("candidate_kind='service'", $workflow);
    self::assertStringContainsString(
      "candidate_source='docs/seo/audit-site-web-candidate-1117.md'",
      $workflow,
    );
    self::assertStringContainsString(
      'editorial-service-candidate-source.py',
      $workflow,
    );
    self::assertStringContainsString(
      'run-editorial-service-publication.sh',
      $workflow,
    );
    self::assertStringContainsString(
      '.image_waiver == "NOT_REQUIRED"',
      $workflow,
    );
    self::assertStringContainsString(
      "payload.get('bundle') != 'article'",
      $workflow,
    );
    self::assertStringContainsString(
      'Article promotion requires an exact repository-owned image profile.',
      $workflow,
    );
    self::assertStringContainsString(
      'run-editorial-promotion.sh',
      $workflow,
    );
    self::assertStringContainsString(
      '.image_waiver == "UNSUPPORTED"',
      $workflow,
    );
    self::assertStringContainsString(
      'Live main changed after workflow start; approval is stale.',
      $workflow,
    );
    self::assertStringContainsString(
      'Candidate revision changed after workflow start.',
      $workflow,
    );
    self::assertStringContainsString(
      'Candidate payload changed after workflow start.',
      $workflow,
    );
  }

  /**
   * Direct owner approval is mandatory and stale/App authority fails closed.
   */
  public function testServiceApprovalRequiresDirectOwnerAndFreshDryRun(): void {
    $fixture = $this->commentsFixture();
    [$exitCode, $result] = $this->runValidator($fixture);
    self::assertSame(0, $exitCode);
    self::assertSame('AUTHORIZED', $result['verdict'] ?? NULL);
    self::assertSame('agency-service-1117', $result['candidate_id'] ?? NULL);
    self::assertSame('NOT_REQUIRED', $result['image_waiver'] ?? NULL);

    $mutations = [
      'GitHub App approval' => static function (array &$comments): void {
        $comments[1]['performed_via_github_app'] = [
          'id' => 1144995,
          'slug' => 'chatgpt-codex-connector',
        ];
      },
      'bot-looking approval' => static function (array &$comments): void {
        $comments[1]['user']['type'] = 'Bot';
      },
      'stale approval' => static function (array &$comments): void {
        $comments[1]['id'] = 90;
      },
      'pre-approval PROD dry-run' => static function (array &$comments): void {
        $comments[2]['id'] = 190;
      },
      'stale main' => static function (array &$comments): void {
        $comments[1]['body'] = str_replace(
          self::MAIN_SHA,
          str_repeat('a', 40),
          $comments[1]['body'],
        );
      },
      'stale candidate' => static function (array &$comments): void {
        $comments[1]['body'] = str_replace(
          self::REVISION,
          str_repeat('b', 40),
          $comments[1]['body'],
        );
      },
    ];

    foreach ($mutations as $name => $mutate) {
      $comments = $this->commentsFixture();
      $mutate($comments);
      [$mutatedExit] = $this->runValidator($comments);
      self::assertSame(1, $mutatedExit, $name);
    }
  }

  /**
   * Service uses the exact Git candidate, routes and a bounded Entity API writer.
   */
  public function testServiceCandidateIdentityAndWriterAreBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $source = $root . '/docs/seo/audit-site-web-candidate-1117.md';
    $parser = $root . '/scripts/runner/editorial-service-candidate-source.py';
    $runner = $root . '/scripts/runner/run-editorial-service-publication.sh';
    $publisher = $root . '/scripts/runner/editorial-service-publication.php';
    $runtime = $root . '/scripts/runner/editorial-service-publication-runtime.php';
    foreach ([$source, $parser, $runner, $publisher, $runtime] as $path) {
      self::assertFileExists($path);
    }

    $dir = sys_get_temp_dir() . '/agency-service-prod-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($dir, 0700, TRUE));
    $payload = $dir . '/payload.json';
    $output = [];
    $exitCode = 0;
    exec(implode(' ', [
      'python3', escapeshellarg($parser),
      '--source', escapeshellarg($source),
      '--issue-number', (string) self::ISSUE,
      '--output', escapeshellarg($payload),
      '2>&1',
    ]), $output, $exitCode);
    self::assertSame(0, $exitCode, implode("\n", $output));
    self::assertSame(self::PAYLOAD_SHA, trim(implode("\n", $output)));

    $revisionOutput = [];
    $revisionExit = 0;
    exec(
      'git -C ' . escapeshellarg($root)
      . ' rev-parse HEAD:docs/seo/audit-site-web-candidate-1117.md 2>&1',
      $revisionOutput,
      $revisionExit,
    );
    self::assertSame(0, $revisionExit, implode("\n", $revisionOutput));
    self::assertSame(self::REVISION, trim(implode("\n", $revisionOutput)));

    $payloadData = json_decode((string) file_get_contents($payload), TRUE);
    self::assertIsArray($payloadData);
    self::assertSame(
      ['fr' => '/fr/audit-site-web', 'en' => '/en/website-audit'],
      $payloadData['public_routes'],
    );
    self::assertSame(
      ['fr' => '/audit-site-web', 'en' => '/website-audit'],
      $payloadData['stored_aliases'],
    );

    $publisherText = (string) file_get_contents($publisher);
    $runnerText = (string) file_get_contents($runner);
    self::assertStringContainsString("private const BUNDLE = 'service'", $publisherText);
    self::assertStringContainsString('SUPPORTED_ISSUE = 1117', $publisherText);
    self::assertStringContainsString('agency_editorial.service.issue.', $publisherText);
    self::assertStringContainsString("'pathauto' => 0", $publisherText);
    self::assertStringContainsString("'content_sync' => 'NONE'", $publisherText);
    self::assertStringContainsString("'db_copy' => 'NONE'", $publisherText);
    self::assertStringContainsString("'service_image_profile_required' => 'NO'", $publisherText);
    self::assertStringNotContainsString('field_feature_image', $publisherText . $runnerText);
    self::assertStringNotContainsString('editorial-feature-image', $publisherText . $runnerText);
    self::assertStringNotContainsString('PREPROD_SERVER', $publisherText . $runnerText);
    self::assertStringNotContainsString('drush cim', $runnerText);
    self::assertStringNotContainsString('drush updb', $runnerText);

    foreach ([
      ['bash', '-n', $runner],
      ['php', '-l', $publisher],
      ['php', '-l', $runtime],
    ] as $commandParts) {
      $command = implode(' ', array_map('escapeshellarg', $commandParts)) . ' 2>&1';
      $lintOutput = [];
      $lintExit = 0;
      exec($command, $lintOutput, $lintExit);
      self::assertSame(0, $lintExit, implode("\n", $lintOutput));
    }

    @unlink($payload);
    @rmdir($dir);
  }

  /**
   * Builds exact #1117 PREPROD, human approval and PROD dry-run evidence.
   *
   * @return array<int, array<string, mixed>>
   *   Comment fixtures.
   */
  private function commentsFixture(): array {
    return [
      $this->botComment(100, implode("\n", [
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
      ])),
      [
        'id' => 200,
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
          'TRUSTED_MAIN = ' . self::MAIN_SHA,
          'PREPROD_NODE_ID = 41',
          'PREPROD_REVISION_ID = 48',
          'FR_PREPROD_URL = https://preprod.emergingdigital.be/fr/audit-site-web',
          'EN_PREPROD_URL = https://preprod.emergingdigital.be/en/website-audit',
          'HUMAN_REVIEW = PASS',
          'CONTENT = APPROVED',
          'EXACT_CANDIDATE_PROMOTION_TO_PROD = AUTHORIZED',
          'CONTENT_CHANGE_AFTER_APPROVAL = INVALIDATES_APPROVAL',
        ]),
      ],
      $this->botComment(300, implode("\n", [
        '### Agency editorial dry-run PASS',
        '',
        'candidate_kind: `service`',
        'candidate_id: `agency-service-1117`',
        'candidate_revision: `' . self::REVISION . '`',
        'payload_sha256: `' . self::PAYLOAD_SHA . '`',
        'trusted_main: `' . self::MAIN_SHA . '`',
        'run_id: `999`',
        'route_outcome: `success`',
        'verdict: `READY`',
      ])),
    ];
  }

  /**
   * Runs the direct-human approval validator against synthetic comments.
   *
   * @param array<int, array<string, mixed>> $comments
   *   Comment fixtures.
   *
   * @return array{0:int,1:array<string,mixed>}
   *   Exit code and decoded result.
   */
  private function runValidator(array $comments): array {
    $dir = sys_get_temp_dir() . '/agency-service-approval-' . bin2hex(random_bytes(6));
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
   * Builds one exact bot-authored receipt comment.
   *
   * @return array<string, mixed>
   *   Comment fixture.
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

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the exact PREPROD-first Article promotion approval parser.
 *
 * @group agency_project_tests
 * @group editorial_promotion_governance
 */
#[Group('editorial_promotion_governance')]
final class EditorialPromotionApprovalTest extends TestCase {

  private const ISSUE = 999;
  private const CANDIDATE_REVISION = 100;
  private const PAYLOAD_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
  private const MAIN_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

  /**
   * Exact PREPROD, image, human approval and fresh PROD dry-run are accepted.
   */
  public function testExactApprovedCandidateIsPromotable(): void {
    $fixture = $this->fixture();
    [$exitCode, $result] = $this->runValidator($fixture);

    self::assertSame(0, $exitCode);
    self::assertSame('PASS', $result['status']);
    self::assertSame('AUTHORIZED', $result['verdict']);
    self::assertSame('agency-article-999', $result['candidate_id']);
    self::assertSame('UNSUPPORTED', $result['image_waiver']);
  }

  /**
   * The promoted image helper must not execute its standalone CLI entrypoint.
   */
  public function testPromotionImportsImageHelperInLibraryMode(): void {
    $runtime = (string) file_get_contents(dirname(DRUPAL_ROOT) . '/scripts/runner/editorial-promotion-runtime.php');
    $guard = strpos($runtime, "define('AGENCY_EDITORIAL_FEATURE_IMAGE_LIBRARY_ONLY', TRUE);");
    $include = strpos($runtime, 'require_once $imageLibrary;');

    self::assertIsInt($guard, 'Image library-only guard must be set.');
    self::assertIsInt($include, 'Promotion must import the image library.');
    self::assertLessThan($include, $guard, 'Image library-only guard must precede import.');
  }

  /**
   * A fresh direct owner approval supersedes a historical one without deletion.
   */
  public function testFreshOwnerReapprovalPreservesHistoricalApproval(): void {
    $fixture = $this->fixture();
    $historical = $fixture['comments'][3];
    $historical['id'] = 90;
    $fixture['comments'][] = $historical;

    [$exitCode, $result] = $this->runValidator($fixture);
    self::assertSame(0, $exitCode);
    self::assertSame('AUTHORIZED', $result['verdict']);
    self::assertSame(140, $result['approval_comment_id']);
  }

  /**
   * A newer invalid owner approval must fail, not fall back to an older one.
   */
  public function testNewerInvalidOwnerApprovalCannotFallBack(): void {
    $fixture = $this->fixture();
    $invalid = $fixture['comments'][3];
    $invalid['id'] = 145;
    $invalid['body'] = str_replace('CONTENT = APPROVED', 'CONTENT = REJECTED', $invalid['body']);
    $fixture['comments'][] = $invalid;

    [$exitCode] = $this->runValidator($fixture);
    self::assertSame(1, $exitCode);
  }

  /**
   * Only the existing #1491 photo may use JPEG; all other profiles stay PNG.
   */
  public function testIssueBoundJpegException(): void {
    foreach ([
      [1491, 'image/jpeg', 0],
      [1491, 'image/png', 1],
      [1512, 'image/jpeg', 1],
      [1512, 'image/png', 0],
    ] as [$issue, $mime, $expectedExit]) {
      $fixture = $this->fixture();
      $profile = $fixture['registry']['profiles']['999'];
      $oldHash = hash('sha256', $this->canonicalJson($profile));
      $profile['issue_number'] = $issue;
      $profile['asset']['mime'] = $mime;
      $newHash = hash('sha256', $this->canonicalJson($profile));
      $fixture['registry']['profiles'] = [(string) $issue => $profile];
      foreach ($fixture['comments'] as &$comment) {
        $comment['body'] = str_replace(
          ['999', $oldHash],
          [(string) $issue, $newHash],
          $comment['body'],
        );
      }
      unset($comment);

      [$exitCode] = $this->runValidator($fixture, $issue);
      self::assertSame($expectedExit, $exitCode, "Issue #$issue ($mime)");
    }
  }

  /**
   * Accept exact historical MCP evidence.
   *
   * The old PREPROD main is distinct from the fresh PROD main.
   */
  public function testMcpWebpPromotionUsesExactHistoricEvidence(): void {
    $fixture = $this->mcpFixture();
    [$exitCode, $result] = $this->runValidator($fixture, 1404);
    self::assertSame(0, $exitCode);
    self::assertSame('AUTHORIZED', $result['verdict']);
    self::assertSame(6110961556, $result['candidate_revision']);
    self::assertSame(42, $result['preprod_node_id']);
    self::assertSame(65, $result['preprod_revision_id']);
    self::assertSame('0c504f2f72d1fa9cb6569c404fd9d0ae0853d3cfdc49ae422076b19c3bcd24c6', $result['asset_sha256']);
  }

  /**
   * Reject malformed or stale MCP approval evidence.
   */
  public function testMcpWebpGovernanceFailsClosed(): void {
    $mutations = [
      'wrong MIME' => static function (array &$f): void {
        $f['registry']['profiles']['1404']['asset']['mime'] = 'image/png';
      },
      'wrong WebP bytes' => static function (array &$f): void {
        $f['asset'] = 'wrong WebP bytes';
      },
      'wrong ALT' => static function (array &$f): void {
        $f['registry']['profiles']['1404']['alt']['en'] = 'Unapproved English ALT';
      },
      'wrong candidate' => static function (array &$f): void {
        $f['candidate_revision'] = 6110961557;
      },
      'missing Article receipt' => static function (array &$f): void {
        unset($f['comments'][0]);
      },
      'unrelated Article receipt main' => static function (array &$f): void {
        $f['comments'][0]['body'] = str_replace('14cbc02945c37a72732b2b7a05b16593f79848e6', str_repeat('f', 40), $f['comments'][0]['body']);
      },
      'wrong image FID' => static function (array &$f): void {
        $f['comments'][1]['body'] = str_replace('fid: `12`', 'fid: `13`', $f['comments'][1]['body']);
      },
      'wrong image revision' => static function (array &$f): void {
        $f['comments'][1]['body'] = str_replace('revision_id: `65`', 'revision_id: `66`', $f['comments'][1]['body']);
      },
      'image receipt not from bot' => static function (array &$f): void {
        $f['comments'][1]['user']['login'] = 'E-merging-digital';
      },
      'missing human approval' => static function (array &$f): void {
        unset($f['comments'][2]);
      },
      'stale approval' => static function (array &$f): void {
        $f['comments'][2]['id'] = 6780534836;
      },
      'wrong owner identity' => static function (array &$f): void {
        $f['comments'][2]['user']['type'] = 'Bot';
      },
      'missing image approval' => static function (array &$f): void {
        $f['comments'][2]['body'] = str_replace("ALT_FR_EN = APPROVED\n", '', $f['comments'][2]['body']);
      },
      'stale current main in PROD dry-run' => static function (array &$f): void {
        $f['comments'][3]['body'] = str_replace(self::MAIN_SHA, str_repeat('d', 40), $f['comments'][3]['body']);
      },
      'dry-run before human approval' => static function (array &$f): void {
        $f['comments'][3]['id'] = 6781000000;
      },
    ];
    foreach ($mutations as $name => $mutate) {
      $fixture = $this->mcpFixture();
      $mutate($fixture);
      [$exitCode] = $this->runValidator($fixture, 1404);
      self::assertSame(1, $exitCode, $name);
    }
  }

  /**
   * Exact repository WebP with immutable historic and fresh synthetic receipts.
   */
  private function mcpFixture(): array {
    $root = dirname(DRUPAL_ROOT);
    $registry = json_decode((string) file_get_contents($root . '/scripts/runner/editorial-feature-image-profiles.json'), TRUE, 32, JSON_THROW_ON_ERROR);
    $profile = $registry['profiles']['1404'];
    $asset = (string) file_get_contents($root . '/' . $profile['asset']['path']);
    self::assertNotSame('', $asset);
    $profileSha = hash('sha256', $this->canonicalJson($profile));
    $assetSha = hash('sha256', $asset);
    $payload = $profile['article_payload_sha256'];
    $candidateRevision = 6110961556;
    $approvalId = 6781000001;
    $comments = [
      $this->botComment(6111077035, implode("\n", [
        '### Agency editorial PREPROD candidate apply PASS',
        'target: `PREPROD`',
        'candidate_id: `agency-article-1404`',
        'candidate_revision: `6110961556`',
        'payload_sha256: `' . $payload . '`',
        'trusted_main: `14cbc02945c37a72732b2b7a05b16593f79848e6`',
        'run_id: `38154962644`',
        'node_id: `42`',
        'revision_id: `49`',
        'prod_write: `NONE`',
      ])),
      $this->botComment(6780534837, implode("\n", [
        '### Agency editorial image apply PASS',
        'profile_sha256: `' . $profileSha . '`',
        'asset_sha256: `' . $assetSha . '`',
        'trusted_main: `30cb29b7c4666da92fe1315be9a2bf0b30e828a2`',
        'target: `PREPROD`',
        'run_id: `42911099314`',
        'route_outcome: `success`',
        'verdict: `REPAIRED`',
        'node_id: `42`',
        'revision_id: `65`',
        'fid: `12`',
        'uri: `public://articles/agency-mcp-permissions-editorial-v1.webp`',
        'prod_write: `NONE`',
      ])),
      [
        'id' => $approvalId,
        'user' => ['login' => 'E-merging-digital', 'type' => 'User'],
        'author_association' => 'OWNER',
        'performed_via_github_app' => NULL,
        'body' => implode("\n", [
          '## PROJECT LEAD — HUMAN APPROVAL / exact #1404 candidate approved for PROD promotion',
          'CANDIDATE_ID = agency-article-1404',
          'CANDIDATE_REVISION = 6110961556',
          'ARTICLE_PAYLOAD_SHA256 = ' . $payload,
          'PREPROD_ARTICLE_APPLY = 38154962644 / SUCCESS',
          'PREPROD_NODE_ID = 42',
          'PREPROD_ARTICLE_REVISION_AFTER_IMAGE = 65',
          'IMAGE_PROFILE_SHA256 = ' . $profileSha,
          'IMAGE_ASSET_SHA256 = ' . $assetSha,
          'PREPROD_IMAGE_APPLY = 42911099314 / SUCCESS',
          'Approved rendered URLs:',
          '- FR: `https://preprod.emergingdigital.be/fr/blog/comment-connecter-une-application-metier-un-agent-ia-grace-mcp-sans-lui-donner-tous-les-droits`',
          '- EN: `https://preprod.emergingdigital.be/en/blog/how-connect-business-application-ai-agent-mcp-without-giving-it-full-access`',
          'HUMAN_REVIEW = PASS',
          'CONTENT = APPROVED',
          'IMAGE = APPROVED',
          'ALT_FR_EN = APPROVED',
          'IMAGE_SOURCE_POLICY = APPROVED',
          'RESPONSIVE_RENDER = APPROVED',
          'LISTING_DETAIL_RENDER = APPROVED',
          'EXACT_CANDIDATE_PROMOTION_TO_PROD = AUTHORIZED',
          'CONTENT_CHANGE_AFTER_APPROVAL = INVALIDATES_APPROVAL',
          'IMAGE_CHANGE_AFTER_APPROVAL = INVALIDATES_APPROVAL',
        ]),
      ],
      $this->botComment(6781000002, implode("\n", [
        '### Agency editorial dry-run PASS',
        'payload_sha256: `' . $payload . '`',
        'trusted_main: `' . self::MAIN_SHA . '`',
        'run_id: `50000000000`',
        'route_outcome: `success`',
        'verdict: `READY`',
      ])),
    ];
    return [
      'comments' => $comments,
      'registry' => ['schema_version' => 1, 'profiles' => ['1404' => $profile]],
      'asset' => $asset,
      'candidate_revision' => $candidateRevision,
      'payload_sha' => $payload,
      'trusted_main' => self::MAIN_SHA,
    ];
  }

  /**
   * Missing, stale or non-human authority components fail closed before PROD.
   */
  public function testGovernanceBypassesAreRefused(): void {
    $mutations = [
      'missing PREPROD Article' => static function (array &$fixture): void {
        unset($fixture['comments'][0]);
      },
      'missing human approval' => static function (array &$fixture): void {
        unset($fixture['comments'][3]);
      },
      'wrong candidate revision' => static function (array &$fixture): void {
        $fixture['comments'][3]['body'] = str_replace(
          'CANDIDATE_REVISION = 100',
          'CANDIDATE_REVISION = 101',
          $fixture['comments'][3]['body'],
        );
      },
      'wrong payload hash' => static function (array &$fixture): void {
        $fixture['comments'][3]['body'] = str_replace(
          self::PAYLOAD_SHA,
          str_repeat('c', 64),
          $fixture['comments'][3]['body'],
        );
      },
      'stale PREPROD main' => static function (array &$fixture): void {
        $fixture['comments'][0]['body'] = str_replace(
          self::MAIN_SHA,
          str_repeat('d', 40),
          $fixture['comments'][0]['body'],
        );
      },
      'approval predates evidence' => static function (array &$fixture): void {
        $fixture['comments'][3]['id'] = 115;
      },
      'PROD dry-run predates approval' => static function (array &$fixture): void {
        $fixture['comments'][4]['id'] = 135;
      },
      'missing image profile' => static function (array &$fixture): void {
        $fixture['registry']['profiles'] = [];
      },
      'image asset drift' => static function (array &$fixture): void {
        $fixture['asset'] = 'different asset bytes';
      },
      'ALT approval missing' => static function (array &$fixture): void {
        $fixture['comments'][3]['body'] = str_replace(
          "ALT_FR_EN = APPROVED\n",
          '',
          $fixture['comments'][3]['body'],
        );
      },
      'source policy approval missing' => static function (array &$fixture): void {
        $fixture['comments'][3]['body'] = str_replace(
          "IMAGE_SOURCE_POLICY = APPROVED\n",
          '',
          $fixture['comments'][3]['body'],
        );
      },
      'responsive review missing' => static function (array &$fixture): void {
        $fixture['comments'][3]['body'] = str_replace(
          "RESPONSIVE_RENDER = APPROVED\n",
          '',
          $fixture['comments'][3]['body'],
        );
      },
      'listing/detail review missing' => static function (array &$fixture): void {
        $fixture['comments'][3]['body'] = str_replace(
          "LISTING_DETAIL_RENDER = APPROVED\n",
          '',
          $fixture['comments'][3]['body'],
        );
      },
      'human-looking non-owner approval' => static function (array &$fixture): void {
        $fixture['comments'][3]['author_association'] = 'MEMBER';
      },
      'owner approval performed via GitHub App' => static function (array &$fixture): void {
        $fixture['comments'][3]['performed_via_github_app'] = [
          'id' => 1144995,
          'slug' => 'chatgpt-codex-connector',
        ];
      },
      'owner-looking bot identity' => static function (array &$fixture): void {
        $fixture['comments'][3]['user']['type'] = 'Bot';
      },
      'wrong approval heading' => static function (array &$fixture): void {
        $fixture['comments'][3]['body'] = str_replace(
          '## PROJECT LEAD — HUMAN APPROVAL / exact #999 candidate approved for PROD promotion',
          '## HUMAN APPROVAL',
          $fixture['comments'][3]['body'],
        );
      },
    ];

    foreach ($mutations as $name => $mutate) {
      $fixture = $this->fixture();
      $mutate($fixture);
      [$exitCode] = $this->runValidator($fixture);
      self::assertSame(1, $exitCode, $name);
    }
  }

  /**
   * Builds exact synthetic evidence with the same receipt shapes as runtime.
   *
   * @return array<string, mixed>
   *   Fixture inputs.
   */
  private function fixture(): array {
    $asset = 'exact approved image bytes';
    $assetSha = hash('sha256', $asset);
    $profile = [
      'issue_number' => self::ISSUE,
      'bundle' => 'article',
      'article_payload_sha256' => self::PAYLOAD_SHA,
      'field_name' => 'field_feature_image',
      'asset' => [
        'path' => 'assets/editorial/issue-999.png',
        'filename' => 'issue-999.png',
        'sha256' => $assetSha,
        'mime' => 'image/png',
        'width' => 1200,
        'height' => 630,
        'max_bytes' => 2000000,
      ],
      'alt' => [
        'fr' => 'Illustration approuvée',
        'en' => 'Approved illustration',
      ],
    ];
    $profileSha = hash('sha256', $this->canonicalJson($profile));

    $comments = [
      $this->botComment(110, implode("\n", [
        '### Agency editorial PREPROD candidate apply PASS',
        '',
        'target: `PREPROD`',
        'candidate_id: `agency-article-999`',
        'candidate_revision: `100`',
        'payload_sha256: `' . self::PAYLOAD_SHA . '`',
        'trusted_main: `' . self::MAIN_SHA . '`',
        'run_id: `201`',
        'verdict: `APPLIED`',
        'node_id: `39`',
        'revision_id: `42`',
        'fr_url: `https://preprod.emergingdigital.be/blog/fr`',
        'en_url: `https://preprod.emergingdigital.be/blog/en`',
        'prod_write: `NONE`',
      ])),
      $this->botComment(120, implode("\n", [
        '### Agency editorial image apply PASS',
        '',
        'profile_sha256: `' . $profileSha . '`',
        'asset_sha256: `' . $assetSha . '`',
        'trusted_main: `' . self::MAIN_SHA . '`',
        'target: `PREPROD`',
        'run_id: `202`',
        'route_outcome: `success`',
        'verdict: `APPLIED`',
        'node_id: `39`',
        'revision_id: `43`',
        'prod_write: `NONE`',
      ])),
      $this->botComment(130, implode("\n", [
        '### Agency editorial image dry-run PASS',
        '',
        'profile_sha256: `' . $profileSha . '`',
        'asset_sha256: `' . $assetSha . '`',
        'trusted_main: `' . self::MAIN_SHA . '`',
        'target: `PREPROD`',
        'run_id: `203`',
        'route_outcome: `success`',
        'verdict: `IDEMPOTENT`',
        'node_id: `39`',
        'revision_id: `43`',
        'prod_write: `NONE`',
      ])),
      [
        'id' => 140,
        'user' => [
          'login' => 'E-merging-digital',
          'type' => 'User',
        ],
        'author_association' => 'OWNER',
        'performed_via_github_app' => NULL,
        'body' => implode("\n", [
          '## PROJECT LEAD — HUMAN APPROVAL / exact #999 candidate approved for PROD promotion',
          '',
          'CANDIDATE_ID = agency-article-999',
          'CANDIDATE_REVISION = 100',
          'ARTICLE_PAYLOAD_SHA256 = ' . self::PAYLOAD_SHA,
          'PREPROD_ARTICLE_APPLY = 201 / SUCCESS',
          'PREPROD_NODE_ID = 39',
          'PREPROD_ARTICLE_REVISION_AFTER_IMAGE = 43',
          'IMAGE_PROFILE_SHA256 = ' . $profileSha,
          'IMAGE_ASSET_SHA256 = ' . $assetSha,
          'PREPROD_IMAGE_APPLY = 202 / SUCCESS',
          'PREPROD_IMAGE_POST_APPLY_DRY_RUN = 203 / SUCCESS / IDEMPOTENT',
          '',
          'Approved rendered URLs:',
          '',
          '- FR: `https://preprod.emergingdigital.be/blog/fr`',
          '- EN: `https://preprod.emergingdigital.be/blog/en`',
          '',
          'HUMAN_REVIEW = PASS',
          'CONTENT = APPROVED',
          'IMAGE = APPROVED',
          'ALT_FR_EN = APPROVED',
          'IMAGE_SOURCE_POLICY = APPROVED',
          'RESPONSIVE_RENDER = APPROVED',
          'LISTING_DETAIL_RENDER = APPROVED',
          'EXACT_CANDIDATE_PROMOTION_TO_PROD = AUTHORIZED',
          'CONTENT_CHANGE_AFTER_APPROVAL = INVALIDATES_APPROVAL',
          'IMAGE_CHANGE_AFTER_APPROVAL = INVALIDATES_APPROVAL',
        ]),
      ],
      $this->botComment(150, implode("\n", [
        '### Agency editorial dry-run PASS',
        '',
        'payload_sha256: `' . self::PAYLOAD_SHA . '`',
        'trusted_main: `' . self::MAIN_SHA . '`',
        'run_id: `204`',
        'route_outcome: `success`',
        'verdict: `READY`',
      ])),
    ];

    return [
      'comments' => $comments,
      'registry' => [
        'schema_version' => 1,
        'profiles' => ['999' => $profile],
      ],
      'asset' => $asset,
    ];
  }

  /**
   * Runs the repository parser against a temporary fixture.
   *
   * @param array<string, mixed> $fixture
   *   Fixture inputs.
   * @param int $issue
   *   Issue number used by this approval fixture.
   *
   * @return array{0:int,1:array<string,mixed>}
   *   Exit code and decoded result when present.
   */
  private function runValidator(array $fixture, int $issue = self::ISSUE): array {
    $dir = sys_get_temp_dir() . '/agency-editorial-approval-' . bin2hex(random_bytes(6));
    self::assertTrue(mkdir($dir, 0700, TRUE));
    $commentsPath = $dir . '/comments.b64';
    $registryPath = $dir . '/profiles.json';
    $assetPath = $dir . '/asset.png';
    $resultPath = $dir . '/result.json';

    $encoded = [];
    foreach ($fixture['comments'] as $comment) {
      $encoded[] = base64_encode((string) json_encode($comment, JSON_UNESCAPED_SLASHES));
    }
    file_put_contents($commentsPath, implode("\n", $encoded) . "\n");
    file_put_contents(
      $registryPath,
      json_encode($fixture['registry'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    );
    file_put_contents($assetPath, $fixture['asset']);

    $script = dirname(DRUPAL_ROOT) . '/scripts/runner/validate-editorial-promotion-approval.py';
    $command = implode(' ', [
      'python3',
      escapeshellarg($script),
      '--comments-b64', escapeshellarg($commentsPath),
      '--issue-number', (string) $issue,
      '--candidate-revision', (string) ($fixture['candidate_revision'] ?? self::CANDIDATE_REVISION),
      '--payload-sha256', ($fixture['payload_sha'] ?? self::PAYLOAD_SHA),
      '--trusted-main', ($fixture['trusted_main'] ?? self::MAIN_SHA),
      '--profile-registry', escapeshellarg($registryPath),
      '--asset-path', escapeshellarg($assetPath),
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
    @unlink($registryPath);
    @unlink($assetPath);
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

  /**
   * Canonicalizes nested arrays with the same JSON rules as the gate.
   */
  private function canonicalJson(array $value): string {
    $normalize = static function (array $input) use (&$normalize): array {
      ksort($input);
      foreach ($input as &$item) {
        if (is_array($item)) {
          $item = $normalize($item);
        }
      }
      unset($item);
      return $input;
    };
    return (string) json_encode(
      $normalize($value),
      JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";
  }

}

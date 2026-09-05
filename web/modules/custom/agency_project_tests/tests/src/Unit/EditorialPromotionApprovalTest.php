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
   * Exact PREPROD + image + human approval + fresh PROD dry-run is accepted.
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
   * Missing or stale authority components fail closed before PROD.
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
        'user' => ['login' => 'E-merging-digital'],
        'author_association' => 'OWNER',
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
   *
   * @return array{0:int,1:array<string,mixed>}
   *   Exit code and decoded result when present.
   */
  private function runValidator(array $fixture): array {
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
      '--issue-number', (string) self::ISSUE,
      '--candidate-revision', (string) self::CANDIDATE_REVISION,
      '--payload-sha256', self::PAYLOAD_SHA,
      '--trusted-main', self::MAIN_SHA,
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

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the fixed #971 PREPROD source-file rehydration route.
 *
 * @group agency_project_tests
 * @group preprod_editorial_image_rehydrate_971
 */
final class PreprodEditorialImageRehydrate971WorkflowTest extends TestCase {

  /**
   * The workflow is issue-bound, PREPROD-only and non-operational on PRs.
   */
  public function testWorkflowAuthorityIsExactAndPreprodOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/preprod-editorial-image-rehydrate-971.yml';
    self::assertFileExists($path);
    $workflow = Yaml::parseFile($path);
    self::assertIsArray($workflow);

    $on = $workflow['on'];
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayHasKey('pull_request', $on);
    self::assertArrayNotHasKey('issue_comment', $on);

    $secrets = $on['workflow_call']['secrets'];
    self::assertIsArray($secrets);
    self::assertSame(
      ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($secrets),
    );

    $source = (string) file_get_contents($path);
    self::assertStringContainsString("github.event.issue.number == 971", $source);
    self::assertStringContainsString('/agency-preprod-image-rehydrate dry-run', $source);
    self::assertStringContainsString('/agency-preprod-image-rehydrate apply', $source);
    self::assertStringContainsString("github.event_name == 'pull_request'", $source);
    self::assertStringContainsString("github.event_name == 'issue_comment'", $source);
    self::assertStringContainsString('currently on live main', $source);
    self::assertStringContainsString('Apply requires a bot-authored #971 dry-run PASS', $source);
    self::assertStringNotContainsString('workflow_dispatch:', $source);
  }

  /**
   * The existing #584 profile and deterministic #401 bytes are reused exactly.
   */
  public function testExistingProfileAndAssetHashAreReusedExactly(): void {
    $root = dirname(DRUPAL_ROOT);
    $registry = json_decode(
      (string) file_get_contents($root . '/scripts/runner/editorial-feature-image-profiles.json'),
      TRUE,
      16,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($registry);
    $profile = $registry['profiles']['401'];
    self::assertSame(401, $profile['issue_number']);
    self::assertSame('article', $profile['bundle']);
    self::assertSame('field_feature_image', $profile['field_name']);
    self::assertSame(
      '489bf57f89e519862d5a1ae46f2d3335dd213993be0d2cf83b47694cfab22acf',
      $profile['article_payload_sha256'],
    );
    self::assertSame(
      'assets/editorial/issue-401-redesign-checklist.png',
      $profile['asset']['path'],
    );
    self::assertSame(
      'issue-401-redesign-checklist-70bf17abe69d.png',
      $profile['asset']['filename'],
    );
    self::assertSame(
      '70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898',
      $profile['asset']['sha256'],
    );
    self::assertSame(
      'Checklist de préparation avant la refonte d’un site web',
      $profile['alt']['fr'],
    );
    self::assertSame(
      'Website redesign preparation checklist',
      $profile['alt']['en'],
    );

    $asset = tempnam(sys_get_temp_dir(), 'agency-971-');
    self::assertIsString($asset);
    unlink($asset);
    $asset .= '.png';
    try {
      $command = sprintf(
        'python3 %s --output %s 2>&1',
        escapeshellarg($root . '/scripts/runner/generate-editorial-feature-image-401.py'),
        escapeshellarg($asset),
      );
      $output = [];
      $exitCode = 0;
      exec($command, $output, $exitCode);
      self::assertSame(0, $exitCode, implode("\n", $output));
      self::assertFileExists($asset);
      self::assertSame($profile['asset']['sha256'], hash_file('sha256', $asset));
    }
    finally {
      if (is_file($asset)) {
        unlink($asset);
      }
    }
  }

  /**
   * Runtime code fixes node, FID, URI and source before any file write.
   */
  public function testRuntimeTargetAndPreconditionsAreFixed(): void {
    $root = dirname(DRUPAL_ROOT);
    $phpPath = $root . '/scripts/runner/preprod-editorial-image-rehydrate-971.php';
    $shellPath = $root . '/scripts/runner/run-preprod-editorial-image-rehydrate-971.sh';
    self::assertFileExists($phpPath);
    self::assertFileExists($shellPath);
    $php = (string) file_get_contents($phpPath);
    $shell = (string) file_get_contents($shellPath);

    foreach ([
      'private const NODE_ID = 37',
      'private const FID = 2',
      "private const EXPECTED_URI = 'public://articles/issue-401-redesign-checklist-70bf17abe69d.png'",
      "private const EXPECTED_ASSET_SHA256 = '70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898'",
      "private const EXPECTED_PAYLOAD_SHA256 = '489bf57f89e519862d5a1ae46f2d3335dd213993be0d2cf83b47694cfab22acf'",
      "private const REMOTE_ASSET_PATH = '/tmp/agency-preprod-image-rehydrate-971-asset.png'",
      'agency_editorial.issue.401',
      'FileExists::Error',
      'saveData($bytes, self::EXPECTED_URI',
      "realpath('public://articles')",
      "'READY_TO_REHYDRATE'",
      "'REHYDRATED'",
      "'IDEMPOTENT'",
    ] as $required) {
      self::assertStringContainsString($required, $php);
    }

    self::assertStringContainsString("ISSUE_NUMBER\" == '971'", $shell);
    self::assertStringContainsString("remote_target=\"agency-preprod@\$PREPROD_SERVER_HOST\"", $shell);
    self::assertStringContainsString("REMOTE_ASSET='/tmp/agency-preprod-image-rehydrate-971-asset.png'", $shell);

    foreach (['node->save(', 'file->save(', 'image_style', 'flush_derivative', 'rsync ', 'curl ', 'wget '] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $php . "\n" . $shell);
    }
  }

  /**
   * Dry-run is read-only; apply is preflighted and post-proved idempotent.
   */
  public function testDryRunApplyAndIdempotenceOrderingIsFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $shell = (string) file_get_contents(
      $root . '/scripts/runner/run-preprod-editorial-image-rehydrate-971.sh',
    );
    $runtime = (string) file_get_contents(
      $root . '/scripts/runner/preprod-editorial-image-rehydrate-971.php',
    );

    $dryRunStart = strpos($shell, "if [[ \"\$MODE\" == 'dry-run' ]]");
    $applyStart = strpos($shell, "else\n  remote_eval dry-run \"\$ARTIFACT_DIR/preapply.json\"");
    self::assertIsInt($dryRunStart);
    self::assertIsInt($applyStart);
    self::assertLessThan($applyStart, $dryRunStart);
    $dryRunBlock = substr($shell, $dryRunStart, $applyStart - $dryRunStart);
    self::assertStringNotContainsString('scp ', $dryRunBlock);
    self::assertStringNotContainsString('remote_cleanup_armed=1', $dryRunBlock);
    self::assertStringNotContainsString('rm -f', $dryRunBlock);

    $preapply = strpos($shell, 'remote_eval dry-run "$ARTIFACT_DIR/preapply.json"');
    $remoteTempAbsent = strpos($shell, "test ! -e '\$REMOTE_ASSET'");
    $cleanupArm = strpos($shell, 'remote_cleanup_armed=1');
    $scp = strpos($shell, 'scp "${ssh_common[@]}" "$ASSET"');
    $apply = strpos($shell, 'remote_eval apply "$ARTIFACT_DIR/result.json"');
    $postapply = strpos($shell, 'remote_eval dry-run "$ARTIFACT_DIR/postapply.json"');
    self::assertIsInt($preapply);
    self::assertIsInt($remoteTempAbsent);
    self::assertIsInt($cleanupArm);
    self::assertIsInt($scp);
    self::assertIsInt($apply);
    self::assertIsInt($postapply);
    self::assertLessThan($remoteTempAbsent, $preapply);
    self::assertLessThan($cleanupArm, $remoteTempAbsent);
    self::assertLessThan($scp, $cleanupArm);
    self::assertLessThan($apply, $scp);
    self::assertLessThan($postapply, $apply);
    self::assertStringContainsString(
      '.mode == "dry-run" and .verdict == "IDEMPOTENT"',
      $shell,
    );

    $dryRunRuntime = strpos($runtime, "if (\$mode === 'dry-run')");
    $idempotentRuntime = strpos($runtime, "if (\$physical === 'PRESENT_EXACT')");
    $saveRuntime = strpos($runtime, 'saveData($bytes, self::EXPECTED_URI, FileExists::Error)');
    $afterState = strpos($runtime, '$after = $this->captureState();');
    self::assertIsInt($dryRunRuntime);
    self::assertIsInt($idempotentRuntime);
    self::assertIsInt($saveRuntime);
    self::assertIsInt($afterState);
    self::assertLessThan($idempotentRuntime, $dryRunRuntime);
    self::assertLessThan($saveRuntime, $idempotentRuntime);
    self::assertLessThan($afterState, $saveRuntime);
    self::assertStringContainsString("return \$this->result('apply', 'IDEMPOTENT'", $runtime);
    self::assertStringContainsString("return \$this->result('apply', 'REHYDRATED'", $runtime);
    self::assertStringContainsString('if ($after !== $before)', $runtime);
  }

  /**
   * No arbitrary target, source asset or PROD identity is accepted.
   */
  public function testArbitraryInputsAndProdTargetAreRefusedByConstruction(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/preprod-editorial-image-rehydrate-971.yml',
    );
    $runner = (string) file_get_contents(
      $root . '/scripts/runner/run-preprod-editorial-image-rehydrate-971.sh',
    );
    $runtime = (string) file_get_contents(
      $root . '/scripts/runner/preprod-editorial-image-rehydrate-971.php',
    );

    self::assertStringNotContainsString('/agency-preprod-image-rehydrate dry-run ', $workflow);
    self::assertStringNotContainsString('/agency-preprod-image-rehydrate apply ', $workflow);
    self::assertStringNotContainsString('SERVER_USER', $workflow);
    self::assertStringNotContainsString('secrets.SSH_PRIVATE_KEY', $workflow);
    self::assertStringNotContainsString('secrets.SERVER_HOST', $workflow);
    self::assertStringNotContainsString('secrets.SERVER_USER', $workflow);
    self::assertStringNotContainsString('${ASSET_FILE', $runner);
    self::assertStringNotContainsString('${TARGET', $runner);
    self::assertStringNotContainsString("getenv('AGENCY_PREPROD_IMAGE_REHYDRATE_ASSET", $runtime);
    self::assertStringContainsString("PREPROD_ROOT='/var/www/agency-preprod/current'", $runner);
  }

  /**
   * The runner and runtime syntax remain valid.
   */
  public function testRuntimeSyntaxIsValid(): void {
    $root = dirname(DRUPAL_ROOT);
    $checks = [
      ['bash', '-n', $root . '/scripts/runner/run-preprod-editorial-image-rehydrate-971.sh'],
      ['php', '-l', $root . '/scripts/runner/preprod-editorial-image-rehydrate-971.php'],
    ];
    foreach ($checks as $check) {
      $command = implode(' ', array_map('escapeshellarg', $check)) . ' 2>&1';
      $output = [];
      $exitCode = 0;
      exec($command, $output, $exitCode);
      self::assertSame(0, $exitCode, implode("\n", $output));
    }
  }

}

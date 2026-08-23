<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the one-shot production repair for the corrupt #401 source image.
 *
 * @group agency_project_tests
 * @group governed_editorial_feature_image
 */
final class ProductionEditorialImageSourceRepairWorkflowTest extends TestCase {

  /**
   * The repair surface must remain exact, owner-only and issue #596-only.
   */
  public function testRepairWorkflowIsClosedAndLiveMainBound(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-image-source-repair.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString("github.event.issue.number == 596", $workflow);
    self::assertStringContainsString("'/agency-production-image repair-401-source'", $workflow);
    self::assertStringContainsString("GITHUB_ACTOR\" == 'E-merging-digital'", $workflow);
    self::assertStringContainsString('currently on live main', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('REPLACE_REQUIRED', $workflow);
    self::assertStringContainsString('IDEMPOTENT', $workflow);
    self::assertStringContainsString('vendor/bin/drush sql:dump', $workflow);
    self::assertStringContainsString('generate-editorial-feature-image-401.py', $workflow);
    self::assertStringContainsString('imagecreatefrompng', $workflow);
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);

    foreach (['curl ', 'wget ', 'repository_dispatch', 'inputs:'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * A remote fail-close must preserve the helper JSON before the job fails.
   */
  public function testRepairWorkflowPreservesRemoteFailureEvidence(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-image-source-repair.yml';
    $workflow = (string) file_get_contents($path);

    foreach ([
      'dry_run_status="$?"',
      'apply_status="$?"',
      'cp "$artifact_dir/preapply.json" "$artifact_dir/result.json"',
      'Remote dry-run failed after preserving bounded result evidence.',
      'Remote apply failed after preserving bounded result evidence.',
      'message="$(jq -r',
      'message: \\`${message}\\`',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertMatchesRegularExpression(
      '/set \+e\s+remote_run dry-run .*?dry_run_status="\$\?"\s+set -e\s+if ! scp/s',
      $workflow,
    );
    self::assertMatchesRegularExpression(
      '/set \+e\s+remote_run apply .*?apply_status="\$\?"\s+set -e\s+if ! scp/s',
      $workflow,
    );
  }

  /**
   * Pins the exact legacy-to-replacement transition and fail-close.
   */
  public function testRepairHelperPinsExactLegacyAndReplacementBytes(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/runner/repair-editorial-feature-image-401.php';
    self::assertFileExists($path);
    $php = (string) file_get_contents($path);

    foreach ([
      "const AGENCY_IMAGE_REPAIR_ISSUE = 401",
      "const AGENCY_IMAGE_REPAIR_OLD_FILENAME = 'issue-401-redesign-checklist-f925e3b41c32.png'",
      "const AGENCY_IMAGE_REPAIR_OLD_SHA = 'f925e3b41c325e9e863d1936d41b18cea5d0b9c064fac7ba6f551741f863fad4'",
      "const AGENCY_IMAGE_REPAIR_NEW_FILENAME = 'issue-401-redesign-checklist-70bf17abe69d.png'",
      "const AGENCY_IMAGE_REPAIR_NEW_SHA = '70bf17abe69d9b817b610de1e9529d468270a9a8206268bf0bb5736f82e6b898'",
      "'verdict' => 'REPLACE_REQUIRED'",
      "'verdict' => 'REPLACED'",
      'Current feature image is neither the exact corrupt legacy asset nor the exact replacement.',
      'Legacy #401 asset ALT values differ from the reviewed state; refusing compound repair.',
      "agency_editorial.issue.' . AGENCY_IMAGE_REPAIR_ISSUE",
      "->set('field_feature_image'",
      "->getTranslation('en')->set('field_feature_image'",
      'setNewRevision(TRUE)',
      'FileExists::Error',
    ] as $required) {
      self::assertStringContainsString($required, $php);
    }

    foreach ([
      'unlink(',
      'file_unmanaged_delete',
      'entity_delete',
      'drush cim',
      'drush updb',
      'composer ',
      'shell_exec(',
      'exec(',
      'system(',
      'passthru(',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $php);
    }

    $output = [];
    $exitCode = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
    self::assertSame(0, $exitCode, implode("\n", $output));
  }

  /**
   * The regenerated source must remain deterministic and profile-bound.
   */
  public function testGeneratedReplacementMatchesClosedProfile(): void {
    $root = dirname(DRUPAL_ROOT);
    $generator = $root . '/scripts/runner/generate-editorial-feature-image-401.py';
    $profile = json_decode(
      (string) file_get_contents($root . '/scripts/runner/editorial-feature-image-profiles.json'),
      TRUE,
      16,
      JSON_THROW_ON_ERROR,
    );
    $asset = $profile['profiles']['401']['asset'];

    $first = DRUPAL_ROOT . '/sites/simpletest/browser_output/issue-401-repair-first.png';
    $second = DRUPAL_ROOT . '/sites/simpletest/browser_output/issue-401-repair-second.png';
    foreach ([$first, $second] as $target) {
      $output = [];
      $exitCode = 0;
      exec(
        'python3 ' . escapeshellarg($generator) . ' --output ' . escapeshellarg($target) . ' 2>&1',
        $output,
        $exitCode,
      );
      self::assertSame(0, $exitCode, implode("\n", $output));
      self::assertFileExists($target);
    }

    self::assertSame($asset['sha256'], hash_file('sha256', $first));
    self::assertSame(hash_file('sha256', $first), hash_file('sha256', $second));
    self::assertSame(file_get_contents($first), file_get_contents($second));
  }

}

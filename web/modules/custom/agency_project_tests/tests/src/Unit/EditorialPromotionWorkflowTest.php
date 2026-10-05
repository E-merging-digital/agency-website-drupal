<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the PREPROD-first same-artifact PROD promotion wiring.
 *
 * @group agency_project_tests
 * @group editorial_promotion_governance
 */
#[Group('editorial_promotion_governance')]
final class EditorialPromotionWorkflowTest extends TestCase {

  /**
   * Apply must cross the exact approval gate before production SSH setup.
   */
  public function testApplyRequiresExactPreprodHumanAndImageGate(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-editorial-publication.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));
    $workflow = (string) file_get_contents($path);

    self::assertStringContainsString('Require exact PREPROD and human approval for apply', $workflow);
    self::assertStringContainsString('validate-editorial-promotion-approval.py', $workflow);
    self::assertStringContainsString('Live main changed after workflow start; approval is stale.', $workflow);
    self::assertStringContainsString('Candidate revision changed after workflow start.', $workflow);
    self::assertStringContainsString('Candidate payload changed after workflow start.', $workflow);
    self::assertStringContainsString('generate-editorial-feature-image-${ISSUE_NUMBER}.py', $workflow);
    self::assertStringContainsString('run-editorial-promotion.sh', $workflow);
    self::assertStringContainsString('.image_waiver == "UNSUPPORTED"', $workflow);

    $gatePosition = strpos($workflow, 'Require exact PREPROD and human approval for apply');
    $sshPosition = strpos($workflow, 'Configure existing production SSH channel');
    self::assertIsInt($gatePosition);
    self::assertIsInt($sshPosition);
    self::assertLessThan($sshPosition, $gatePosition);
  }

  /**
   * Promotion must stage before visual completion/publication.
   */
  public function testPromotionRunnerIsBoundedAndSyntaxValid(): void {
    $root = dirname(DRUPAL_ROOT);
    $runnerPath = $root . '/scripts/runner/run-editorial-promotion.sh';
    $runtimePath = $root . '/scripts/runner/editorial-promotion-runtime.php';
    $promotionPath = $root . '/scripts/runner/editorial-promotion.php';
    foreach ([$runnerPath, $runtimePath, $promotionPath] as $path) {
      self::assertFileExists($path);
    }

    $runner = (string) file_get_contents($runnerPath);
    $runtime = (string) file_get_contents($runtimePath);
    $promotion = (string) file_get_contents($promotionPath);

    self::assertStringContainsString('/var/www/agency/current', $runner);
    self::assertStringContainsString('vendor/bin/drush sql:dump', $runner);
    self::assertStringContainsString("\$stagedPayload['published'] = FALSE;", $runtime);
    self::assertStringContainsString('Feature image did not converge before publication.', $runtime);
    self::assertStringContainsString('visual_completeness', $runtime);
    self::assertStringContainsString('REFUSED_BY_DESIGN', $runtime);
    self::assertStringContainsString('Mapped Article content drifted', $promotion);
    self::assertStringContainsString('exact approved FR/EN ALT', $promotion);
    self::assertStringContainsString('feature image bytes drifted', $promotion);

    foreach (['eval ', 'drush cim', 'drush updb', 'composer require'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner . $runtime . $promotion);
    }

    $output = [];
    $exitCode = 0;
    exec('bash -n ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    self::assertSame(0, $exitCode, implode("\n", $output));

    foreach ([$runtimePath, $promotionPath] as $path) {
      $output = [];
      $exitCode = 0;
      exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
      self::assertSame(0, $exitCode, implode("\n", $output));
    }
  }

}

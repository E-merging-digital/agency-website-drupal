<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects strict Composer validation for governed prerelease constraints.
 *
 * @group agency_project_tests
 * @group governed_composer
 */
final class ComposerValidationCiTest extends TestCase {

  /**
   * CI ignores only Composer's overly-strict constraint warning class.
   */
  public function testStrictValidationKeepsLockAndPublishChecksEnabled(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/ci.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString(
      'composer validate --strict --no-check-all',
      $workflow,
    );
    self::assertStringNotContainsString('--no-check-lock', $workflow);
    self::assertStringNotContainsString('--no-check-publish', $workflow);
    self::assertStringNotContainsString(
      'composer validate --no-check-all',
      $workflow,
    );
  }

}

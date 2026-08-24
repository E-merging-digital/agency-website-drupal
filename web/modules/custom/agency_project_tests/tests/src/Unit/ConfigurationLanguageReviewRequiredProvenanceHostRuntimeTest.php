<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects the DDEV-owned PHP runtime for the #748 trusted proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageReviewRequiredProvenanceHostRuntimeTest extends TestCase {

  /**
   * The host preflight must not require PHP outside DDEV.
   */
  public function testPhpRuntimeIsValidatedInsideDdevOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-review-required-provenance.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertStringNotContainsString("          php --version\n", $workflow);
    self::assertStringContainsString(
      'ddev exec php -l \\',
      $workflow,
    );
  }

}

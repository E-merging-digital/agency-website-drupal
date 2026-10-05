<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects shape-agnostic empty module-owned baseline validation.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageLockPostMigrationBaselineShapeTest extends TestCase {

  /**
   * Empty module-owned state is checked semantically, not by JSON shape.
   */
  public function testEmptyModuleOwnedCheckUsesCardinality(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/run-config-language-lock-post-migration-evaluation.sh';
    self::assertFileExists($path);

    $runner = (string) file_get_contents($path);
    self::assertStringContainsString(
      "jq -e '(.module_owned | length) == 0'",
      $runner,
    );
    self::assertStringNotContainsString(
      "jq -e '.module_owned == {}'",
      $runner,
    );
  }

}

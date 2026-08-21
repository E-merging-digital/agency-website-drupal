<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Protects bounded Pathauto finalization for editorial publication.
 *
 * @group agency_project_tests
 * @group governed_editorial_publication
 */
final class GovernedEditorialPathautoWorkflowTest extends TestCase {

  /**
   * Alias-only repair must remain a guarded, Pathauto-owned mutation.
   */
  public function testRunnerTreatsAliasRepairAsGuardedMutation(): void {
    $root = dirname(DRUPAL_ROOT);
    $runner = (string) file_get_contents($root . '/scripts/runner/run-editorial-publication.sh');
    $wrapper = (string) file_get_contents($root . '/scripts/runner/editorial-publication-pathauto.php');

    self::assertStringContainsString('editorial-publication-pathauto.php', $runner);
    self::assertStringContainsString('AGENCY_EDITORIAL_LIBRARY_PATH', $runner);
    self::assertStringContainsString('.verdict == "REPAIR_REQUIRED"', $runner);
    self::assertStringContainsString('$preapply_verdict" == \'REPAIR_REQUIRED\'', $runner);
    self::assertStringContainsString('$container->get(\'pathauto.generator\')', $wrapper);
    self::assertStringContainsString('updateEntityAlias(', $wrapper);
    self::assertStringContainsString("'bulkupdate'", $wrapper);
    self::assertStringContainsString("['force' => TRUE]", $wrapper);
    self::assertStringContainsString("private const ALIAS_PREFIX = '/blog/'", $wrapper);
    self::assertStringNotContainsString('->save()', $wrapper);
    self::assertStringNotContainsString('PathAlias::create', $wrapper);
    self::assertStringNotContainsString('->delete(', $wrapper);
  }

}

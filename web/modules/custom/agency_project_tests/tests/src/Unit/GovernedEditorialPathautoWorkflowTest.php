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
    $finalizer = (string) file_get_contents(
      DRUPAL_ROOT . '/modules/custom/emerging_digital_content/src/Service/EditorialPathautoFinalizer.php',
    );
    $moduleInfo = (string) file_get_contents(
      DRUPAL_ROOT . '/modules/custom/emerging_digital_content/emerging_digital_content.info.yml',
    );

    self::assertStringContainsString('editorial-publication-pathauto.php', $runner);
    self::assertStringContainsString('AGENCY_EDITORIAL_LIBRARY_PATH', $runner);
    self::assertStringContainsString('.verdict == "REPAIR_REQUIRED"', $runner);
    self::assertStringContainsString('$preapply_verdict" == \'REPAIR_REQUIRED\'', $runner);
    self::assertStringContainsString('EditorialPathautoFinalizer::fromContainer', $wrapper);
    self::assertStringContainsString("$container->get('pathauto.generator')", $finalizer);
    self::assertStringContainsString('PathautoGeneratorInterface', $finalizer);
    self::assertStringContainsString('updateEntityAlias(', $finalizer);
    self::assertStringContainsString("'bulkupdate'", $finalizer);
    self::assertStringContainsString("['force' => TRUE]", $finalizer);
    self::assertStringContainsString("private const ALIAS_PREFIX = '/blog/'", $finalizer);
    self::assertStringContainsString('- drupal:pathauto', $moduleInfo);
    self::assertStringNotContainsString('->save()', $finalizer);
    self::assertStringNotContainsString('PathAlias::create', $finalizer);
    self::assertStringNotContainsString('->delete(', $finalizer);
  }

}

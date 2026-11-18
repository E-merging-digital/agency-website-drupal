<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Ensures the #1417 preflight cannot silently become a PROD importer.
 *
 * @group agency_project_tests
 */
final class AgencyNlTransferPreflightTest extends TestCase {

  /**
   * Checks PHP syntax and the explicit no-mutation boundary.
   */
  public function testPrivatePreflightIsReadOnly(): void {
    $path = dirname(DRUPAL_ROOT) . '/scripts/runner/agency-1417-nl-transfer-preflight.php';
    $source = file_get_contents($path);
    self::assertNotFalse($source);

    // Parse using the CI PHP runtime, including its syntax errors.
    self::assertNotEmpty(token_get_all($source, TOKEN_PARSE));

    self::assertStringContainsString('INVALID_MODE_EXPORT_OR_PLAN_ONLY', $source);
    self::assertStringContainsString('EXISTING_NL_', $source);
    self::assertStringContainsString('CONTENT_IMPORT_NOT_IMPLEMENTED=YES', $source);
    self::assertStringContainsString('MANIFEST_MISSING_OR_HASH_MISMATCH', $source);
    self::assertStringContainsString("'exceptions' => [43, 46]", $source);
    // Drush php:script includes the file inside a method, not global scope.
    self::assertStringNotContainsString('global $map;', $source);
    self::assertStringContainsString('array $map, int $depth = 0', $source);
    self::assertStringContainsString('agency1417_fields($ref, $langcode, $source, $map, $depth + 1)', $source);
    self::assertStringNotContainsString('->save(', $source);
    self::assertStringNotContainsString('->delete(', $source);
    self::assertStringNotContainsString('addTranslation(', $source);
    self::assertStringNotContainsString('importContent(', $source);
  }

}

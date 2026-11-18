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

    self::assertStringContainsString('INVALID_MODE_EXPORT_PLAN_OR_DIAGNOSE_ONLY', $source);
    self::assertStringContainsString('EXISTING_NL_', $source);
    self::assertStringContainsString('CONTENT_IMPORT_NOT_IMPLEMENTED=YES', $source);
    self::assertStringContainsString('MANIFEST_MISSING_OR_HASH_MISMATCH', $source);
    // Diagnose using the immutable private export; never re-export
    // NL fields or log their plaintext. PREPROD/PROD remain owner operated.
    self::assertStringContainsString("'diagnose-export' =>", $source);
    self::assertStringContainsString("'diagnose-plan' =>", $source);
    self::assertStringContainsString('agency1417_diagnostic_fields(', $source);
    self::assertStringContainsString('agency1417_diagnostic_diff(', $source);
    self::assertStringContainsString('DIAGNOSTIC_FILE_ALREADY_EXISTS', $source);
    self::assertStringContainsString('SOURCE_FIELD_CHANGED_', $source);
    self::assertStringContainsString('DIAGNOSTIC_HASH_OR_FILE_MISMATCH', $source);
    self::assertStringContainsString('UNEXPECTED_TARGET_IDENTITY_OR_REFERENCE_ERROR', $source);
    self::assertStringContainsString('FIELD_PATHS=', $source);
    self::assertStringContainsString('NO_WRITE=YES', $source);
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

  /**
   * Keeps the additive NL candidate a draft-only, owner-operated operation.
   */
  public function testAdditiveCandidateCannotPublishOrReplaceFrEn(): void {
    $path = dirname(DRUPAL_ROOT) . '/scripts/runner/agency-1417-nl-additive-drafts.php';
    $source = file_get_contents($path);
    self::assertNotFalse($source);
    self::assertNotEmpty(token_get_all($source, TOKEN_PARSE));

    self::assertStringContainsString("['dry-run', 'apply']", $source);
    self::assertStringContainsString('PRIVATE_MANIFEST_INTEGRITY', $source);
    self::assertStringContainsString('SOURCE_TARGET_MAPPING_CHANGED', $source);
    self::assertStringContainsString('DRAFT_FR_EN_CHANGED_', $source);
    self::assertStringContainsString('DRAFT_POST_SAVE_FR_EN_DRIFT', $source);
    self::assertStringContainsString('EXACT_OWNER_DRAFT_GO_REQUIRED', $source);
    self::assertStringContainsString('NL_LANGUAGE_NOT_ACTIVE', $source);
    self::assertStringContainsString('DRAFT_UNSUPPORTED_REFERENCE_', $source);
    self::assertStringContainsString('DRAFT_NODE_DEPENDENCY_NOT_READY', $source);
    self::assertStringContainsString('DRAFT_PARAGRAPH_IDENTITY_OR_TRANSLATION', $source);
    self::assertStringContainsString('DRAFT_PARAGRAPH_REVISION_NOT_SAVED', $source);
    self::assertStringContainsString('$paragraph->setNewRevision(TRUE);', $source);
    self::assertStringContainsString('array_diff(range(1, 38), [37, 38])', $source);
    self::assertStringContainsString('DRAFT_HOMEPAGE_DIAGNOSTIC_INTEGRITY', $source);
    self::assertStringContainsString('DRAFT_HOMEPAGE_EDITORIAL_DRIFT', $source);
    self::assertStringContainsString('DRAFT_HOMEPAGE_REFERENCE_IDENTITY', $source);
    self::assertStringContainsString('DRAFT_LINK_NODE_DEPENDENCY_NOT_READY', $source);
    self::assertStringContainsString('DRAFT_LINK_URI_UNVERIFIED', $source);
    self::assertStringContainsString('DRAFT_MEDIA_ASSET_PARITY_UNPROVEN', $source);
    self::assertStringContainsString('DRAFT_FIELD_PREFLIGHT=PASS NODES=36', $source);
    self::assertStringContainsString('DRAFT_EXTRA41=PARITY_PASS', $source);
    self::assertStringContainsString('DRAFT_EXTRA41_FR_EN_PARITY_CHANGED', $source);
    self::assertStringContainsString('NOT_ADMITTED=YES TARGET_WRITE=NONE', $source);
    self::assertLessThan(
      strpos($source, "if (!\$lang) {"),
      strpos($source, 'DRAFT_EXTRA41=PARITY_PASS'),
    );
    self::assertStringContainsString("if (\$name === 'content_translation_changed')", $source);
    self::assertStringContainsString("if (\$name === 'metatag' && \$entity->hasField(\$name)", $source);
    self::assertStringContainsString("->getType() === 'metatag_computed'", $source);
    self::assertStringContainsString("DRAFT_PARAGRAPH_IDENTITY_OR_TRANSLATION_UUID", $source);
    self::assertStringContainsString("(int) \$entity->id() !== 2", $source);
    self::assertStringContainsString("loadByProperties(['uuid' => \$uuid])", $source);
    self::assertStringContainsString("'uuid' => \$uuid", $source);
    self::assertStringContainsString("!\$nlConfigured || \$entity->hasTranslation('nl')", $source);
    self::assertStringContainsString("if (\$mode === 'apply' && \\Drupal::languageManager()->getLanguage('nl') === NULL)", $source);
    self::assertStringContainsString('DRAFT_NEW_PARAGRAPH_NL_LANGUAGE_INVALID', $source);
    self::assertStringContainsString("'new' => TRUE", $source);
    self::assertStringContainsString("['entity' => \$paragraph]", $source);
    self::assertStringContainsString('DRAFT_POST_SAVE_NL_PARAGRAPH_COUNT', $source);
    self::assertStringContainsString('DRAFT_POST_SAVE_NL_PARAGRAPH_BUNDLE', $source);
    self::assertStringContainsString('DRAFT_FIELD_BLOCKER NID=', $source);
    self::assertStringContainsString('DRAFT_FIELD_PREFLIGHT_BLOCKERS_', $source);
    self::assertStringContainsString('$translation->setUnpublished();', $source);
    self::assertStringContainsString('TARGET_WRITE=NONE', $source);
    self::assertStringNotContainsString('->delete(', $source);
    self::assertStringNotContainsString("->addTranslation('fr'", $source);
    self::assertStringNotContainsString("->addTranslation('en'", $source);
    self::assertStringNotContainsString("getStorage('path_alias')", $source);
  }

}

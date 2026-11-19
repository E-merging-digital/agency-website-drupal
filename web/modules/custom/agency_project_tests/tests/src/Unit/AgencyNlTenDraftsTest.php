<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Verifies R177 using synthetic data, never private production content.
 *
 * @group agency_project_tests
 */
final class AgencyNlTenDraftsTest extends TestCase {

  /**
   * Loads pure functions without calling the guarded Drush runtime.
   */
  protected function setUp(): void {
    parent::setUp();
    if (!defined('AGENCY1417_TEN_SYNTHETIC_TEST')) {
      define('AGENCY1417_TEN_SYNTHETIC_TEST', TRUE);
    }
    require_once dirname(DRUPAL_ROOT) . '/scripts/runner/agency-1417-nl-ten-drafts.php';
  }

  /**
   * Makes a fully invented ten-node, ten-Paragraph contract.
   */
  private function fakeBatch(): array {
    $source = ['contract' => 'agency1417-10-prod-fr-texts-v1', 'source' => 'SYNTHETIC', 'items' => []];
    $candidate = [
      'contract' => 'agency-1417-prod-10-nl-be-editorial-candidate-v1',
      'source_contract' => $source['contract'],
      'source_sha256' => '5817ef5ca96bf80c3bc9df00c5582260d765157c5b1b419d067708874da9cb83',
      'language' => 'nl-BE',
      'runtime_write' => 'NONE',
      'publication_authorized' => FALSE,
      'items' => [],
    ];
    foreach ([37, 38, 39, 41, 45, 43, 44, 46, 47, 48] as $id) {
      $page = $id === 41;
      $fields = [
        'field_short_description' => [['value' => 'Synthetic FR', 'format' => 'basic_html']],
        'title' => [['value' => 'FR example ' . $id]],
      ];
      $nlFields = ['title' => 'NL example ' . $id, 'field_short_description' => 'Synthetisch'];
      if ($page) {
        $fields['field_home_components'] = [];
        $nlFields['field_home_components'] = [];
        for ($i = 0; $i < 10; $i++) {
          $fields['field_home_components'][] = [
            'bundle' => 'text_block',
            'fields' => ['field_text' => [['value' => '<a href="/fr/contact">FR</a>', 'format' => 'basic_html']]],
          ];
          $nlFields['field_home_components'][] = [
            'paragraph_index' => $i,
            'bundle' => 'text_block',
            'fields_nl' => ['field_text' => [['value' => '<a href="/fr/contact">NL</a>', 'format' => 'basic_html']]],
          ];
        }
      }
      else {
        $fields['body'] = [['value' => '<a href="/fr/contact">FR</a>', 'format' => 'basic_html']];
        $fields['field_feature_image'] = [['alt' => 'FR', 'title' => NULL]];
        $nlFields['body'] = ['value' => '<a href="/fr/contact">NL</a>', 'format' => 'basic_html'];
        $nlFields['field_feature_image'] = ['alt' => 'NL'];
      }
      $fr = [
        'prod_nid' => $id,
        'bundle' => $page ? 'page' : 'article',
        'published_fr' => TRUE,
        'title_fr' => 'FR example ' . $id,
        'alias_fr' => '/fr/example-' . $id,
        'fields_fr' => $fields,
      ];
      $source['items'][] = $fr;
      $candidate['items'][] = [
        'prod_nid' => $id,
        'bundle' => $fr['bundle'],
        'source_fr_sha256' => $this->synthetic('agency1417_ten_digest', $fr),
        'title_fr_for_review' => $fr['title_fr'],
        'alias_fr_for_review' => $fr['alias_fr'],
        'status_nl' => 'DRAFT_NOT_PUBLISHED',
        'fields_nl' => $nlFields,
      ];
    }
    return [$source, $candidate];
  }

  /**
   * Calls pure script helpers without adding a new Composer autoload entry.
   */
  private function synthetic(string $function, mixed ...$args): mixed {
    if (!function_exists($function)) {
      throw new \RuntimeException('Synthetic helper unavailable');
    }
    $callback = \Closure::fromCallable($function);
    return $callback(...$args);
  }

  /**
   * Asserts that a synthetic case fails closed with the expected code.
   */
  private function expectStop(callable $check, string $reason): void {
    try {
      $check();
      self::fail('Expected controlled stop: ' . $reason);
    }
    catch (\RuntimeException $e) {
      self::assertSame('CONTROLLED_STOP=' . $reason, $e->getMessage());
    }
  }

  /**
   * Checks the exact ten synthetic identities and ten-page components.
   */
  public function testSyntheticTenItemContract(): void {
    [$fr, $nl] = $this->fakeBatch();
    $batch = $this->synthetic('agency1417_ten_batch', $fr, $nl);
    self::assertSame([37, 38, 39, 41, 45, 43, 44, 46, 47, 48], array_keys($batch));
    self::assertCount(10, $batch[41]['nl']['fields_nl']['field_home_components']);
  }

  /**
   * Rejects identity, provenance and publication changes.
   */
  public function testRejectsWrongIdentitySourceAndPublication(): void {
    [$fr, $nl] = $this->fakeBatch();
    $bad = $nl;
    $bad['items'][1]['prod_nid'] = 99;
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'CANDIDATE_NODE_IDENTITY_OR_FIELDS');
    $bad = $nl;
    $bad['items'][1]['source_fr_sha256'] = str_repeat('0', 64);
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'CANDIDATE_NODE_IDENTITY_OR_FIELDS');
    $bad = $nl;
    $bad['publication_authorized'] = TRUE;
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'PRIVATE_BATCH_CONTRACT');
    $bad = $nl;
    $bad['items'][1]['status_nl'] = 'PUBLISHED';
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'CANDIDATE_NODE_IDENTITY_OR_FIELDS');
  }

  /**
   * Rejects unsafe, unquoted and invented destinations.
   */
  public function testRejectsNewUnsafeOrChangedLink(): void {
    [$fr, $nl] = $this->fakeBatch();
    $bad = $nl;
    $bad['items'][0]['fields_nl']['body']['value'] = '<a href="javascript:alert(1)">NO</a>';
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'UNSAFE_LINK_URI');
    $bad = $nl;
    $bad['items'][0]['fields_nl']['body']['value'] = '<a href="/nl/contact">NO</a>';
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'EDITORIAL_LINK_DRIFT');
    $bad = $nl;
    $bad['items'][0]['fields_nl']['body']['value'] = '<a href=/fr/contact>NO</a>';
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'UNQUOTED_OR_UNSAFE_LINK');
  }

  /**
   * Rejects a changed Paragraph order, bundle or count.
   */
  public function testRejectsParagraphIdentityAndFieldDifferences(): void {
    [$fr, $nl] = $this->fakeBatch();
    $bad = $nl;
    $bad['items'][3]['fields_nl']['field_home_components'][4]['paragraph_index'] = 5;
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'PARAGRAPH_MAPPING_UNPROVEN');
    $bad = $nl;
    $bad['items'][3]['fields_nl']['field_home_components'][4]['bundle'] = 'hero';
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'PARAGRAPH_MAPPING_UNPROVEN');
    $bad = $nl;
    array_pop($bad['items'][3]['fields_nl']['field_home_components']);
    $this->expectStop(fn () => $this->synthetic('agency1417_ten_batch', $fr, $bad), 'PARAGRAPH_COUNT_UNPROVEN');
  }

  /**
   * Accepts native taxonomy term kind independently of its vocabulary.
   */
  public function testBlogTaxonomyKindAndVocabulary(): void {
    $source = ['kind' => 'taxonomy_term', 'label' => 'Synthetic category'];
    self::assertNull($this->synthetic('agency1417_ten_taxonomy',
      $source, 'taxonomy_term', 'blog_categories', 'Synthetic category'));
    $this->expectStop(
      fn () => $this->synthetic('agency1417_ten_taxonomy',
        $source, 'taxonomy_term', 'wrong_vocabulary', 'Synthetic category'),
      'TAXONOMY_IDENTITY_UNPROVEN',
    );
    $this->expectStop(
      fn () => $this->synthetic('agency1417_ten_taxonomy',
        $source, 'taxonomy_term', 'blog_categories', 'Different category'),
      'TAXONOMY_IDENTITY_UNPROVEN',
    );
    $this->expectStop(
      fn () => $this->synthetic('agency1417_ten_taxonomy',
        $source, 'node', 'blog_categories', 'Synthetic category'),
      'TAXONOMY_IDENTITY_UNPROVEN',
    );
  }

  /**
   * Rejects missing media proof, revisions and private input.
   */
  public function testRejectsMediaMismatchAndNonexistentInput(): void {
    $this->expectStop(
      fn () => $this->synthetic('agency1417_ten_reference', 'media',
        ['alt' => 'FR', 'title' => NULL],
        ['alt' => 'OTHER', 'title' => NULL, 'target_id' => 11, 'file_id' => 11, 'uuid' => 'synthetic-uuid']),
      'MEDIA_IDENTITY_UNPROVEN',
    );
    $this->expectStop(
      fn () => $this->synthetic('agency1417_ten_reference', 'paragraph',
        ['bundle' => 'text_block'],
        ['bundle' => 'text_block', 'uuid' => 'synthetic-uuid', 'revision_id' => 4, 'linked_revision_id' => 3]),
      'PARAGRAPH_IDENTITY_UNPROVEN',
    );
    $this->expectStop(
      fn () => $this->synthetic('agency1417_ten_private', '', str_repeat('a', 64)),
      'PRIVATE_FILE_UNAVAILABLE_OR_INTEGRITY',
    );
  }

  /**
   * Keeps the future runtime source-only and draft-gated.
   */
  public function testRuntimeRemainsGatedAndOtherImporterUntouched(): void {
    $script = file_get_contents(dirname(DRUPAL_ROOT) . '/scripts/runner/agency-1417-nl-ten-drafts.php');
    self::assertNotFalse($script);
    self::assertNotEmpty(token_get_all($script, TOKEN_PARSE));
    self::assertStringContainsString("'dry-run', 'apply'", $script);
    self::assertStringContainsString('NOT_AUTHORIZED_STAGING_SURFACE', $script);
    self::assertStringContainsString('SEPARATE_EXACT_STAGE_AUTHORITY_REQUIRED', $script);
    self::assertStringContainsString('->hasTranslation(', $script);
    self::assertStringContainsString("->setUnpublished();", $script);
    self::assertStringContainsString('POST_SAVE_LANGUAGE_OR_CONTENT_DRIFT', $script);
    self::assertStringContainsString('PUBLIC_ALIASES=NONE', $script);
    self::assertStringNotContainsString('agency-1417-nl-additive-drafts.php', $script);
    self::assertStringNotContainsString('->delete(', $script);
    self::assertStringNotContainsString("->addTranslation('fr'", $script);
    self::assertStringNotContainsString("->addTranslation('en'", $script);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #754 safe provenance promotion cohort.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageSafeProvenanceCohort754Test extends TestCase {

  /**
   * The manifest and repository contain exactly the proven 17-item cohort.
   */
  public function testExactCohortIsCanonicalEnglish(): void {
    $root = dirname(DRUPAL_ROOT);
    $manifestPath = $root
      . '/docs/evidence/configuration-language-safe-provenance-cohort-754.yml';
    self::assertFileExists($manifestPath);

    $manifest = DrupalYaml::decode((string) file_get_contents($manifestPath));
    self::assertIsArray($manifest);

    $expected = [
      'canvas.asset_library.global',
      'canvas.brand_kit.global',
      'core.entity_form_mode.media.media_library',
      'core.entity_view_mode.media.full',
      'core.entity_view_mode.media.media_library',
      'editor.editor.canvas_html_block',
      'editor.editor.canvas_html_inline',
      'field.field.media.document.field_media_document',
      'field.field.media.image.field_media_image',
      'filter.format.canvas_html_block',
      'filter.format.canvas_html_inline',
      'filter.format.webform_default',
      'image.style.canvas_avatar',
      'image.style.canvas_parametrized_width',
      'metatag.metatag_defaults.global',
      'system.action.automator_chain_delete_action',
      'views.view.canvas_pages',
    ];
    sort($expected, SORT_STRING);

    $items = $manifest['items'] ?? [];
    self::assertIsArray($items);
    self::assertCount(17, $items);
    $names = array_map(
      static fn(array $item): string => (string) ($item['name'] ?? ''),
      $items,
    );
    sort($names, SORT_STRING);
    self::assertSame($expected, $names);

    foreach ($expected as $name) {
      $path = $root . '/config/sync/' . $name . '.yml';
      self::assertFileExists($path, $name);
      $data = DrupalYaml::decode((string) file_get_contents($path));
      self::assertIsArray($data);
      self::assertSame('en', $data['langcode'] ?? NULL, $name);
      self::assertFileDoesNotExist(
        $root . '/config/sync/language/en/' . $name . '.yml',
        $name,
      );
    }

    self::assertSame(122, $manifest['remaining_review_required'] ?? NULL);
    self::assertSame(
      'system.action.agency_ai_translate_nodes_bulk_action',
      $manifest['excluded'][0]['name'] ?? NULL,
    );
  }

  /**
   * Repository-wide distribution reflects later governed promotions.
   */
  public function testRepositoryDistributionAfterPromotion(): void {
    $root = dirname(DRUPAL_ROOT);
    $files = glob($root . '/config/sync/*.yml');
    self::assertIsArray($files);
    self::assertCount(596, $files);

    $counts = [];
    foreach ($files as $path) {
      $data = DrupalYaml::decode((string) file_get_contents($path));
      self::assertIsArray($data, $path);
      $langcode = isset($data['langcode']) && is_string($data['langcode'])
        ? $data['langcode']
        : '__none__';
      $counts[$langcode] = ($counts[$langcode] ?? 0) + 1;
    }
    ksort($counts, SORT_STRING);

    self::assertSame([
      '__none__' => 59,
      'en' => 497,
      'fr' => 39,
      'und' => 1,
    ], $counts);

    $coreExtension = DrupalYaml::decode((string) file_get_contents(
      $root . '/config/sync/core.extension.yml',
    ));
    self::assertIsArray($coreExtension);
    self::assertArrayNotHasKey(
      'config_language_lock',
      $coreExtension['module'] ?? [],
    );
    self::assertFileDoesNotExist(
      $root . '/config/sync/config_language_lock.settings.yml',
    );
  }

  /**
   * The trusted route is fixed to #754 and fresh-DDEV read-only verification.
   */
  public function testTrustedVerificationRouteIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflowPath = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-safe-provenance-754-verify.yml';
    self::assertFileExists($workflowPath);

    $workflow = (string) file_get_contents($workflowPath);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString('github.event.issue.number == 754', $workflow);
    self::assertStringContainsString(
      "'/agency-config-language-safe-provenance verify'",
      $workflow,
    );
    self::assertStringContainsString(
      'test "$GITHUB_ACTOR" = \'E-merging-digital\'',
      $workflow,
    );
    self::assertStringContainsString(
      'test "$EVENT_DEFAULT_SHA" = "$main_sha"',
      $workflow,
    );
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('ddev drush site:install --existing-config', $workflow);
    self::assertStringContainsString('ddev drush cim -y', $workflow);
    self::assertStringContainsString("grep -Fq 'No differences'", $workflow);
    self::assertStringContainsString(
      'SEVENTEEN_SAFE_PROVENANCE_PROMOTIONS_VERIFIED',
      $workflow,
    );
    self::assertStringContainsString(
      '"__none__":59,"en":413,"fr":122,"und":1',
      $workflow,
    );

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'drush cex',
      'php --version',
      'pm:enable config_language_lock',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'git push',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

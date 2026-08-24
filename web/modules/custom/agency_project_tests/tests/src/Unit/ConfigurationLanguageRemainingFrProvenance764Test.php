<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #764 remaining-FR provenance proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageRemainingFrProvenance764Test extends TestCase {

  /**
   * The manifest fixes the exact 69-name cohort and baseline distribution.
   */
  public function testManifestFixesExactRemainingCohort(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/docs/evidence/'
      . 'configuration-language-remaining-fr-provenance-cohort-764.yml';
    self::assertFileExists($path);

    $manifest = DrupalYaml::decode((string) file_get_contents($path));
    self::assertIsArray($manifest);
    self::assertSame(764, $manifest['issue'] ?? NULL);
    self::assertSame(609, $manifest['parent_issue'] ?? NULL);
    self::assertSame(69, $manifest['cohort']['total'] ?? NULL);
    self::assertSame([
      '__none__' => 59,
      'en' => 466,
      'fr' => 69,
      'und' => 1,
    ], $manifest['baseline']['distribution'] ?? NULL);

    $names = $manifest['cohort']['names'] ?? NULL;
    self::assertIsArray($names);
    self::assertCount(69, $names);
    self::assertCount(69, array_unique($names));
    sort($names, SORT_STRING);
    self::assertSame(
      '3386c99b57a7c3de9191107664e51ca80336e1d40557b717be8faf7b3c9c7a07',
      hash('sha256', implode("\n", $names) . "\n"),
    );
    self::assertSame(
      30,
      $manifest['cohort']['groups']['canvas_component'] ?? NULL,
    );
    self::assertSame(
      13,
      $manifest['cohort']['groups']['canvas_folder'] ?? NULL,
    );
    self::assertFalse($manifest['constraints']['migration_allowed'] ?? TRUE);
    self::assertFalse(
      $manifest['constraints']['natural_language_heuristic_used'] ?? TRUE,
    );
  }

  /**
   * The analyzer is read-only, typed-config based and fail-closed.
   */
  public function testAnalyzerUsesBoundedAuthoritativeProvenance(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-remaining-fr-provenance-764.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString(
      "\\Drupal::service('config.typed')",
      $script,
    );
    self::assertStringContainsString(
      "\\Drupal::service('config.storage')",
      $script,
    );
    self::assertStringContainsString('extension.list.module', $script);
    self::assertStringContainsString('extension.list.theme', $script);
    self::assertStringContainsString('extension.list.profile', $script);
    self::assertStringContainsString('runtime_source_candidate', $script);
    self::assertStringContainsString(
      'canvas_component_exposes_explicit_runtime_source_reference',
      $script,
    );
    self::assertStringContainsString(
      'extension_default_present_but_values_diverged',
      $script,
    );
    self::assertStringContainsString(
      'project_custom_or_editorial_review_required',
      $script,
    );
    self::assertStringContainsString(
      'schema_or_provenance_unresolved',
      $script,
    );
    self::assertStringContainsString(
      'REMAINING_FR_PROVENANCE_CLASSIFIED',
      $script,
    );

    foreach ([
      '->save(',
      '->write(',
      '->delete(',
      'drush cex',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The trusted route is owner-only, live-main-only and runner read-only.
   */
  public function testTrustedWorkflowIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-remaining-fr-provenance-764.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.number == 764',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-remaining-fr classify-provenance'",
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
    self::assertStringContainsString(
      'persist-credentials: false',
      $workflow,
    );
    self::assertStringContainsString(
      'ddev drush site:install --existing-config',
      $workflow,
    );
    self::assertStringContainsString('ddev drush cim -y', $workflow);
    self::assertStringContainsString(
      "grep -Fq 'No differences'",
      $workflow,
    );
    self::assertStringContainsString(
      'REMAINING_FR_PROVENANCE_CLASSIFIED',
      $workflow,
    );
    self::assertStringContainsString(
      '.focus.canvas_component.count == 30',
      $workflow,
    );
    self::assertStringContainsString(
      '.focus.canvas_folder.count == 13',
      $workflow,
    );
    self::assertStringContainsString(
      '"__none__":59,"en":466,"fr":69,"und":1',
      $workflow,
    );
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString('issues: write', $workflow);

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'persist-credentials: true',
      'drush cex',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

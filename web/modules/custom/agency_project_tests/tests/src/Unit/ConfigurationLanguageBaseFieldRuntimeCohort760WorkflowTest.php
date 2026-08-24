<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #760 runtime-provenance canonical promotion tooling.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageBaseFieldRuntimeCohort760WorkflowTest extends TestCase {

  /**
   * The durable manifest fixes the exact 53-name identity and target state.
   */
  public function testManifestFixesExactCohortAndDistribution(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/docs/evidence/configuration-language-base-field-runtime-cohort-760.yml';
    self::assertFileExists($path);

    $manifest = DrupalYaml::decode((string) file_get_contents($path));
    self::assertIsArray($manifest);
    self::assertSame(760, $manifest['issue'] ?? NULL);
    self::assertSame(609, $manifest['parent_issue'] ?? NULL);
    self::assertSame(53, $manifest['cohort']['total'] ?? NULL);
    self::assertSame(52, $manifest['cohort']['exact_runtime_source_match'] ?? NULL);
    self::assertSame(1, $manifest['cohort']['review_exception'] ?? NULL);
    self::assertSame(0, $manifest['cohort']['unresolved'] ?? NULL);

    $exactNames = $manifest['cohort']['exact_match_names'] ?? NULL;
    self::assertIsArray($exactNames);
    self::assertCount(52, $exactNames);
    self::assertCount(52, array_unique($exactNames));
    sort($exactNames, SORT_STRING);

    $exception = $manifest['cohort']['exception'] ?? NULL;
    self::assertIsArray($exception);
    self::assertSame(
      'core.base_field_override.canvas_page.canvas_page.components',
      $exception['name'] ?? NULL,
    );
    self::assertSame('Composants', $exception['current_config_value'] ?? NULL);
    self::assertSame(
      'Components',
      $exception['runtime_untranslated_source_value'] ?? NULL,
    );
    self::assertSame('Components', $exception['target_base_value'] ?? NULL);
    self::assertSame('Composants', $exception['target_fr_override_value'] ?? NULL);

    $allNames = [...$exactNames, (string) $exception['name']];
    sort($allNames, SORT_STRING);
    self::assertCount(53, $allNames);
    self::assertCount(53, array_unique($allNames));
    self::assertSame(
      '72d2e6f904c99bfd426f6ca58e2c3e7c277beb36eff62eed269a793ff5846535',
      hash('sha256', implode("\n", $allNames) . "\n"),
    );
    self::assertSame(
      'fc71d904d034fb657ce0dc6d61528d2e8e50e9ede1b838ab4d91842f821cfcbf',
      hash('sha256', implode("\n", $exactNames) . "\n"),
    );
    self::assertSame([
      '__none__' => 59,
      'en' => 413,
      'fr' => 122,
      'und' => 1,
    ], $manifest['baseline']['distribution'] ?? NULL);
    self::assertSame([
      '__none__' => 59,
      'en' => 466,
      'fr' => 69,
      'und' => 1,
    ], $manifest['target']['distribution'] ?? NULL);
    self::assertFalse($manifest['constraints']['natural_language_heuristic_used'] ?? TRUE);
    self::assertFalse($manifest['constraints']['config_export_allowed'] ?? TRUE);
    self::assertFalse(
      $manifest['constraints']['config_language_lock_activation_allowed'] ?? TRUE,
    );
  }

  /**
   * The governed route is fixed to #760 and a single bounded generated ref.
   */
  public function testWorkflowIsLiveMainGuardedAndBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'governed-configuration-language-base-field-runtime-cohort-760.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString('github.event.issue.number == 760', $workflow);
    self::assertStringContainsString(
      "'/agency-config-language-base-field-runtime migrate'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$EVENT_DEFAULT_SHA" == "$main_sha" ]]',
      $workflow,
    );
    self::assertStringContainsString('ref: ${{ needs.validate-request.outputs.main_sha }}', $workflow);
    self::assertStringContainsString('persist-credentials: true', $workflow);
    self::assertSame(1, substr_count($workflow, 'contents: write'));
    self::assertStringContainsString(
      '.counts.runtime_base_definition_exact_match_candidate == 52',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.runtime_base_definition_review_required == 1',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.runtime_base_definition_unresolved_review_required == 0',
      $workflow,
    );
    self::assertStringContainsString(
      'core.base_field_override.canvas_page.canvas_page.components',
      $workflow,
    );
    self::assertStringContainsString(
      'feature/760-promote-base-field-runtime-cohort',
      $workflow,
    );
    self::assertStringContainsString(
      'git ls-remote --exit-code --heads origin "$branch"',
      $workflow,
    );
    self::assertStringContainsString(
      'git push origin "HEAD:refs/heads/$branch"',
      $workflow,
    );
    self::assertStringContainsString('ddev drush site:install --existing-config', $workflow);
    self::assertStringContainsString('ddev drush cim -y', $workflow);
    self::assertStringContainsString("grep -Fq 'No differences'", $workflow);
    self::assertStringContainsString(
      '.repository_distribution == {"__none__":59,"en":466,"fr":69,"und":1}',
      $workflow,
    );
    self::assertStringContainsString('ddev exec php -l', $workflow);

    foreach ([
      'workflow_dispatch:',
      'php --version',
      'drush cex',
      'pm:enable config_language_lock',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The writer is strict, textual and refuses all unproven widening.
   */
  public function testWriterIsStrictTextualAndLockFree(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'apply-configuration-language-base-field-runtime-cohort-760.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString("'/^langcode: fr$/m'", $script);
    self::assertStringContainsString("'/^label: Composants$/m'", $script);
    self::assertStringContainsString("'langcode: en'", $script);
    self::assertStringContainsString("'label: Components'", $script);
    self::assertStringContainsString('"label: Composants\\n"', $script);
    self::assertStringContainsString("'runtime_exact_match'", $script);
    self::assertStringContainsString("'localized_runtime_exception'", $script);
    self::assertStringContainsString(
      "'FIFTY_THREE_BASE_FIELD_RUNTIME_CANONICAL_PATCH_PREPARED'",
      $script,
    );
    self::assertStringContainsString(
      "'natural_language_heuristic_used' => FALSE",
      $script,
    );
    self::assertStringContainsString("'config_export_used' => FALSE", $script);
    self::assertStringContainsString(
      "'config_language_lock_activation_allowed' => FALSE",
      $script,
    );

    foreach ([
      'config.storage.sync',
      '->save(',
      'drush cex',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * Rechecks active/repository parity and untranslated runtime source.
   */
  public function testVerifierRechecksRuntimeAndTargetDistribution(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-base-field-runtime-cohort-760-verify.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString("\\Drupal::service('config.typed')", $script);
    self::assertStringContainsString(
      "\\Drupal::service('entity_field.manager')",
      $script,
    );
    self::assertStringContainsString('getBaseFieldDefinitions($entityTypeId)', $script);
    self::assertStringContainsString('getUntranslatedString()', $script);
    self::assertStringContainsString('NestedArray::getValue(', $script);
    self::assertStringContainsString("'Components'", $script);
    self::assertStringContainsString("['label' => 'Composants']", $script);
    self::assertStringContainsString("'verified' => \$verified", $script);
    self::assertStringContainsString("'remaining_fr_review_required' => 69", $script);
    self::assertStringContainsString(
      "'FIFTY_THREE_BASE_FIELD_RUNTIME_CANONICAL_PROMOTIONS_VERIFIED'",
      $script,
    );
    self::assertStringContainsString("'semantic_und_zxx_preserved' => TRUE", $script);
    self::assertStringContainsString(
      "'config_language_lock_enabled_canonically' => FALSE",
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

}

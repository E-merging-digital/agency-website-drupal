<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the governed #715 canonical mechanical migration routes.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageMechanicalCanonicalMigrationWorkflowTest extends TestCase {

  /**
   * The writer route is owner/issue/live-main bounded and branch-only.
   */
  public function testGovernedWriterRouteIsStrictlyBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/'
      . 'governed-configuration-language-mechanical-canonical-migration.yml';
    $workflow = (string) file_get_contents($path);

    self::assertIsArray(DrupalYaml::decode($workflow));
    foreach ([
      "github.event.comment.body == '/agency-config-language-mechanical-canonical migrate'",
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      '[[ "$ISSUE_NUMBER" == \'715\' ]]',
      '[[ "$EVENT_DEFAULT_SHA" == "$main_sha" ]]',
      'contents: write',
      "branch='feature/715-apply-canonical-mechanical-migration'",
      'Refusing to overwrite existing branch',
      '.counts.prepared == 39',
      'git diff --numstat -- config/sync',
      'git diff --name-only -- config/sync/language',
      'git diff --name-only -- config/sync/core.extension.yml',
      'gh issue comment 715',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'workflow_dispatch:',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'drush cex',
      'pm:enable config_language_lock',
      'git push --force',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The writer changes only exact cohort files and preserves YAML semantics.
   */
  public function testCanonicalWriterIsExactAndFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/apply-configuration-language-mechanical-canonical.php';
    $script = (string) file_get_contents($path);

    self::assertIsArray(token_get_all($script, TOKEN_PARSE));
    foreach ([
      "(int) (\$cohort['expected_count'] ?? -1) !== 39",
      "'core.entity_form_display.node.page.default'",
      "'field.storage.node.ai_automator_status'",
      "'langcode'] ?? NULL) !== 'fr'",
      '"langcode: fr\\n"',
      '"langcode: en\\n"',
      '$replacementCount !== 1',
      'unset($beforeWithoutLangcode[\'langcode\'], $afterWithoutLangcode[\'langcode\'])',
      '$beforeWithoutLangcode !== $afterWithoutLangcode',
      "'THIRTY_NINE_MECHANICAL_CANONICAL_PATCH_PREPARED'",
      "'global_langcode_replacement_allowed' => FALSE",
      "'language_override_mutation_allowed' => FALSE",
    ] as $required) {
      self::assertStringContainsString($required, $script);
    }

    foreach ([
      'preg_replace',
      'Yaml::dump',
      'config:export',
      'drush cex',
      'config_language_lock.settings',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The trusted verifier is read-only and requires all canonical gates.
   */
  public function testTrustedCanonicalVerifierRequiresExactEvidence(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflowPath = $root . '/.github/workflows/'
      . 'trusted-configuration-language-mechanical-canonical-verify.yml';
    $runnerPath = $root
      . '/scripts/runner/run-configuration-language-mechanical-canonical-verify.sh';
    $probePath = $root
      . '/scripts/runner/configuration-language-mechanical-canonical-verify.php';

    $workflow = (string) file_get_contents($workflowPath);
    $runner = (string) file_get_contents($runnerPath);
    $probe = (string) file_get_contents($probePath);

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertIsArray(token_get_all($probe, TOKEN_PARSE));

    foreach ([
      "github.event.comment.body == '/agency-config-language-mechanical-canonical verify'",
      '[[ "$ISSUE_NUMBER" == \'715\' ]]',
      '[[ "$EVENT_DEFAULT_SHA" == "$main_sha" ]]',
      'persist-credentials: false',
      'site:install --existing-config',
      'ddev drush cim -y',
      'agency-config-canonical-715-',
      'gh issue comment 715',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      '.counts.cohort_expected == 39',
      '.counts.cohort_verified_en == 39',
      '.counts.material_translatable_leaf_count == 0',
      '.counts.excluded_exceptions_preserved_fr == 2',
      '.counts.problem_count == 0',
      '.verdict == "TWO_EXCEPTIONS_EXPLICITLY_COVERED"',
      'config-status-before.txt',
      'config-status-after.txt',
      'git diff --exit-code -- config/sync',
    ] as $required) {
      self::assertStringContainsString($required, $runner);
    }

    foreach ([
      "'repository_langcode_not_en'",
      "'active_langcode_not_en'",
      "'material_translatable_source_detected'",
      "'excluded_exception_base_not_fr'",
      "'THIRTY_NINE_MECHANICAL_CANONICAL_MIGRATION_VERIFIED'",
      "'read_only' => TRUE",
    ] as $required) {
      self::assertStringContainsString($required, $probe);
    }

    foreach ([$workflow, $runner, $probe] as $surface) {
      foreach ([
        'OPENAI_API_KEY',
        'SSH_PRIVATE_KEY',
        'drush cex',
        'pm:enable config_language_lock',
      ] as $forbidden) {
        self::assertStringNotContainsString($forbidden, $surface);
      }
    }

    $output = [];
    $status = 0;
    exec('bash -n ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
  }

}

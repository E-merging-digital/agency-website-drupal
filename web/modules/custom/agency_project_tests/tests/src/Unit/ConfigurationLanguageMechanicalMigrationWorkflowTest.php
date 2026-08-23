<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded #713 mechanical migration/rollback proof.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageMechanicalMigrationWorkflowTest extends TestCase {

  /**
   * The trusted route remains owner-bound, issue-bound and live-main-only.
   */
  public function testTrustedMechanicalMigrationGatewayIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/'
      . 'trusted-configuration-language-mechanical-migration.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-mechanical prove'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'713\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$EVENT_DEFAULT_SHA" == "$main_sha" ]]',
      $workflow,
    );
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('- self-hosted', $workflow);
    self::assertStringContainsString('- agency', $workflow);
    self::assertStringContainsString('- ddev', $workflow);
    self::assertStringContainsString('site:install --existing-config', $workflow);
    self::assertStringContainsString('ddev drush cim -y', $workflow);
    self::assertStringContainsString('agency-config-mechanical-713-', $workflow);
    self::assertStringContainsString('gh issue comment 713', $workflow);

    foreach ([
      'workflow_dispatch:',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'composer require',
      'drush cex',
      'pm:enable config_language_lock',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The exact 39-object cohort remains anchored to trusted #709 evidence.
   */
  public function testMechanicalCohortIsExactAndExcludesTwoExceptions(): void {
    $root = dirname(DRUPAL_ROOT);
    $cohort = Yaml::parseFile(
      $root . '/docs/evidence/configuration-language-mechanical-cohort-713.yml',
    );

    self::assertSame(39, $cohort['expected_count'] ?? NULL);
    self::assertSame([
      'entity_form_display' => 10,
      'entity_view_display' => 10,
      'field_storage_config' => 5,
      'language_content_settings' => 14,
    ], $cohort['expected_by_type'] ?? NULL);
    self::assertSame(
      'no_material_translatable_source_candidate',
      $cohort['source']['classification'] ?? NULL,
    );
    self::assertSame(32656359172, $cohort['source']['trusted_run_id'] ?? NULL);
    self::assertSame(9497598673, $cohort['source']['artifact_id'] ?? NULL);

    $items = $cohort['items'] ?? [];
    self::assertCount(39, $items);
    $names = array_column($items, 'name');
    self::assertCount(39, array_unique($names));
    self::assertNotContains('core.entity_form_display.node.page.default', $names);
    self::assertNotContains('field.storage.node.ai_automator_status', $names);
  }

  /**
   * The PHP probe uses typed-config and real config entities, then rolls back.
   */
  public function testMechanicalMigrationProbeUsesDrupalApisAndFailsClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $probe = (string) file_get_contents(
      $root . '/scripts/runner/configuration-language-mechanical-migration.php',
    );

    self::assertIsArray(token_get_all($probe, TOKEN_PARSE));
    self::assertStringContainsString("\\Drupal::service('config.typed')", $probe);
    self::assertStringContainsString("\\Drupal::service('config.manager')", $probe);
    self::assertStringContainsString('getEntityTypeIdByName', $probe);
    self::assertStringContainsString('ConfigEntityInterface', $probe);
    self::assertStringContainsString("->set('langcode', 'en')", $probe);
    self::assertStringContainsString("->set('langcode', 'fr')", $probe);
    self::assertStringContainsString('->save()', $probe);
    self::assertStringContainsString("['translatable']", $probe);
    self::assertStringContainsString('unexpected_active_config_mutation', $probe);
    self::assertStringContainsString('language_or_nondefault_collection_changed', $probe);
    self::assertStringContainsString('active_config_not_fully_restored', $probe);
    self::assertStringContainsString(
      'THIRTY_NINE_MECHANICAL_CANDIDATES_MIGRATION_ROLLBACK_PROVEN',
      $probe,
    );

    foreach ([
      'file_put_contents',
      'Yaml::dump',
      'config:set',
      'drush cex',
      'pm:enable',
      'config_language_lock.settings',
      'preg_replace',
    ] as $forbiddenOperation) {
      self::assertStringNotContainsString($forbiddenOperation, $probe);
    }
  }

  /**
   * The shell gate requires all proof counts and a clean rollback.
   */
  public function testMechanicalRunnerRequiresExactPassAndNoRepositoryMutation(): void {
    $root = dirname(DRUPAL_ROOT);
    $runnerPath = $root
      . '/scripts/runner/run-configuration-language-mechanical-migration.sh';
    $runner = (string) file_get_contents($runnerPath);

    foreach ([
      '.counts.cohort_expected == 39',
      '.counts.cohort_classified == 39',
      '.counts.material_translatable_leaf_count == 0',
      '.counts.migrated == 39',
      '.counts.unexpected_mutation_count == 0',
      '.counts.language_override_delta_count == 0',
      '.counts.rollback_restored == 39',
      '.counts.problem_count == 0',
      '.problems == []',
      'config-status-before.txt',
      'config-status-after.txt',
      'git diff --exit-code -- config/sync',
    ] as $required) {
      self::assertStringContainsString($required, $runner);
    }

    foreach ([
      'composer require',
      'drush cex',
      'drush en',
      'pm:enable',
      'config:set',
      'state:set',
    ] as $forbiddenMutation) {
      self::assertStringNotContainsString($forbiddenMutation, $runner);
    }

    $output = [];
    $status = 0;
    exec('bash -n ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
  }

}

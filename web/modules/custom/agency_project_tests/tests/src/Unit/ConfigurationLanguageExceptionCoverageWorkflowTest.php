<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the two explicit EN exception overrides and their trusted proof.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageExceptionCoverageWorkflowTest extends TestCase {

  /**
   * The two EN overrides remain sparse and contain only approved leaves.
   */
  public function testExceptionOverridesAreMinimal(): void {
    $root = dirname(DRUPAL_ROOT);

    $formOverride = Yaml::parseFile(
      $root
      . '/config/sync/language/en/'
      . 'core.entity_form_display.node.page.default.yml',
    );
    self::assertSame([
      'content' => [
        'field_home_components' => [
          'settings' => [
            'title' => 'Paragraph',
            'title_plural' => 'Paragraphs',
          ],
        ],
      ],
    ], $formOverride);

    $statusOverride = Yaml::parseFile(
      $root
      . '/config/sync/language/en/'
      . 'field.storage.node.ai_automator_status.yml',
    );
    self::assertSame([
      'settings' => [
        'allowed_values' => [
          ['label' => 'Pending'],
          ['label' => 'Processing'],
          ['label' => 'Failed'],
          ['label' => 'Finished'],
        ],
      ],
    ], $statusOverride);
  }

  /**
   * The source configs stay FR and retain the approved source values.
   */
  public function testExceptionSourceConfigsRemainUnmigrated(): void {
    $root = dirname(DRUPAL_ROOT);
    $form = Yaml::parseFile(
      $root . '/config/sync/core.entity_form_display.node.page.default.yml',
    );
    $status = Yaml::parseFile(
      $root . '/config/sync/field.storage.node.ai_automator_status.yml',
    );

    self::assertSame('fr', $form['langcode'] ?? NULL);
    self::assertSame(
      'Paragraphe',
      $form['content']['field_home_components']['settings']['title'] ?? NULL,
    );
    self::assertSame(
      'Paragraphes',
      $form['content']['field_home_components']['settings']['title_plural']
        ?? NULL,
    );

    self::assertSame('fr', $status['langcode'] ?? NULL);
    self::assertSame([
      ['value' => 'pending', 'label' => 'Pending'],
      ['value' => 'processing', 'label' => 'Processing'],
      ['value' => 'failed', 'label' => 'Failed'],
      ['value' => 'finished', 'label' => 'Finished'],
    ], $status['settings']['allowed_values'] ?? NULL);
  }

  /**
   * The gateway remains owner-only, issue-only and live-main-only.
   */
  public function testTrustedGatewayIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-exception-coverage.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-exceptions classify'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'711\' ]]',
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
    self::assertStringContainsString(
      'agency-config-exception-coverage-711-',
      $workflow,
    );
    self::assertStringContainsString('gh issue comment 711', $workflow);

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
   * The classifier and runner fail closed without mutating configuration.
   */
  public function testProofIsTypedConfigReadOnlyAndExact(): void {
    $root = dirname(DRUPAL_ROOT);
    $classifierPath = $root
      . '/scripts/runner/configuration-language-exception-coverage.php';
    $runnerPath = $root
      . '/scripts/runner/run-configuration-language-exception-coverage.sh';
    $classifier = (string) file_get_contents($classifierPath);
    $runner = (string) file_get_contents($runnerPath);

    self::assertIsArray(token_get_all($classifier, TOKEN_PARSE));
    self::assertStringContainsString(
      "\\Drupal::service('config.typed')",
      $classifier,
    );
    self::assertStringContainsString(
      "\\Drupal::service('language.config_factory_override')",
      $classifier,
    );
    self::assertStringContainsString('createFromNameAndData', $classifier);
    self::assertStringContainsString('NestedArray::getValue', $classifier);
    self::assertStringContainsString("['translatable']", $classifier);
    self::assertStringContainsString(
      'TWO_EXCEPTIONS_EXPLICITLY_COVERED',
      $classifier,
    );
    self::assertStringContainsString(
      'en_override_not_minimal_or_incomplete',
      $classifier,
    );
    self::assertStringContainsString(
      "'base_langcode_migration_allowed_by_this_proof' => FALSE",
      $classifier,
    );

    self::assertStringContainsString(
      '.counts.material_translatable_leaf_count == 6',
      $runner,
    );
    self::assertStringContainsString(
      '.counts.explicit_en_coverage_count == 6',
      $runner,
    );
    self::assertStringContainsString('.counts.source_equal_count == 4', $runner);
    self::assertStringContainsString('.counts.problem_count == 0', $runner);
    self::assertStringContainsString('config-status-before.txt', $runner);
    self::assertStringContainsString('config-status-after.txt', $runner);
    self::assertStringContainsString('git diff --exit-code -- config/sync', $runner);

    foreach ([
      '->save(',
      '->delete(',
      'drush cex',
      'config:set',
      'state:set',
      'pm:enable',
    ] as $forbiddenMutation) {
      self::assertStringNotContainsString($forbiddenMutation, $classifier);
      self::assertStringNotContainsString($forbiddenMutation, $runner);
    }

    $output = [];
    $status = 0;
    exec('bash -n ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
  }

}

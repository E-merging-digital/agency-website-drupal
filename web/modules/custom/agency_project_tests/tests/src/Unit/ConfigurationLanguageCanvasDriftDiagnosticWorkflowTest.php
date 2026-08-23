<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #728 Canvas drift diagnostic route.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageCanvasDriftDiagnosticWorkflowTest extends TestCase {

  /**
   * The diagnostic probe is read-only and frozen to eight Canvas configs.
   */
  public function testProbeIsReadOnlyAndBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $probe = (string) file_get_contents(
      $root . '/scripts/runner/configuration-language-canvas-drift-diagnostic.php',
    );

    self::assertIsArray(token_get_all($probe, TOKEN_PARSE));
    self::assertStringContainsString("\\Drupal::service('config.storage')", $probe);
    self::assertStringContainsString("\\Drupal::service('config.storage.sync')", $probe);

    foreach ([
      'canvas.component.block.help_block',
      'canvas.component.block.language_block.language_content',
      'canvas.component.block.local_actions_block',
      'canvas.component.block.page_title_block',
      'canvas.component.block.system_branding_block',
      'canvas.component.block.system_breadcrumb_block',
      'canvas.component.block.system_powered_by_block',
      'canvas.component.block.views_block.content_recent-block_1',
    ] as $name) {
      self::assertStringContainsString($name, $probe);
    }

    foreach ([
      '->write(',
      '->delete(',
      'file_put_contents',
      'config:set',
      'pm:enable',
      'OPENAI_API_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $probe);
    }
  }

  /**
   * The trusted route reproduces but never publishes the #720 patch.
   */
  public function testWorkflowIsDiagnosticOnlyAndClassifiesConvergence(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-configuration-language-canvas-drift-diagnostic.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-canvas-drift diagnose'",
      $workflow,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'728\' ]]', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('.counts.prepared == 173', $workflow);
    self::assertStringContainsString('.counts.material_translatable_leaf_count == 930', $workflow);
    self::assertStringContainsString('.counts.config_paths_changed == 353', $workflow);
    self::assertStringContainsString('.counts.different == 8', $workflow);
    self::assertStringContainsString('CANVAS_EIGHT_COMPONENT_DRIFT_CONVERGES', $workflow);
    self::assertStringContainsString('CANVAS_EIGHT_COMPONENT_DRIFT_PERSISTS', $workflow);
    self::assertStringContainsString('CANVAS_EIGHT_COMPONENT_REIMPORT_REJECTED', $workflow);
    self::assertStringContainsString('actions/upload-artifact@v4', $workflow);
    self::assertStringContainsString('gh issue comment 728', $workflow);
    self::assertSame(2, substr_count($workflow, 'site:install --existing-config'));
    self::assertSame(3, substr_count($workflow, 'ddev drush cim -y'));

    foreach ([
      'workflow_dispatch:',
      'drush cex',
      'git push',
      'contents: write',
      'pm:enable config_language_lock',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the exact #733 Canvas stabilization proof route.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageCanvasStabilizationProofWorkflowTest extends TestCase {

  /**
   * The stabilizer is restricted to the exact eight Canvas configs.
   */
  public function testCanvasStabilizerIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $script = (string) file_get_contents(
      $root . '/scripts/runner/stabilize-configuration-language-canvas-components.php',
    );

    self::assertIsArray(token_get_all($script, TOKEN_PARSE));
    self::assertStringContainsString("\\Drupal::service('config.storage')", $script);
    self::assertStringContainsString("\\Drupal::service('config.storage.sync')", $script);
    self::assertStringContainsString('$syncStorage->write($name, $active)', $script);
    self::assertStringContainsString('langcode_preserved_fr', $script);
    self::assertStringContainsString('EIGHT_CANVAS_COMPONENT_STABILIZATION_PATCH_PREPARED', $script);

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
      self::assertStringContainsString($name, $script);
    }

    foreach ([
      'file_put_contents',
      'Yaml::dump',
      'drush cex',
      'config:set',
      'pm:enable',
      'config_language_lock.settings',
      'OPENAI_API_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The trusted route proves third-fresh convergence and never pushes.
   */
  public function testTrustedCanvasStabilizationProofIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-configuration-language-canvas-stabilization-proof.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-canvas-stabilization prove'",
      $workflow,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'733\' ]]', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('contents: read', $workflow);
    self::assertGreaterThanOrEqual(3, substr_count($workflow, 'site:install --existing-config'));
    self::assertStringContainsString('[[ "${#expected_config_paths[@]}" -eq 361 ]]', $workflow);
    self::assertStringContainsString('[[ "${#expected_all_paths[@]}" -eq 362 ]]', $workflow);
    self::assertStringContainsString('.counts.different == 8', $workflow);
    self::assertStringContainsString('.counts.different == 0', $workflow);
    self::assertStringContainsString(
      'EIGHT_CANVAS_COMPONENT_DETERMINISTIC_BASELINE_STABILIZATION_PROVEN',
      $workflow,
    );
    self::assertStringContainsString(
      'ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANONICAL_PROMOTION_VERIFIED',
      $workflow,
    );
    self::assertStringContainsString('actions/upload-artifact@v4', $workflow);
    self::assertStringContainsString('gh issue comment 733', $workflow);

    foreach ([
      'git push ',
      'contents: write',
      'drush cex',
      'pm:enable config_language_lock',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

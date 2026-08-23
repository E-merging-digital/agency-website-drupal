<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded Canvas drift diagnostic route.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageCanvasDriftDiagnosticWorkflowTest extends TestCase {

  /**
   * The diagnostic script is exact and read-only.
   */
  public function testCanvasDriftDiagnosticIsExactAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $script = (string) file_get_contents(
      $root . '/scripts/runner/diagnose-configuration-language-canvas-drift.php',
    );

    self::assertIsArray(token_get_all($script, TOKEN_PARSE));
    self::assertStringContainsString("\\Drupal::service('config.storage.sync')", $script);
    self::assertStringContainsString("\\Drupal::service('config.storage')", $script);
    self::assertStringContainsString('EIGHT_CANVAS_COMPONENT_LANGUAGE_DRIFTS_DIAGNOSED', $script);

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
      'write(',
      'delete(',
      'file_put_contents',
      'config:set',
      'pm:enable',
      'config_language_lock.settings',
      'drush cex',
      'OPENAI_API_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The trusted workflow reproduces locally and never pushes.
   */
  public function testTrustedCanvasDriftRouteIsRepositoryReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-canvas-drift-diagnostic.yml';
    $workflow = (string) file_get_contents($path);

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-canvas-drift diagnose'",
      $workflow,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'729\' ]]', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString(
      'apply-configuration-language-translated-canonical.php',
      $workflow,
    );
    self::assertStringContainsString(
      'diagnose-configuration-language-canvas-drift.php',
      $workflow,
    );
    self::assertGreaterThanOrEqual(
      2,
      substr_count($workflow, 'site:install --existing-config'),
    );
    self::assertStringContainsString(
      'EIGHT_CANVAS_COMPONENT_LANGUAGE_DRIFTS_DIAGNOSED',
      $workflow,
    );
    self::assertStringContainsString('different == 8', $workflow);
    self::assertStringContainsString('actions/upload-artifact@v4', $workflow);
    self::assertStringContainsString('gh issue comment 729', $workflow);

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

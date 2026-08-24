<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #766 Canvas runtime source API proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageCanvasRuntimeSourceApi766Test extends TestCase {

  /**
   * The manifest fixes the exact 30 Canvas component cohort.
   */
  public function testManifestFixesExactCanvasRuntimeCohort(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/docs/evidence/'
      . 'configuration-language-canvas-runtime-source-api-cohort-766.yml';
    self::assertFileExists($path);

    $manifest = DrupalYaml::decode((string) file_get_contents($path));
    self::assertIsArray($manifest);
    self::assertSame(766, $manifest['issue'] ?? NULL);
    self::assertSame(609, $manifest['parent_issue'] ?? NULL);
    self::assertSame('1.10.1', $manifest['baseline']['canvas_version'] ?? NULL);
    self::assertSame(30, $manifest['cohort']['total'] ?? NULL);
    self::assertSame(26, $manifest['cohort']['block'] ?? NULL);
    self::assertSame(4, $manifest['cohort']['sdc'] ?? NULL);

    $names = $manifest['cohort']['names'] ?? NULL;
    self::assertIsArray($names);
    self::assertCount(30, $names);
    self::assertCount(30, array_unique($names));
    sort($names, SORT_STRING);
    self::assertSame(
      'b2ad9dcff4b65e56e2a76efefc55b508cf4012b6aaa18cba0c4879cf2f3dec23',
      hash('sha256', implode("\n", $names) . "\n"),
    );
    self::assertTrue($manifest['constraints']['read_only'] ?? FALSE);
    self::assertFalse($manifest['constraints']['migration_allowed'] ?? TRUE);
    self::assertFalse(
      $manifest['constraints']['source_generation_allowed'] ?? TRUE,
    );
    self::assertFalse(
      $manifest['constraints']['config_entity_creation_allowed'] ?? TRUE,
    );
    self::assertFalse(
      $manifest['constraints']['config_entity_update_allowed'] ?? TRUE,
    );
  }

  /**
   * The probe maps native runtime APIs without executing mutation primitives.
   */
  public function testProbeUsesNativeReadOnlyRuntimeIntrospection(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-canvas-runtime-source-api-766.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString(
      "InstalledVersions::getPrettyVersion('drupal/canvas')",
      $script,
    );
    self::assertStringContainsString(
      "getConfigPrefix() === 'canvas.component'",
      $script,
    );
    self::assertStringContainsString(
      "method_exists(\$entity, 'getComponentSource')",
      $script,
    );
    self::assertStringContainsString(
      '$entity->getComponentSource()',
      $script,
    );
    self::assertStringContainsString(
      "'plugin.manager.block'",
      $script,
    );
    self::assertStringContainsString(
      "'plugin.manager.sdc'",
      $script,
    );
    self::assertStringContainsString(
      "'Drupal\\\\canvas\\\\ComponentSource\\\\ComponentSourceManager'",
      $script,
    );
    self::assertStringContainsString(
      'CANVAS_RUNTIME_SOURCE_API_MAPPED',
      $script,
    );
    self::assertStringContainsString(
      "'source_generation_executed' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'config_entity_creation_executed' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'config_entity_update_executed' => FALSE",
      $script,
    );

    foreach ([
      'ReflectionClass',
      'ReflectionIntersectionType',
      'ReflectionMethod',
      'ReflectionNamedType',
      'ReflectionParameter',
      'ReflectionType',
      'ReflectionUnionType',
    ] as $globalReflectionClass) {
      self::assertStringNotContainsString(
        'use ' . $globalReflectionClass . ';',
        $script,
      );
    }

    foreach ([
      '->save(',
      '->write(',
      '->delete(',
      '->generateComponents(',
      '->createConfigEntity(',
      '->updateConfigEntity(',
      'drush cex',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The trusted route is owner-only, live-main-only and read-only.
   */
  public function testTrustedWorkflowIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-canvas-runtime-source-api-766.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.number == 766',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-canvas-runtime-api map'",
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
      'CANVAS_RUNTIME_SOURCE_API_MAPPED',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.mapped == 30',
      $workflow,
    );
    self::assertStringContainsString('.counts.block == 26', $workflow);
    self::assertStringContainsString('.counts.sdc == 4', $workflow);
    self::assertStringContainsString(
      '.constraints.source_generation_executed == false',
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

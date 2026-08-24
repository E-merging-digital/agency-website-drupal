<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #770 Canvas native source correlation proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageCanvasNativeSourcePayload770Test extends TestCase {

  /**
   * The analyzer reuses the exact #766 cohort and native read-only APIs.
   */
  public function testAnalyzerIsStrictAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-canvas-native-source-payload-770.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString(
      'configuration-language-canvas-runtime-source-api-cohort-766.yml',
      $script,
    );
    self::assertStringContainsString(
      'b2ad9dcff4b65e56e2a76efefc55b508cf4012b6aaa18cba0c4879cf2f3dec23',
      $script,
    );
    self::assertStringContainsString(
      "InstalledVersions::getPrettyVersion('drupal/canvas')",
      $script,
    );
    self::assertStringContainsString(
      '$entity->getComponentSource()',
      $script,
    );
    self::assertStringContainsString(
      '$source->getConfiguration()',
      $script,
    );
    self::assertStringContainsString(
      '$source->getPluginDefinition()',
      $script,
    );
    self::assertStringContainsString(
      '$source->getSourceSpecificComponentId()',
      $script,
    );
    self::assertStringContainsString(
      '$source->generateVersionHash()',
      $script,
    );
    self::assertStringContainsString(
      '$value->getUntranslatedString()',
      $script,
    );
    self::assertStringContainsString(
      "if (\$point['value'] === \$leaf['value'])",
      $script,
    );
    self::assertStringContainsString(
      'CANVAS_NATIVE_SOURCE_PAYLOADS_CORRELATED',
      $script,
    );
    self::assertStringContainsString(
      "'value_presence_authorizes_migration' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'strict_type_and_value_equality_only' => TRUE",
      $script,
    );
    self::assertStringContainsString(
      "'natural_language_heuristic_used' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'fuzzy_matching_used' => FALSE",
      $script,
    );

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
   * The trusted route stays owner-only, live-main-only and read-only.
   */
  public function testTrustedWorkflowIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-canvas-native-source-payload-770.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.number == 770',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-canvas-source correlate'",
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
      'CANVAS_NATIVE_SOURCE_PAYLOADS_CORRELATED',
      $workflow,
    );
    self::assertStringContainsString('.counts.analyzed == 30', $workflow);
    self::assertStringContainsString('.counts.block == 26', $workflow);
    self::assertStringContainsString('.counts.sdc == 4', $workflow);
    self::assertStringContainsString(
      '.counts.source_payload_unresolved == 0',
      $workflow,
    );
    self::assertStringContainsString(
      '.constraints.value_presence_authorizes_migration == false',
      $workflow,
    );
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

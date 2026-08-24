<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #774 Canvas Block native label provenance proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageCanvasBlockNativeLabel774Test extends TestCase {

  /**
   * The probe reuses the exact 26 Block subset from the #766 cohort.
   */
  public function testProbeFixesExactBlockCohort(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-canvas-block-native-label-provenance-774.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString(
      'configuration-language-canvas-runtime-source-api-cohort-766.yml',
      $script,
    );
    self::assertStringContainsString(
      "'canvas.component.block.'",
      $script,
    );
    self::assertStringContainsString(
      "count(\$blockNames) !== 26",
      $script,
    );
    self::assertStringContainsString(
      'e388542385fb7cd490c79ef0783dd1ace930ab1b1db1a0762fd50df783db247c',
      $script,
    );
    self::assertStringContainsString(
      "'candidate_total' => 26",
      $script,
    );
    self::assertStringContainsString(
      "'definition_resolved' => \$definitionResolved",
      $script,
    );
  }

  /**
   * Native Block labels remain source/render/literal evidence, not heuristics.
   */
  public function testProbeUsesNativeBlockManagerAndStrictLabelEvidence(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-canvas-block-native-label-provenance-774.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString("'plugin.manager.block'", $script);
    self::assertStringContainsString(
      '$blockManager->hasDefinition($sourceLocalId)',
      $script,
    );
    self::assertStringContainsString(
      '$blockManager->getDefinition($sourceLocalId, FALSE)',
      $script,
    );
    self::assertStringContainsString(
      '$blockManager->createInstance($sourceLocalId, [])',
      $script,
    );
    self::assertStringContainsString(
      '$adminLabel->getUntranslatedString()',
      $script,
    );
    self::assertStringContainsString(
      '$adminLabel->getArguments()',
      $script,
    );
    self::assertStringContainsString(
      "\$renderTranslation(\$adminLabel, 'fr')",
      $script,
    );
    self::assertStringContainsString(
      "\$renderTranslation(\$adminLabel, 'en')",
      $script,
    );
    self::assertStringContainsString(
      "'strict_equality_only' => TRUE",
      $script,
    );
    self::assertStringContainsString(
      "'source_local_ids_from_runtime_only' => TRUE",
      $script,
    );
    self::assertStringContainsString(
      "'natural_language_heuristic_used' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'automatic_translation_used' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'migration_allowed_by_this_proof' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      'CANVAS_BLOCK_NATIVE_LABEL_PROVENANCE_ANALYZED',
      $script,
    );

    foreach ([
      '->generateComponents(',
      '->build(',
      '->save(',
      '->write(',
      '->delete(',
      'drush cex',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The trusted route is fixed to #774, live main and read-only execution.
   */
  public function testTrustedWorkflowIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-canvas-block-native-label-774.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.number == 774',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-block-labels correlate'",
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
      'CANVAS_BLOCK_NATIVE_LABEL_PROVENANCE_ANALYZED',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.candidate_total == 26',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.definition_resolved == 26',
      $workflow,
    );
    self::assertStringContainsString(
      '.constraints.block_definition_surface == "plugin.manager.block"',
      $workflow,
    );
    self::assertStringContainsString('contents: read', $workflow);
    self::assertStringContainsString('issues: write', $workflow);

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'persist-credentials: true',
      'drush cex',
      'generateComponents(',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

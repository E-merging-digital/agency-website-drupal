<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #772 SDC native metadata provenance proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageSdcNativeMetadata772Test extends TestCase {

  /**
   * The probe is fixed to the four SDC gaps established by #770.
   */
  public function testProbeFixesExactSdcGapCohort(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-sdc-native-metadata-provenance-772.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    foreach ([
      'canvas.component.sdc.emerging_digital.cta',
      'canvas.component.sdc.emerging_digital.hero',
      'canvas.component.sdc.emerging_digital.trust-list',
      'canvas.component.sdc.olivero.teaser',
    ] as $name) {
      self::assertStringContainsString("'$name'", $script);
    }
    self::assertStringContainsString(
      'b1babac65a7a0d0e6e22f776fc26cb486dea0882b2d27d98daed2a8f2f5cbe71',
      $script,
    );
    self::assertStringContainsString(
      "'material_translatable_leaves' => \$totalMaterialLeaves",
      $script,
    );
    self::assertStringContainsString(
      "'component_source_matched_leaves' => \$totalComponentSourceMatched",
      $script,
    );
    self::assertStringContainsString(
      "'pre_sdc_gap_leaves' => \$totalMaterialLeaves - \$totalComponentSourceMatched",
      $script,
    );
    self::assertStringContainsString(
      "'expected' => 36",
      $script,
    );
    self::assertStringContainsString(
      "'expected' => 8",
      $script,
    );
    self::assertStringContainsString(
      "'expected' => 28",
      $script,
    );
  }

  /**
   * Native SDC resolution is read-only and uses runtime source IDs.
   */
  public function testProbeUsesNativeSdcManagerWithoutGeneration(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/'
      . 'configuration-language-sdc-native-metadata-provenance-772.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString("'plugin.manager.sdc'", $script);
    self::assertStringContainsString(
      '$sourceObject->getSourceSpecificComponentId()',
      $script,
    );
    self::assertStringContainsString(
      '$sdcManager->hasDefinition($sourceSpecificId)',
      $script,
    );
    self::assertStringContainsString(
      '$sdcManager->getDefinition($sourceSpecificId, FALSE)',
      $script,
    );
    self::assertStringContainsString(
      '$sourceObject->getConfiguration()',
      $script,
    );
    self::assertStringContainsString(
      '$value->getUntranslatedString()',
      $script,
    );
    self::assertStringContainsString(
      "'strict_type_and_value_equality_only' => TRUE",
      $script,
    );
    self::assertStringContainsString(
      "'source_specific_ids_from_runtime_only' => TRUE",
      $script,
    );
    self::assertStringContainsString(
      "'value_presence_authorizes_migration' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      "'source_generation_executed' => FALSE",
      $script,
    );
    self::assertStringContainsString(
      'SDC_NATIVE_METADATA_PROVENANCE_ANALYZED',
      $script,
    );

    foreach ([
      '->generateComponents(',
      '->save(',
      '->write(',
      '->delete(',
      '->createConfigEntity(',
      '->updateConfigEntity(',
      'drush cex',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $script);
    }
  }

  /**
   * The trusted route is fixed, live-main-only and read-only.
   */
  public function testTrustedWorkflowIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-sdc-native-metadata-772.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.number == 772',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-sdc-metadata correlate'",
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
      'SDC_NATIVE_METADATA_PROVENANCE_ANALYZED',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.candidate_total == 4',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.material_translatable_leaves == 36',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.component_source_matched_leaves == 8',
      $workflow,
    );
    self::assertStringContainsString(
      '.counts.pre_sdc_gap_leaves == 28',
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

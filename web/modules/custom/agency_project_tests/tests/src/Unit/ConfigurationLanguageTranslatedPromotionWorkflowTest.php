<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded #718 translated promotion/rollback proof.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageTranslatedPromotionWorkflowTest extends TestCase {

  /**
   * The 173-object cohort identity remains anchored to final trusted evidence.
   */
  public function testTranslatedCohortIdentityIsFrozen(): void {
    $root = dirname(DRUPAL_ROOT);
    $cohort = Yaml::parseFile(
      $root . '/docs/evidence/configuration-language-translated-cohort-718.yml',
    );

    self::assertSame(173, $cohort['expected_count'] ?? NULL);
    self::assertSame(
      '3908bb3b785091d996e969f67af5d0060b3548d2ec732fb055c748085c0d6547',
      $cohort['names_sha256'] ?? NULL,
    );
    self::assertSame(32655167659, $cohort['sources']['historical_complete_171']['trusted_run'] ?? NULL);
    self::assertSame(9497280060, $cohort['sources']['historical_complete_171']['artifact_id'] ?? NULL);
    self::assertSame(171, $cohort['sources']['historical_complete_171']['complete'] ?? NULL);
    self::assertSame(0, $cohort['sources']['historical_complete_171']['partial'] ?? NULL);
    self::assertSame(0, $cohort['sources']['historical_complete_171']['unresolved'] ?? NULL);
    self::assertSame(32657297513, $cohort['sources']['technical_exceptions_2']['trusted_run'] ?? NULL);
    self::assertSame(9497831272, $cohort['sources']['technical_exceptions_2']['artifact_id'] ?? NULL);
    self::assertSame([
      'core.entity_form_display.node.page.default',
      'field.storage.node.ai_automator_status',
    ], $cohort['required_exception_names'] ?? NULL);
    self::assertSame([
      'block.block.emerging_digital_footer_menu',
      'views.view.blog',
    ], $cohort['expected_preexisting_fr_overrides_outside_cohort'] ?? NULL);
  }

  /**
   * The PHP probe uses typed-config and Drupal configuration APIs fail-closed.
   */
  public function testTranslatedPromotionProbeUsesDrupalApisAndRollsBack(): void {
    $root = dirname(DRUPAL_ROOT);
    $probe = (string) file_get_contents(
      $root . '/scripts/runner/configuration-language-translated-promotion.php',
    );

    self::assertIsArray(token_get_all($probe, TOKEN_PARSE));
    self::assertStringContainsString("\\Drupal::service('config.typed')", $probe);
    self::assertStringContainsString("\\Drupal::service('config.factory')", $probe);
    self::assertStringContainsString("\\Drupal::service('language.config_factory_override')", $probe);
    self::assertStringContainsString("\$newBase['langcode'] = 'en'", $probe);
    self::assertStringContainsString("getOverride('fr', \$name)->setData", $probe);
    self::assertStringContainsString("getOverride('en', \$name)->delete()", $probe);
    self::assertStringContainsString("getEditable(\$name)->setData(\$base)->save()", $probe);
    self::assertStringContainsString('candidate_identity_hash_mismatch', $probe);
    self::assertStringContainsString('material_translatable_source_not_covered', $probe);
    self::assertStringContainsString('en_override_contains_non_translatable_or_unresolved_path', $probe);
    self::assertStringContainsString('default_collection_does_not_match_expected_promotion', $probe);
    self::assertStringContainsString('language_collections_do_not_match_expected_promotion', $probe);
    self::assertStringContainsString('final_configuration_fingerprint_mismatch', $probe);
    self::assertStringContainsString(
      'ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANDIDATES_PROMOTION_ROLLBACK_PROVEN',
      $probe,
    );

    foreach ([
      'file_put_contents',
      'Yaml::dump',
      'drush cex',
      'pm:enable',
      'config_language_lock.settings',
      'preg_replace',
      'OPENAI_API_KEY',
    ] as $forbiddenOperation) {
      self::assertStringNotContainsString($forbiddenOperation, $probe);
    }
  }

  /**
   * The shell runner requires a full pass and exact rollback.
   */
  public function testTranslatedPromotionRunnerRequiresExactProof(): void {
    $root = dirname(DRUPAL_ROOT);
    $runnerPath = $root
      . '/scripts/runner/run-configuration-language-translated-promotion.sh';
    $runner = (string) file_get_contents($runnerPath);

    foreach ([
      '.cohort.expected_count == 173',
      '.cohort.actual_count == 173',
      '.counts.cohort_classified == 173',
      '.counts.material_translatable_leaf_count == .counts.explicit_en_coverage_count',
      '.counts.promoted == 173',
      '.counts.en_overrides_removed == 173',
      '.counts.unexpected_default_mutation_count == 0',
      '.counts.rollback_restored == 173',
      '.counts.problem_count == 0',
      '.baseline_fingerprint == .final_fingerprint',
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

  /**
   * The trusted route stays owner, issue and live-main bound.
   */
  public function testTrustedTranslatedPromotionGatewayIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/'
      . 'trusted-configuration-language-translated-promotion.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-translated-promotion prove'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'718\' ]]',
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
    self::assertStringContainsString('agency-config-translated-718-', $workflow);
    self::assertStringContainsString('gh issue comment 718', $workflow);

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

}

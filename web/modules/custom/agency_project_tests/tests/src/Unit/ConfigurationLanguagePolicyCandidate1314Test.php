<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects #1314 historical evidence after Recommendation C adoption.
 *
 * @group agency_project_tests
 * @group configuration_language_candidate_1314
 */
final class ConfigurationLanguagePolicyCandidate1314Test extends TestCase {

  /**
   * Current policy adopts Recommendation C while preserving historical intent.
   */
  public function testCurrentPolicyAdoptsRecommendationC(): void {
    $root = dirname(DRUPAL_ROOT);

    $lock = Yaml::parseFile(
      $root . '/config/sync/config_language_lock.settings.yml',
    );
    self::assertSame('fr', $lock['locked_langcode'] ?? NULL);
    self::assertTrue($lock['follow_site_default'] ?? FALSE);

    $policy = Yaml::parseFile($root . '/docs/configuration-language-policy.yml');
    self::assertSame(2, $policy['schema_version'] ?? NULL);
    self::assertSame('enforced', $policy['status'] ?? NULL);
    self::assertSame(
      'en',
      $policy['historical_configuration']['canonical_base_language_intent'] ?? NULL,
    );
    self::assertSame('site_default', $policy['configuration_writes']['strategy'] ?? NULL);
    self::assertSame('fr', $policy['configuration_writes']['resolved_langcode'] ?? NULL);
    self::assertTrue($policy['configuration_writes']['follow_site_default'] ?? FALSE);
  }

  /**
   * The #1314 evidence keeps the accepted decision and migration scope intact.
   */
  public function testEvidenceRecordsDecisionAndMigrationScope(): void {
    $root = dirname(DRUPAL_ROOT);
    $evidence = (string) file_get_contents(
      $root . '/docs/evidence/configuration-language-site-default-candidate-1314.md',
    );

    foreach ([
      'CANDIDATE_A_CANVAS_VERDICT = PASS',
      'EXISTING_CONFIG_AUTOREWRITE = NO',
      'TRANSLATION_PRESERVATION = PASS',
      'UND_ZXX_PRESERVATION = PASS',
      'CANONICAL_LANGUAGE_POLICY_RECOMMENDATION = C',
      'CONFIG_OBJECT_BULK_MIGRATION_REQUIRED = YES',
      'LOCK_SETTINGS_CHANGE_REQUIRED = YES',
      'POLICY_SCHEMA_CHANGE_REQUIRED = YES',
      'HISTORICAL_EVIDENCE_IMMUTABLE',
      'OBSOLETE_ASSERTION_TO_RETIRE',
      'REUSABLE_TEST_TO_REBASELINE',
    ] as $required) {
      self::assertStringContainsString($required, $evidence, $required);
    }
  }

  /**
   * Historical #609 proof stays branch-gated and EN-oriented.
   */
  public function testHistorical609WorkflowRemainsImmutableEvidence(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/config-language-lock-609-hosted-ddev.yml',
    );

    self::assertStringContainsString(
      "github.event.pull_request.head.ref == 'feature/609-configuration-language-audit'",
      $workflow,
    );
    self::assertStringContainsString('.locked_langcode == "en"', $workflow);
    self::assertStringContainsString('.follow_site_default == false', $workflow);
  }

}

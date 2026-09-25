<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the durable Agency configuration-language policy.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageGovernanceTest extends TestCase {

  /**
   * The machine-readable policy must stay explicit and Preflight-observable.
   */
  public function testPolicyContractIsExplicit(): void {
    $root = dirname(DRUPAL_ROOT);
    $policy = Yaml::parseFile($root . '/docs/configuration-language-policy.yml');

    self::assertSame(2, $policy['schema_version'] ?? NULL);
    self::assertSame('agency-configuration-language-v2', $policy['policy_id'] ?? NULL);
    self::assertSame('enforced', $policy['status'] ?? NULL);
    self::assertSame('fr', $policy['site_default_language'] ?? NULL);
    self::assertSame(['fr', 'en'], $policy['site_languages'] ?? NULL);

    self::assertSame(
      'en',
      $policy['historical_configuration']['canonical_base_language_intent'] ?? NULL,
    );
    self::assertSame(609, $policy['historical_configuration']['evidence_issue'] ?? NULL);
    self::assertSame(1316, $policy['historical_configuration']['migration_issue'] ?? NULL);

    self::assertSame('site_default', $policy['configuration_writes']['strategy'] ?? NULL);
    self::assertSame('fr', $policy['configuration_writes']['resolved_langcode'] ?? NULL);
    self::assertTrue($policy['configuration_writes']['follow_site_default'] ?? FALSE);
    self::assertSame(
      ['fr', 'en'],
      $policy['managed_configuration_translation_languages'] ?? NULL,
    );
    self::assertSame([
      'config/sync/language/fr',
      'config/sync/language/en',
    ], $policy['current_configuration_translation_directories'] ?? NULL);
    self::assertTrue($policy['enforce_consistency'] ?? FALSE);

    self::assertSame('use_drupal', $policy['enforcement']['strategy'] ?? NULL);
    self::assertSame(
      'drupal/config_language_lock',
      $policy['enforcement']['implementation'] ?? NULL,
    );
    self::assertSame('1.0.0', $policy['enforcement']['minimum_release'] ?? NULL);
    self::assertSame(
      'site_default',
      $policy['enforcement']['locked_langcode_strategy'] ?? NULL,
    );
    self::assertSame('fr', $policy['enforcement']['resolved_locked_langcode'] ?? NULL);
    self::assertTrue($policy['enforcement']['follow_site_default'] ?? FALSE);
    self::assertSame(609, $policy['enforcement']['adoption_issue'] ?? NULL);
    self::assertSame(1316, $policy['enforcement']['migration_issue'] ?? NULL);

    self::assertArrayNotHasKey('canonical_configuration_language', $policy);
    self::assertArrayNotHasKey('target_configuration_translation_languages', $policy);
    self::assertArrayNotHasKey('candidate', $policy['enforcement']);
    self::assertArrayNotHasKey('target_locked_langcode', $policy['enforcement']);

    self::assertSame([
      'manual_admin',
      'module_install',
      'theme_install',
      'recipe',
      'config_action',
      'canvas',
      'drupal_ai',
      'automated_agent',
    ], $policy['transformation_sources'] ?? NULL);

    foreach ([
      'require_before_snapshot',
      'require_after_snapshot',
      'require_diff',
      'require_independent_verdict',
    ] as $requirement) {
      self::assertTrue($policy['evidence'][$requirement] ?? FALSE);
    }

    self::assertSame('none', $policy['preflight']['coupling'] ?? NULL);
    self::assertSame(
      'observable_policy_v2_and_snapshots',
      $policy['preflight']['contract'] ?? NULL,
    );
    self::assertTrue(
      $policy['core_transition']['remove_contrib_when_core_sufficient'] ?? FALSE,
    );
  }

  /**
   * The enforced lock preserves editorial and semantic language invariants.
   */
  public function testEnforcedLockPreservesLanguageSemantics(): void {
    $root = dirname(DRUPAL_ROOT);
    $policy = Yaml::parseFile($root . '/docs/configuration-language-policy.yml');

    self::assertSame('enforced', $policy['status'] ?? NULL);
    self::assertTrue($policy['enforce_consistency'] ?? FALSE);

    $extensions = Yaml::parseFile($root . '/config/sync/core.extension.yml');
    self::assertSame(0, $extensions['module']['config_language_lock'] ?? NULL);

    $lock = Yaml::parseFile(
      $root . '/config/sync/config_language_lock.settings.yml',
    );
    self::assertSame('fr', $lock['locked_langcode'] ?? NULL);
    self::assertTrue($lock['follow_site_default'] ?? FALSE);

    $site = Yaml::parseFile($root . '/config/sync/system.site.yml');
    self::assertSame('fr', $site['langcode'] ?? NULL);
    self::assertSame('fr', $site['default_langcode'] ?? NULL);

    foreach ([
      '/config/sync/language/fr',
      '/config/sync/language/en',
    ] as $translationDirectory) {
      self::assertDirectoryExists($root . $translationDirectory);
    }

    foreach (['und', 'zxx'] as $id) {
      $language = Yaml::parseFile(
        $root . '/config/sync/language.entity.' . $id . '.yml',
      );
      self::assertSame($id, $language['id'] ?? NULL);
      self::assertTrue((bool) ($language['locked'] ?? FALSE));
    }
  }

  /**
   * Policy ownership must be discoverable from durable agent documentation.
   */
  public function testDurableDocumentationOwnsTheInvariant(): void {
    $root = dirname(DRUPAL_ROOT);
    $agents = (string) file_get_contents($root . '/AGENTS.md');
    $architecture = (string) file_get_contents(
      $root . '/docs/configuration-language-governance.md',
    );
    $adr = (string) file_get_contents(
      $root . '/docs/decisions/ADR-002-configuration-language-governance.md',
    );

    foreach ([$agents, $architecture, $adr] as $document) {
      self::assertStringContainsString(
        'docs/configuration-language-policy.yml',
        $document,
      );
    }

    foreach (['Recipes', 'Canvas', 'Drupal AI', 'Preflight'] as $surface) {
      self::assertStringContainsString($surface, $architecture);
    }

    self::assertStringContainsString('ACTIVE / ENFORCED', $architecture);
    self::assertStringContainsString('migration_required', $adr);
    self::assertStringContainsString('drupal/config_language_lock', $adr);
    self::assertStringContainsString('Preflight', $adr);
  }

}

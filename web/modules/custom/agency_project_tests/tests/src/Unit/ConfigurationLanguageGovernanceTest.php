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

    self::assertSame(1, $policy['schema_version'] ?? NULL);
    self::assertSame(
      'agency-configuration-language-v1',
      $policy['policy_id'] ?? NULL,
    );
    self::assertSame('adopted', $policy['status'] ?? NULL);
    self::assertSame('en', $policy['canonical_configuration_language'] ?? NULL);
    self::assertSame('fr', $policy['site_default_language'] ?? NULL);
    self::assertSame(['fr', 'en'], $policy['site_languages'] ?? NULL);
    self::assertSame(
      ['fr'],
      $policy['target_configuration_translation_languages'] ?? NULL,
    );
    self::assertTrue($policy['enforce_consistency'] ?? FALSE);

    self::assertSame(
      'use_drupal',
      $policy['enforcement']['strategy'] ?? NULL,
    );
    self::assertSame(
      'drupal/config_language_lock',
      $policy['enforcement']['candidate'] ?? NULL,
    );
    self::assertSame(
      '1.0.0',
      $policy['enforcement']['minimum_release'] ?? NULL,
    );
    self::assertSame(
      'en',
      $policy['enforcement']['target_locked_langcode'] ?? NULL,
    );
    self::assertFalse($policy['enforcement']['follow_site_default'] ?? TRUE);
    self::assertSame(609, $policy['enforcement']['adoption_issue'] ?? NULL);

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
      'observable_policy_and_snapshots',
      $policy['preflight']['contract'] ?? NULL,
    );
    self::assertTrue(
      $policy['core_transition']['remove_contrib_when_core_sufficient'] ?? FALSE,
    );
  }

  /**
   * The adopted lock preserves editorial and semantic language exceptions.
   */
  public function testAdoptedLockPreservesLanguageSemantics(): void {
    $root = dirname(DRUPAL_ROOT);
    $policy = Yaml::parseFile($root . '/docs/configuration-language-policy.yml');

    self::assertSame('adopted', $policy['status'] ?? NULL);
    self::assertTrue($policy['enforce_consistency'] ?? FALSE);

    $extensions = Yaml::parseFile($root . '/config/sync/core.extension.yml');
    self::assertSame(
      0,
      $extensions['module']['config_language_lock'] ?? NULL,
    );

    $lock = Yaml::parseFile(
      $root . '/config/sync/config_language_lock.settings.yml',
    );
    self::assertSame('en', $lock['locked_langcode'] ?? NULL);
    self::assertFalse($lock['follow_site_default'] ?? TRUE);

    $site = Yaml::parseFile($root . '/config/sync/system.site.yml');
    self::assertSame('fr', $site['langcode'] ?? NULL);
    self::assertSame('fr', $site['default_langcode'] ?? NULL);

    $canvasFolder = Yaml::parseFile(
      $root
      . '/config/sync/canvas.folder.'
      . '0d5d5129-0d2e-41f3-a6d5-0211018bd59f.yml',
    );
    self::assertSame('fr', $canvasFolder['langcode'] ?? NULL);

    $coreViewMode = Yaml::parseFile(
      $root . '/config/sync/core.entity_view_mode.user.token.yml',
    );
    self::assertSame('en', $coreViewMode['langcode'] ?? NULL);

    $footerMenu = Yaml::parseFile(
      $root . '/config/sync/system.menu.footer.yml',
    );
    self::assertSame('und', $footerMenu['langcode'] ?? NULL);

    foreach ([
      '/config/sync/language/fr',
      '/config/sync/language/en',
    ] as $translationDirectory) {
      self::assertDirectoryExists($root . $translationDirectory);
    }

    foreach ([
      '/config/sync/language.entity.und.yml',
      '/config/sync/language.entity.zxx.yml',
    ] as $lockedLanguage) {
      self::assertFileExists($root . $lockedLanguage);
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

    self::assertStringContainsString('migration_required', $adr);
    self::assertStringContainsString('drupal/config_language_lock', $adr);
    self::assertStringContainsString('Preflight', $adr);
  }

}

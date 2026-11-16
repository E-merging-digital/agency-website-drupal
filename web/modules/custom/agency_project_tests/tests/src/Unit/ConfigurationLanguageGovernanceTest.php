<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\emerging_digital_content\StackMiddleware\NlVisibilityGuard;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Drupal\Component\Serialization\Yaml as DrupalYaml;
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
    self::assertSame(['fr', 'en', 'nl'], $policy['site_languages'] ?? NULL);

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

    // NL source configuration does not imply Dutch content.
    $dutch = Yaml::parseFile($root . '/config/sync/language.entity.nl.yml');
    self::assertSame('nl', $dutch['id'] ?? NULL);
    self::assertSame('fr', $dutch['langcode'] ?? NULL);
    self::assertTrue($dutch['status'] ?? FALSE);
    self::assertSame('ltr', $dutch['direction'] ?? NULL);
    $negotiation = Yaml::parseFile(
      $root . '/config/sync/language.negotiation.yml',
    );
    self::assertSame('path_prefix', $negotiation['url']['source'] ?? NULL);
    // The NL prefix is source-only; existing middleware blocks NL routes.
    self::assertSame(
      ['en' => 'en', 'fr' => 'fr', 'nl' => 'nl'],
      $negotiation['url']['prefixes'] ?? NULL,
    );
    self::assertSame(
      ['en' => '', 'fr' => '', 'nl' => ''],
      $negotiation['url']['domains'] ?? NULL,
    );
    self::assertSame('site_default', $negotiation['selected_langcode'] ?? NULL);
    self::assertDirectoryExists($root . '/config/sync/language/nl');

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
   * NL visibility is enforced before Drupal's anonymous page cache.
   */
  public function testNlMiddlewareBlocksAliasesAndPreservesNativeEditAccess(): void {
    $root = dirname(DRUPAL_ROOT);
    $services = Yaml::parseFile(
      $root . '/web/modules/custom/emerging_digital_content/emerging_digital_content.services.yml',
    );
    $middleware = $services['services']['emerging_digital_content.nl_visibility_guard'];
    self::assertSame(220, $middleware['tags'][0]['priority']);
    self::assertSame('http_middleware', $middleware['tags'][0]['name']);

    $inner = new class implements HttpKernelInterface {

      /**
       * {@inheritdoc}
       */
      public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
        return new Response('NATIVE_ROUTE');
      }

    };
    $guard = new NlVisibilityGuard($inner);
    foreach ([
      '/nl',
      '/nl/',
      '/nl/node/5',
      '/nl/node/6',
      '/nl/diensten',
      '/nl/wettelijke-vermeldingen',
      '/nl/privacyverklaring',
      '/nl/cookiebeleid',
      '/nl/contact',
      '/nl/not-translated',
    ] as $path) {
      $response = $guard->handle(Request::create($path));
      self::assertSame(404, $response->getStatusCode(), $path);
      self::assertSame('blocked', $response->headers->get('X-Agency-NL-Guard'));
      self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }
    foreach (['/fr', '/en', '/nl/node/6/edit', '/nl/node/5/edit'] as $path) {
      $response = $guard->handle(Request::create($path));
      self::assertSame(200, $response->getStatusCode(), $path);
      self::assertSame('NATIVE_ROUTE', $response->getContent());
    }
  }

  /**
   * NL Webform keeps native field identities and requirements.
   */
  public function testNlContactTranslationPreservesFieldSemantics(): void {
    $root = dirname(DRUPAL_ROOT);
    $base = Yaml::parseFile($root . '/config/sync/webform.webform.contact.yml');
    $nl = Yaml::parseFile($root . '/config/sync/language/nl/webform.webform.contact.yml');
    $base_elements = DrupalYaml::decode($base['elements']);
    $nl_elements = DrupalYaml::decode($nl['elements']);
    self::assertSame(array_keys($base_elements), array_keys($nl_elements));
    foreach ($base_elements as $key => $field) {
      foreach (['#type', '#required', '#access'] as $property) {
        self::assertSame($field[$property] ?? NULL, $nl_elements[$key][$property] ?? NULL);
      }
    }
    self::assertSame('Uw naam', $nl_elements['name']['#title']);
    self::assertSame('Bericht verzenden', $nl_elements['actions']['#submit__label']);
    self::assertStringContainsString('/nl/privacyverklaring', $nl_elements['rgpd_consent']['#description']);
    self::assertStringNotContainsString('/fr/', $nl['elements']);
    self::assertStringNotContainsString('/en/', $nl['elements']);
    self::assertArrayNotHasKey('handlers', $nl);
    self::assertFileExists($root . '/config/sync/language/nl/block.block.emerging_digital_footer_branding.yml');
    self::assertFileExists($root . '/config/sync/language/nl/block.block.emerging_digital_footer_menu.yml');
  }

  /**
   * NL legal drafts use the existing Governed Content catalog, not new IDs.
   */
  public function testNlGovernedLegalDraftSourceIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $base = $root . '/web/modules/custom/emerging_digital_content/content_sync';
    $catalog = Yaml::parseFile($base . '/catalog.yml');
    $expected = [
      'mentions-legales' => '/wettelijke-vermeldingen',
      'politique-confidentialite' => '/privacyverklaring',
      'politique-cookies' => '/cookiebeleid',
    ];
    self::assertSame(array_keys($expected),
      array_column($catalog['contents'], 'id'));
    foreach ($catalog['contents'] as $entry) {
      $id = $entry['id'];
      self::assertSame($expected[$id], $entry['translations']['nl']['alias']);
      $source = Yaml::parseFile($base . '/node/' . $id . '.yml');
      self::assertSame(['fr', 'en', 'nl'],
        array_keys($source['translations']));
      self::assertSame(['fr', 'en', 'nl'],
        array_keys($source['components'][0]['translations']));
      self::assertNotEmpty(
        $source['components'][0]['translations']['nl']['fields']['field_text'][0]['value']
      );
    }

    $hero = (string) file_get_contents(
      $root . '/web/themes/custom/emerging_digital/components/hero/hero.twig'
    );
    self::assertStringContainsString('home_nl', $hero);
    self::assertStringContainsString(
      'Uw digitaal project verdient meer dan een website.',
      $hero,
    );

    $switcher = (string) file_get_contents(
      $root . '/web/modules/custom/emerging_digital_content/src/Plugin/Block/LanguageSwitcherBlock.php'
    );
    self::assertStringContainsString(
      'if ($langcode === \'nl\' && $nl_prefix !== \'nl\')',
      $switcher,
    );
  }

  /**
   * Cookie consent NL copy stays local and /nl remains visibility-gated.
   */
  public function testNlCookieConsentReviewGates(): void {
    $root = dirname(DRUPAL_ROOT);
    $script = (string) file_get_contents(
      $root . '/web/modules/custom/emerging_digital_cookie_consent/js/cookie-consent.js',
    );
    self::assertStringContainsString("startsWith('nl')", $script);
    self::assertStringContainsString('Cookievoorkeuren', $script);
    self::assertStringContainsString('showPreferencesShortcut();', $script);
    // The legacy theme helper must not nest policy anchors in CMP buttons.
    $theme = (string) file_get_contents(
      $root . '/web/themes/custom/emerging_digital/js/main.js',
    );
    self::assertStringContainsString(
      "banner.closest('#ed-cookie-banner, #ed-cookie-preferences-shortcut')",
      $theme,
    );
    self::assertStringContainsString(
      "modal.closest('#ed-cookie-preferences-modal')",
      $theme,
    );

    $module = (string) file_get_contents(
      $root . '/web/modules/custom/emerging_digital_cookie_consent/emerging_digital_cookie_consent.module',
    );
    self::assertStringContainsString('getPathByAlias(', $module);
    self::assertStringContainsString('isPublished()', $module);
    self::assertStringContainsString(
      "isset(\$aliases[\$langcode], \$prefixes[\$langcode])",
      $module,
    );
  }

  /**
   * Special locked languages converge through one bounded Drupal manager call.
   */
  public function testSpecialLanguageDeploymentReconcilerIsBoundedAndIdempotent(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/reconcile-config-language-special-entities-1318.php';
    self::assertFileExists($path);

    $helper = (string) file_get_contents($path);

    foreach ([
      "getenv('AGENCY_CONFIG_LANGUAGE_SPECIAL_RECONCILE') !== '1'",
      "if (\$siteDefault !== 'fr')",
      "\$lockedLangcode !== 'fr'",
      "\$followSiteDefault !== TRUE",
      "'language.entity.und' => 'und'",
      "'language.entity.zxx' => 'zxx'",
      "!in_array(\$technicalLangcode, ['en', 'fr'], TRUE)",
      "\$manager->updateConfigForLockedLanguageSwitch(array_keys(\$targets))",
      "(\$data['langcode'] ?? NULL) !== 'fr'",
      "'config_values_exposed' => FALSE",
    ] as $required) {
      self::assertStringContainsString($required, $helper, $required);
    }

    self::assertSame(
      1,
      substr_count(
        $helper,
        '$manager->updateConfigForLockedLanguageSwitch(array_keys($targets))',
      ),
    );
    self::assertSame(1, substr_count($helper, "'language.entity.und' => 'und'"));
    self::assertSame(1, substr_count($helper, "'language.entity.zxx' => 'zxx'"));

    foreach ([
      '$storage->listAll()',
      'listAll(',
      'createCollection(',
      'config.storage.sync',
      "getEditable('config_language_lock.settings')",
      "->set('locked_langcode'",
      "->set('follow_site_default'",
      'config:import',
      'config:export',
      "['label']",
      "['translations']",
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $helper, $forbidden);
    }

    self::assertStringNotContainsString(
      "'language.en'",
      $helper,
    );
    self::assertStringNotContainsString(
      "'language.fr'",
      $helper,
    );

    foreach ([
      "'schema_version' => 1",
      "'status' => 'PASS'",
      "'mechanism' => 'config_language_lock_special_entity_reconcile'",
      "'targets' => array_keys(\$targets)",
      "'before_technical_langcodes' => \$before",
      "'after_technical_langcodes' => \$after",
      "'manager_stats' => \$boundedStats",
      "'site_default_language' => \$siteDefault",
      "'locked_langcode' => \$lockedLangcode",
      "'follow_site_default' => \$followSiteDefault",
      "'config_values_exposed' => FALSE",
    ] as $bounded) {
      self::assertStringContainsString($bounded, $helper, $bounded);
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

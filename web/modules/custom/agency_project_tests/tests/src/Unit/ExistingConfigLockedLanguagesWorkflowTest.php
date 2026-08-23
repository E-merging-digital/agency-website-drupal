<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the trusted existing-config locked-language proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ExistingConfigLockedLanguagesWorkflowTest extends TestCase {

  /**
   * The route stays owner-only and fixed to issue #696.
   */
  public function testRouteIsFixedAndOwnerOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-existing-config-locked-languages.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString(
      'github.event.issue.number == 696',
      $workflow,
    );
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-lock "
        . "prove-existing-config'",
      $workflow,
    );
    self::assertStringContainsString(
      "[[ \"\$GITHUB_ACTOR\" == 'E-merging-digital' ]]",
      $workflow,
    );
    self::assertStringContainsString(
      "[[ \"\$ISSUE_NUMBER\" == '696' ]]",
      $workflow,
    );
    self::assertStringContainsString(
      "[[ \"\$EVENT_DEFAULT_SHA\" == \"\$main_sha\" ]]",
      $workflow,
    );
  }

  /**
   * The DDEV proof covers reconstruction, enforcement and restoration.
   */
  public function testProofCoversExistingConfigAndLockedLanguages(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-existing-config-locked-languages.yml';
    $workflow = (string) file_get_contents($path);

    self::assertStringContainsString('self-hosted', $workflow);
    self::assertStringContainsString('agency', $workflow);
    self::assertStringContainsString('ddev', $workflow);
    self::assertStringContainsString(
      'ddev drush site:install --existing-config',
      $workflow,
    );
    self::assertStringContainsString(
      'config-status-post-install.txt',
      $workflow,
    );
    self::assertSame(2, substr_count($workflow, 'ddev drush cim -y'));
    self::assertStringContainsString(
      'ddev drush pm:enable config_language_lock -y',
      $workflow,
    );
    self::assertStringContainsString(
      "set('locked_langcode', 'en')",
      $workflow,
    );
    self::assertStringContainsString(
      "set('follow_site_default', FALSE)",
      $workflow,
    );
    self::assertStringContainsString('enabled.json', $workflow);
    self::assertSame(
      2,
      substr_count($workflow, 'map_values(del(.label))'),
    );
    self::assertStringContainsString(
      'ConfigurableLanguage::load',
      $workflow,
    );
    self::assertStringContainsString(
      'ddev drush pm:uninstall config_language_lock -y',
      $workflow,
    );
    self::assertStringContainsString('No differences', $workflow);
    self::assertStringContainsString(
      '.languages.und.locked == true',
      $workflow,
    );
    self::assertStringContainsString(
      '.languages.zxx.locked == true',
      $workflow,
    );
    self::assertStringContainsString(
      '.languages.und.weight == 2',
      $workflow,
    );
    self::assertStringContainsString(
      '.languages.zxx.weight == 3',
      $workflow,
    );
    self::assertStringContainsString(
      'EXISTING_CONFIG_AND_LOCKED_LANGUAGES_SAFE',
      $workflow,
    );
    self::assertStringContainsString(
      'ddev delete --omit-snapshot --yes',
      $workflow,
    );

    $enabledPosition = strpos($workflow, '> artifacts/existing-config-locked-languages/enabled.json');
    $savePosition = strpos($workflow, 'ConfigurableLanguage::load');
    $enforcedPosition = strpos($workflow, '> artifacts/existing-config-locked-languages/enforced.json');
    self::assertNotFalse($enabledPosition);
    self::assertNotFalse($savePosition);
    self::assertNotFalse($enforcedPosition);
    self::assertTrue(
      $enabledPosition < $savePosition && $savePosition < $enforcedPosition,
      'Locale footprint must be captured before native saves are measured.',
    );

    self::assertStringNotContainsString('drush cex', $workflow);
    self::assertStringNotContainsString('OPENAI_API_KEY', $workflow);
    self::assertStringNotContainsString('deploy-production', $workflow);
    self::assertStringNotContainsString('ssh ', $workflow);
  }

  /**
   * The machine-readable helper stays strictly read-only.
   */
  public function testLockedLanguageStateHelperIsReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/scripts/runner/locked-language-state.php';
    self::assertFileExists($path);

    $script = (string) file_get_contents($path);
    self::assertStringContainsString("['und', 'zxx']", $script);
    self::assertStringContainsString("'locked'", $script);
    self::assertStringContainsString("'weight'", $script);
    self::assertStringContainsString("'langcode'", $script);
    self::assertStringContainsString("'site_default_language'", $script);
    self::assertStringContainsString(
      "'config_language_lock_enabled'",
      $script,
    );
    self::assertStringContainsString("'lock_settings'", $script);
    self::assertStringContainsString('$storage->read(', $script);
    self::assertStringNotContainsString('->save(', $script);
    self::assertStringNotContainsString('->write(', $script);
    self::assertStringNotContainsString('->delete(', $script);
  }

}

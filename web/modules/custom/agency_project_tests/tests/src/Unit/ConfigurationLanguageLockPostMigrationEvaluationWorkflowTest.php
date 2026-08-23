<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded post-migration Configuration Language Lock proof.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageLockPostMigrationEvaluationWorkflowTest extends TestCase {

  /**
   * The gateway stays fixed to issue #744 and live main.
   */
  public function testGatewayIsIssueOnlyLiveMainAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-config-language-lock-post-migration-evaluation.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.pull_request == null',
      $workflow,
    );
    self::assertStringContainsString(
      'github.event.issue.number == 744',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-lock post-migration-evaluate'",
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
      'test "$(git rev-parse HEAD)" = "$main_sha"',
      $workflow,
    );
    self::assertStringContainsString(
      'configuration-language-translated-canonical-cohort-720.yml',
      $workflow,
    );
    self::assertStringContainsString(
      'Configuration Language Lock must remain disabled in canonical config.',
      $workflow,
    );
    self::assertStringContainsString(
      '.require["drupal/config_language_lock"] == "^1.0"',
      $workflow,
    );
    self::assertStringContainsString('^1\\.0\\.[0-9]+$', $workflow);

    $evaluateStart = strpos($workflow, "  evaluate:\n");
    $reportStart = strpos($workflow, "  report-result:\n");
    self::assertIsInt($evaluateStart);
    self::assertIsInt($reportStart);
    $evaluate = substr($workflow, $evaluateStart, $reportStart - $evaluateStart);

    self::assertStringContainsString('contents: read', $evaluate);
    self::assertStringNotContainsString('contents: write', $evaluate);
    self::assertStringContainsString('persist-credentials: false', $evaluate);
    self::assertStringContainsString('- self-hosted', $evaluate);
    self::assertStringContainsString('- agency', $evaluate);
    self::assertStringContainsString('- ddev', $evaluate);
    self::assertStringContainsString(
      'run-config-language-lock-post-migration-evaluation.sh',
      $evaluate,
    );
    self::assertStringContainsString('actions/upload-artifact@v4', $evaluate);
    self::assertStringContainsString('ddev delete --omit-snapshot --yes', $evaluate);

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'git push',
      'composer require',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The runner proves the post-#720 baseline and reversible non-enforcement.
   */
  public function testRunnerIsPostMigrationLocaleAwareAndReversible(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/scripts/runner/run-config-language-lock-post-migration-evaluation.sh';
    self::assertFileExists($path);
    $runner = (string) file_get_contents($path);

    $output = [];
    $status = 0;
    exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));

    self::assertStringContainsString('ddev composer audit --locked', $runner);
    self::assertStringContainsString('site:install --existing-config', $runner);
    self::assertStringContainsString(
      '.repository.summary.by_langcode == '
      . '{"__none__":59,"en":395,"fr":140,"und":1}',
      $runner,
    );
    self::assertStringContainsString(
      'ddev drush pm:enable config_language_lock -y',
      $runner,
    );
    self::assertStringContainsString(
      "added != ['config_language_lock.settings']",
      $runner,
    );
    self::assertStringContainsString(
      "settings.get('locked_langcode') is not None",
      $runner,
    );
    self::assertStringContainsString(
      "settings.get('follow_site_default') is not False",
      $runner,
    );
    self::assertStringContainsString(
      "old not in ('en', None) or new != site_default",
      $runner,
    );
    self::assertStringContainsString(
      "'classification': 'DRUPAL_LOCALE_EXTENSION_INSTALL_FOOTPRINT'",
      $runner,
    );
    self::assertStringContainsString(
      "special.get('system_menu_footer_langcode') != 'und'",
      $runner,
    );
    self::assertStringContainsString(
      'ddev drush pm:uninstall config_language_lock -y',
      $runner,
    );
    self::assertStringContainsString(
      "changed != ['core.extension']",
      $runner,
    );
    self::assertStringContainsString(
      'canonical restore did not return exact active config baseline',
      $runner,
    );
    self::assertStringContainsString(
      'POST_MIGRATION_NON_ENFORCING_BEHAVIOR_PROVEN',
      $runner,
    );
    self::assertStringContainsString(
      'test -z "$(git status --short config/sync)"',
      $runner,
    );
    self::assertGreaterThanOrEqual(2, substr_count($runner, 'ddev drush cim -y'));

    foreach ([
      'drush cex',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'git push',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner);
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded configuration-language audit route.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageAuditWorkflowTest extends TestCase {

  /**
   * The issue-comment gateway must remain owner-bound and read-only.
   */
  public function testTrustedAuditGatewayIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-configuration-language-audit.yml',
    );

    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language inspect'",
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]',
      $workflow,
    );
    self::assertStringContainsString(
      '[[ "$ISSUE_NUMBER" == \'609\' ]]',
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
    self::assertStringContainsString(
      'ddev exec php -l /var/www/html/scripts/runner/configuration-language-audit.php',
      $workflow,
    );

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('SSH_PRIVATE_KEY', $workflow);
    self::assertStringNotContainsString('SERVER_HOST', $workflow);
    self::assertStringNotContainsString('composer require', $workflow);
    self::assertStringNotContainsString('drush cex', $workflow);
    self::assertStringNotContainsString(
      'emerging:governed-content',
      $workflow,
    );
    self::assertStringNotContainsString(
      "\n          php -l scripts/runner/configuration-language-audit.php",
      $workflow,
    );
    self::assertStringNotContainsString(
      'REPOSITORY_BY_LANGCODE:-{}',
      $workflow,
    );
    self::assertStringNotContainsString(
      'ACTIVE_BY_LANGCODE:-{}',
      $workflow,
    );
  }

  /**
   * Boolean result values must not be collapsed into the unknown fallback.
   */
  public function testBooleanSummaryPreservesFalse(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-configuration-language-audit.yml',
    );

    self::assertStringContainsString(
      'has("mixed_technical_base_languages")',
      $workflow,
    );
    self::assertStringContainsString(
      'has("canonical_language_already_uniform")',
      $workflow,
    );
    self::assertStringNotContainsString(
      '.observations.canonical_language_already_uniform // "unknown"',
      $workflow,
    );
    self::assertStringNotContainsString(
      '.observations.mixed_technical_base_languages // "unknown"',
      $workflow,
    );
  }

  /**
   * The runner captures repository and active state without changing either.
   */
  public function testAuditRunnerCapturesReadOnlyEvidence(): void {
    $root = dirname(DRUPAL_ROOT);
    $runner = (string) file_get_contents(
      $root . '/scripts/runner/run-configuration-language-audit.sh',
    );
    $snapshotter = (string) file_get_contents(
      $root . '/scripts/runner/configuration-language-audit.php',
    );

    self::assertStringContainsString('drush config:status', $runner);
    self::assertStringContainsString(
      'ddev exec php -l /var/www/html/scripts/runner/configuration-language-audit.php',
      $runner,
    );
    self::assertStringContainsString(
      'configuration-language-audit.php',
      $runner,
    );
    self::assertStringContainsString(
      '.repository_active_comparison.missing_from_active | length == 0',
      $runner,
    );
    self::assertStringContainsString(
      '.repository_active_comparison.langcode_mismatches | length == 0',
      $runner,
    );
    self::assertStringContainsString('SNAPSHOT_CAPTURED', $runner);

    foreach ([
      'composer require',
      'drush cex',
      'drush cim',
      'drush en',
      'config:set',
    ] as $forbiddenMutation) {
      self::assertStringNotContainsString($forbiddenMutation, $runner);
    }
    self::assertStringNotContainsString(
      "\nphp -l scripts/runner/configuration-language-audit.php",
      $runner,
    );

    self::assertStringContainsString(
      "\\Drupal::service('config.storage')",
      $snapshotter,
    );
    self::assertStringContainsString(
      'getEntityTypeIdByName',
      $snapshotter,
    );
    self::assertStringContainsString(
      'repository_active_comparison',
      $snapshotter,
    );
    self::assertStringContainsString(
      'mixed_technical_base_languages',
      $snapshotter,
    );
    self::assertStringContainsString(
      "glob(\$configDirectory . '/language/*', GLOB_ONLYDIR)",
      $snapshotter,
    );
    self::assertStringNotContainsString('->save(', $snapshotter);
    self::assertStringNotContainsString('config.factory', $snapshotter);
  }

  /**
   * The proven route and baseline must remain durable and discoverable.
   */
  public function testProvenRunbookAndRegistryExposeAuditEvidence(): void {
    $root = dirname(DRUPAL_ROOT);
    $runbook = (string) file_get_contents(
      $root . '/docs/operations/configuration-language-audit.md',
    );
    $registry = (string) file_get_contents(
      $root . '/docs/operations/execution-capabilities.md',
    );
    $baseline = Yaml::parseFile(
      $root . '/docs/evidence/configuration-language-baseline-609.yml',
    );

    self::assertStringContainsString('PROUVÉE / AVAILABLE', $runbook);
    foreach ([$runbook, $registry] as $document) {
      self::assertStringContainsString(
        '/agency-config-language inspect',
        $document,
      );
      self::assertStringContainsString('32528341256', $document);
      self::assertStringContainsString(
        'df4d389eafaad6135fcd7d995354ff433111be62f745208ac0a65ddf8783629d',
        $document,
      );
    }

    self::assertSame(
      'agency-config-language-609-pre-migration-v1',
      $baseline['baseline_id'] ?? NULL,
    );
    self::assertSame('migration_required', $baseline['policy_status'] ?? NULL);
    self::assertSame(595, $baseline['repository']['total'] ?? NULL);
    self::assertSame(352, $baseline['repository']['by_langcode']['fr'] ?? NULL);
    self::assertSame(183, $baseline['repository']['by_langcode']['en'] ?? NULL);
    self::assertSame(
      181,
      $baseline['migration_analysis']['fr_base_without_en_override'] ?? NULL,
    );
    self::assertFalse(
      $baseline['migration_analysis']['canonical_language_already_uniform'] ?? TRUE,
    );
    self::assertFalse(
      $baseline['classification']['bulk_langcode_replacement_allowed'] ?? TRUE,
    );
  }

}

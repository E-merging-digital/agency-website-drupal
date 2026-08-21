<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;

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

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('SSH_PRIVATE_KEY', $workflow);
    self::assertStringNotContainsString('SERVER_HOST', $workflow);
    self::assertStringNotContainsString('composer require', $workflow);
    self::assertStringNotContainsString('drush cex', $workflow);
    self::assertStringNotContainsString(
      'emerging:governed-content',
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
   * Durable documentation must expose the command and evidence contract.
   */
  public function testDocumentationExposesAuditRoute(): void {
    $root = dirname(DRUPAL_ROOT);
    $architecture = (string) file_get_contents(
      $root . '/docs/configuration-language-governance.md',
    );
    $capabilities = (string) file_get_contents(
      $root . '/docs/operations/execution-capabilities.md',
    );

    foreach ([$architecture, $capabilities] as $document) {
      self::assertStringContainsString(
        '/agency-config-language inspect',
        $document,
      );
      self::assertStringContainsString(
        'agency-config-language-609-',
        $document,
      );
    }
  }

}

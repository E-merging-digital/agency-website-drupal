<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the DEV / PREPROD / PROD external side-effect contract.
 *
 * @group agency_project_tests
 */
final class EnvironmentSideEffectPolicyTest extends TestCase {

  /**
   * Common configuration is fail-safe and environment overrides stay explicit.
   */
  public function testFailSafeConfigurationMatrix(): void {
    $root = dirname(DRUPAL_ROOT);

    $cron = Yaml::decode((string) file_get_contents(
      $root . '/config/sync/automated_cron.settings.yml',
    ));
    self::assertSame(0, $cron['interval'] ?? NULL);

    $update = Yaml::decode((string) file_get_contents(
      $root . '/config/sync/update.settings.yml',
    ));
    self::assertSame([], $update['notification']['emails'] ?? NULL);

    $key = Yaml::decode((string) file_get_contents(
      $root . '/config/sync/key.key.openai_api_key.yml',
    ));
    self::assertFalse($key['status'] ?? TRUE);

    $preprodSplit = Yaml::decode((string) file_get_contents(
      $root . '/config/sync/config_split.config_split.preproduction.yml',
    ));
    self::assertContains('linkchecker.settings', $preprodSplit['complete_list']);
    self::assertNotContains('automated_cron.settings', $preprodSplit['complete_list']);
    self::assertNotContains('system.mail', $preprodSplit['complete_list']);

    $prodSplit = Yaml::decode((string) file_get_contents(
      $root . '/config/sync/config_split.config_split.production.yml',
    ));
    self::assertContains('linkchecker.settings', $prodSplit['complete_list']);
  }

  /**
   * Provider egress and PROD scheduler writes require explicit authority.
   */
  public function testRuntimeGatesAreFailClosed(): void {
    $root = dirname(DRUPAL_ROOT);
    $settings = (string) file_get_contents($root . '/web/sites/default/settings.php');
    $preprodSettings = (string) file_get_contents(
      $root . '/scripts/preproduction/settings.php.template',
    );
    $aiClient = (string) file_get_contents(
      $root . '/web/modules/custom/agency_ai_translation/src/Service/AiTranslationClient.php',
    );
    $runtime = (string) file_get_contents(
      $root . '/scripts/preproduction/validate-runtime.sh',
    );
    $promotion = (string) file_get_contents(
      $root . '/scripts/production-promotion/promote-candidate.sh',
    );
    $schedulerVerifier = (string) file_get_contents(
      $root . '/scripts/production-promotion/reconcile-cron.sh',
    );
    $schedulerMutator = (string) file_get_contents(
      $root . '/scripts/production-promotion/mutate-cron.sh',
    );
    $schedulerWorkflow = (string) file_get_contents(
      $root . '/.github/workflows/production-scheduler-change.yml',
    );

    self::assertStringContainsString('AGENCY_AI_EGRESS_ENABLED', $settings);
    self::assertStringContainsString('agency_external_ai_egress_enabled', $aiClient);
    self::assertStringContainsString("agency_external_ai_egress_enabled'] = FALSE", $preprodSettings);
    self::assertStringContainsString('normal_openai_egress=ZERO_BY_POLICY', $runtime);
    self::assertStringContainsString('OPENAI_API_KEY', $runtime);

    self::assertStringContainsString('reconcile-cron.sh', $promotion);
    self::assertStringNotContainsString('mutate-cron.sh', $promotion);
    self::assertStringContainsString('VERIFY_ONLY', $schedulerVerifier);
    self::assertStringContainsString('# agency-drupal-cron', $schedulerVerifier);
    self::assertStringContainsString('flock -n', $schedulerVerifier);
    self::assertStringContainsString('*/15 * * * *', $schedulerVerifier);
    self::assertStringNotContainsString('crontab "$tmp"', $schedulerVerifier);

    self::assertStringContainsString('OWNER_ISSUE_COMMENT', $schedulerMutator);
    self::assertStringContainsString('crontab "$tmp"', $schedulerMutator);
    self::assertStringContainsString('/agency-production-scheduler', $schedulerWorkflow);
    self::assertStringContainsString(
      "github.event.comment.author_association == 'OWNER'",
      $schedulerWorkflow,
    );
  }

  /**
   * The durable matrix remains versioned with the deployment contract.
   */
  public function testEnvironmentMatrixIsDocumented(): void {
    $root = dirname(DRUPAL_ROOT);
    $matrix = (string) file_get_contents(
      $root . '/docs/operations/environment-side-effects.md',
    );

    foreach ([
      'GA4 / Google Tag',
      'Email',
      'Drupal cron scheduler',
      'OpenAI / external AI provider',
      'Link Checker',
      'Drupal Update notifications',
      'Cookie consent',
      'Simple Sitemap / SEO output',
      'Custom external API / webhook writes',
      'VERIFY_ONLY',
      'production-scheduler-change.yml',
    ] as $expected) {
      self::assertStringContainsString($expected, $matrix);
    }
  }

}

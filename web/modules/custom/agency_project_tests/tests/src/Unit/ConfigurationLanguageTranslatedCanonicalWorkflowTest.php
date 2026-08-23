<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #720 translated canonical migration routes.
 *
 * @group agency_project_tests
 * @group configuration_language_governance
 */
final class ConfigurationLanguageTranslatedCanonicalWorkflowTest extends TestCase {

  /**
   * The writer uses Drupal sync storage and freezes the trusted #718 contract.
   */
  public function testCanonicalWriterUsesDrupalStorageAndExactCohort(): void {
    $root = dirname(DRUPAL_ROOT);
    $writer = (string) file_get_contents(
      $root . '/scripts/runner/apply-configuration-language-translated-canonical.php',
    );

    self::assertIsArray(token_get_all($writer, TOKEN_PARSE));
    self::assertStringContainsString("\\Drupal::service('config.storage.sync')", $writer);
    self::assertStringContainsString("createCollection('language.en')", $writer);
    self::assertStringContainsString("createCollection('language.fr')", $writer);
    self::assertStringContainsString("\\Drupal::service('config.typed')", $writer);
    self::assertStringContainsString('NestedArray::setValue', $writer);
    self::assertStringContainsString('$expectedCount = 173', $writer);
    self::assertStringContainsString('$expectedMaterialLeaves = 930', $writer);
    self::assertStringContainsString(
      '3908bb3b785091d996e969f67af5d0060b3548d2ec732fb055c748085c0d6547',
      $writer,
    );
    self::assertStringContainsString('config_paths_changed', $writer);
    self::assertStringContainsString(
      'ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANONICAL_PATCH_PREPARED',
      $writer,
    );
    self::assertStringContainsString(
      'configuration-language-translated-canonical-cohort-720.yml',
      $writer,
    );

    foreach ([
      'drush cex',
      'config:set',
      'pm:enable',
      'config_language_lock.settings',
      'preg_replace',
      'OPENAI_API_KEY',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $writer);
    }
  }

  /**
   * The governed writer route is bounded and fresh-verifies output.
   */
  public function testGovernedCanonicalWriterRouteIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/governed-configuration-language-translated-canonical-migration.yml',
    );

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-translated-canonical migrate'",
      $workflow,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'720\' ]]', $workflow);
    self::assertStringContainsString('[[ "$GITHUB_ACTOR" == \'E-merging-digital\' ]]', $workflow);
    self::assertStringContainsString('[[ "$EVENT_DEFAULT_SHA" == "$main_sha" ]]', $workflow);
    self::assertStringContainsString('contents: write', $workflow);
    self::assertStringContainsString('persist-credentials: true', $workflow);
    self::assertStringContainsString('counts.config_paths_changed == 353', $workflow);
    self::assertStringContainsString('expected_all_paths[@]}" -eq 354', $workflow);
    self::assertStringContainsString('feature/720-apply-canonical-translated-promotion', $workflow);
    self::assertStringContainsString('Refusing to overwrite existing branch', $workflow);
    self::assertStringContainsString('git push origin "HEAD:refs/heads/$branch"', $workflow);
    self::assertGreaterThanOrEqual(2, substr_count($workflow, 'site:install --existing-config'));
    self::assertStringContainsString('ddev delete --omit-snapshot --yes', $workflow);
    self::assertSame(
      2,
      substr_count(
        $workflow,
        'config_status="$(ddev drush config:status 2>&1)"',
      ),
    );
    self::assertSame(
      2,
      substr_count(
        $workflow,
        'grep -Fq \'No differences\' <<<"$config_status"',
      ),
    );
    self::assertStringNotContainsString(
      "ddev drush config:status | grep -F 'No differences'",
      $workflow,
    );
    self::assertStringContainsString(
      'git status --porcelain --untracked-files=all -- config/sync',
      $workflow,
    );
    self::assertStringNotContainsString(
      'mapfile -t changed_config_paths < <(git diff --name-only -- config/sync | sort)',
      $workflow,
    );
    self::assertStringContainsString(
      'git diff --diff-filter=M --name-only -- config/sync',
      $workflow,
    );
    self::assertStringContainsString(
      'git diff --diff-filter=D --name-only -- config/sync/language/en',
      $workflow,
    );
    self::assertStringContainsString(
      'git status --porcelain -- config/sync/language/fr',
      $workflow,
    );
    self::assertStringContainsString(
      'ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANONICAL_PROMOTION_VERIFIED',
      $workflow,
    );

    foreach ([
      'workflow_dispatch:',
      'drush cex',
      'pm:enable config_language_lock',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'git push --force',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The verifier preserves migration boundaries and review-required state.
   */
  public function testCanonicalVerifierKeepsReviewRequiredAndMechanicalBoundaries(): void {
    $root = dirname(DRUPAL_ROOT);
    $verifier = (string) file_get_contents(
      $root . '/scripts/runner/configuration-language-translated-canonical-verify.php',
    );
    self::assertIsArray(token_get_all($verifier, TOKEN_PARSE));
    self::assertStringContainsString('$expectedCount = 173', $verifier);
    self::assertStringContainsString('count($remainingReviewRequired) !== 140', $verifier);
    self::assertStringContainsString('mechanical_715_verified_en', $verifier);
    self::assertStringContainsString('language.entity.', $verifier);
    self::assertStringContainsString('site_default_language_not_fr', $verifier);
    self::assertStringContainsString(
      'ONE_HUNDRED_SEVENTY_THREE_TRANSLATED_CANONICAL_PROMOTION_VERIFIED',
      $verifier,
    );
    foreach (['write(', 'delete(', 'file_put_contents', 'config:set', 'pm:enable'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $verifier);
    }
  }

  /**
   * The trusted verifier is read-only and asserts exact counts/distribution.
   */
  public function testTrustedCanonicalVerificationRouteIsBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = (string) file_get_contents(
      $root . '/.github/workflows/trusted-configuration-language-translated-canonical-verify.yml',
    );
    $runnerPath = $root . '/scripts/runner/run-configuration-language-translated-canonical-verify.sh';
    $runner = (string) file_get_contents($runnerPath);

    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-config-language-translated-canonical verify'",
      $workflow,
    );
    self::assertStringContainsString('[[ "$ISSUE_NUMBER" == \'720\' ]]', $workflow);
    self::assertStringContainsString('persist-credentials: false', $workflow);
    self::assertStringContainsString('- self-hosted', $workflow);
    self::assertStringContainsString('- agency', $workflow);
    self::assertStringContainsString('- ddev', $workflow);
    self::assertStringContainsString('gh issue comment 720', $workflow);

    foreach ([
      '.counts.verified == 173',
      '.counts.fr_overrides_required == 7',
      '.counts.mechanical_715_verified_en == 39',
      '.counts.remaining_fr_review_required == 140',
      '.counts.preserved_fr_overrides_outside_cohort == 2',
      '.counts.preserved_en_overrides_outside_cohort == 1',
      '.distribution_by_langcode == {"__none__":59,"en":395,"fr":140,"und":1}',
      'git diff --exit-code -- config/sync',
    ] as $required) {
      self::assertStringContainsString($required, $runner);
    }

    foreach ([
      'drush cex',
      'drush en',
      'pm:enable',
      'config:set',
      'state:set',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $runner);
      self::assertStringNotContainsString($forbidden, $workflow);
    }

    $output = [];
    $status = 0;
    exec('bash -n ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $status);
    self::assertSame(0, $status, implode("\n", $output));
  }

}

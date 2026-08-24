<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the trusted #760 post-merge verification route.
 *
 * @group agency_project_tests
 * @group configuration_language
 */
final class ConfigurationLanguageBaseFieldRuntimeCohort760TrustedVerifyTest extends TestCase {

  /**
   * The route is fixed to live main and remains read-only on the runner.
   */
  public function testTrustedRouteIsReadOnlyAndBounded(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/'
      . 'trusted-configuration-language-base-field-runtime-cohort-760-verify.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));
    self::assertStringContainsString(
      'github.event.issue.number == 760',
      $workflow,
    );
    self::assertStringContainsString(
      "'/agency-config-language-base-field-runtime verify'",
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
      'persist-credentials: false',
      $workflow,
    );
    self::assertStringContainsString(
      'ddev drush site:install --existing-config',
      $workflow,
    );
    self::assertStringContainsString('ddev drush cim -y', $workflow);
    self::assertStringContainsString(
      "grep -Fq 'No differences'",
      $workflow,
    );
    self::assertStringContainsString(
      'FIFTY_THREE_BASE_FIELD_RUNTIME_CANONICAL_PROMOTIONS_VERIFIED',
      $workflow,
    );
    self::assertStringContainsString(
      '"__none__":59,"en":466,"fr":69,"und":1',
      $workflow,
    );
    self::assertStringContainsString(
      '.exception.fr_override == {"label":"Composants"}',
      $workflow,
    );
    self::assertStringContainsString(
      '.remaining_fr_review_required == 69',
      $workflow,
    );

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'persist-credentials: true',
      'drush cex',
      'pm:enable config_language_lock',
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PRODUCTION_SSH',
      'git push',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

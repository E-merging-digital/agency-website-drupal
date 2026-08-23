<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the fixed Drupal/contrib + AI RC maintenance execution route.
 *
 * @group agency_project_tests
 * @group governed_composer
 */
final class GovernedDrupalMaintenanceWorkflowTest extends TestCase {

  /**
   * The maintenance trigger and target must remain fixed and non-generic.
   */
  public function testMaintenanceRouteIsFixedToReviewedTarget(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-drupal-maintenance-ai-1-5-rc1.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    $workflow = (string) file_get_contents($path);
    self::assertStringContainsString(
      "github.event.issue.number == 563",
      $workflow,
    );
    self::assertStringContainsString(
      "github.event.comment.body == '/agency-drupal-maintenance-ai-1.5-rc1'",
      $workflow,
    );
    self::assertStringContainsString(
      "feature/issue-562-drupal-contrib-ai-1-5-rc1",
      $workflow,
    );
    self::assertStringContainsString(
      "require['drupal/ai'] = '1.5.0-rc1'",
      $workflow,
    );
    self::assertStringContainsString(
      "require['drupal/ai_search'] = '1.3.0-alpha4'",
      $workflow,
    );
    self::assertStringContainsString(
      "require['drupal/field_validation'] = '3.0.0-beta7'",
      $workflow,
    );
    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * Composer package selection must come only from trusted repository code.
   */
  public function testMaintenanceComposerSelectorIsRepositoryOwned(): void {
    $root = dirname(DRUPAL_ROOT);
    $profilePath = $root
      . '/scripts/runner/composer-materialization-profiles.sh';
    $profiles = (string) file_get_contents($profilePath);

    self::assertStringContainsString(
      'drupal-maintenance-ai-1.5-rc1)',
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_PACKAGE='drupal/ai'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_CONSTRAINT='1.5.0-rc1'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_UPDATE_SELECTOR='drupal/*'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_REQUIRED_AI_SEARCH_VERSION='1.3.0-alpha4'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_REQUIRED_FIELD_VALIDATION_VERSION='3.0.0-beta7'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_OWNER_ISSUE='562'",
      $profiles,
    );
    self::assertStringContainsString(
      "COMPOSER_UPDATE_SELECTOR='drupal/ai_agents'",
      $profiles,
    );
    self::assertStringContainsString(
      'Unsupported Composer materialization profile',
      $profiles,
    );
  }

  /**
   * Self-hosted resolution stays read-only and hosted writes stay lock-only.
   */
  public function testResolverAndPublisherKeepPrivilegeSeparation(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-drupal-maintenance-ai-1-5-rc1.yml';
    $workflow = (string) file_get_contents($path);

    $resolverStart = strpos($workflow, "  resolve-lock:\n");
    $publisherStart = strpos($workflow, "  publish-lock:\n");
    self::assertIsInt($resolverStart);
    self::assertIsInt($publisherStart);
    self::assertGreaterThan($resolverStart, $publisherStart);

    $resolver = substr(
      $workflow,
      $resolverStart,
      $publisherStart - $resolverStart,
    );
    $publisher = substr($workflow, $publisherStart);

    self::assertStringContainsString('contents: read', $resolver);
    self::assertStringNotContainsString('contents: write', $resolver);
    self::assertStringContainsString(
      'persist-credentials: false',
      $resolver,
    );
    self::assertStringContainsString(
      'ddev composer update "$COMPOSER_UPDATE_SELECTOR"',
      $resolver,
    );
    self::assertStringContainsString('--with-all-dependencies', $resolver);
    self::assertStringContainsString('--no-install', $resolver);
    self::assertStringContainsString('--no-scripts', $resolver);
    self::assertStringContainsString(
      "changed\" != 'composer.lock'",
      $resolver,
    );
    self::assertStringContainsString(
      'resolved_ai_search',
      $resolver,
    );
    self::assertStringContainsString(
      'resolved_field_validation',
      $resolver,
    );
    self::assertStringContainsString(
      'ddev composer audit --format=json --locked',
      $resolver,
    );

    $auditPosition = strpos(
      $resolver,
      'ddev composer audit --format=json --locked',
    );
    $gateRemovalPosition = strpos(
      $resolver,
      'rm -f .ddev/config.gate-drupal-maintenance.yaml',
    );
    $gitCheckPosition = strpos(
      $resolver,
      'git ls-files --others --exclude-standard',
    );
    self::assertIsInt($auditPosition);
    self::assertIsInt($gateRemovalPosition);
    self::assertIsInt($gitCheckPosition);
    self::assertGreaterThan($auditPosition, $gateRemovalPosition);
    self::assertGreaterThan($gateRemovalPosition, $gitCheckPosition);

    self::assertStringContainsString('contents: write', $publisher);
    self::assertStringContainsString(
      "test \"$(git diff --cached --name-only)\" = 'composer.lock'",
      $publisher,
    );
    self::assertStringContainsString(
      "'.resolved_ai_search'",
      $publisher,
    );
    self::assertStringContainsString(
      "'.resolved_field_validation'",
      $publisher,
    );
    self::assertStringContainsString(
      'Target PR advanced before final push.',
      $publisher,
    );
    self::assertStringContainsString(
      'git push origin "HEAD:$EXPECTED_HEAD_REF"',
      $publisher,
    );
  }

}

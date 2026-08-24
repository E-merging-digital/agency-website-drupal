<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the repository-only PREPROD release-candidate foundation.
 *
 * @group agency_project_tests
 */
final class PreproductionReleaseCandidateWorkflowTest extends TestCase {

  /**
   * Release candidates are immutable, release-branch-only and non-deploying.
   */
  public function testReleaseCandidateWorkflowIsBoundedAndNonDeploying(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/build-release-candidate.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      "- 'release/**'",
      'persist-credentials: false',
      "php-version: '8.4'",
      'composer install',
      '--no-dev',
      '--optimize-autoloader',
      'candidate_sha',
      'composer_lock_sha256',
      'artifact_sha256',
      'agency-release-candidate-${{ github.sha }}',
      'web/sites/default/settings.php',
      'web/sites/default/files/',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }

    foreach ([
      'workflow_dispatch:',
      'deploy-production.sh',
      'SERVER_HOST',
      'SERVER_USER',
      'SSH_PRIVATE_KEY',
      'BETTERUPTIME_API_TOKEN',
      'OPENAI_API_KEY',
      'contents: write',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * The versioned contract keeps the approved branch and promotion model.
   */
  public function testPreproductionContractKeepsPromotionBoundaries(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/docs/operations/preproduction.md';
    self::assertFileExists($path);

    $contract = (string) file_get_contents($path);
    foreach ([
      '`main`: current PROD baseline',
      '`release/*`: coherent functional release candidate',
      '`feature/*`: bounded development branches',
      '`hotfix/*` and `security/*`',
      'explicit human GO',
      'candidate Git SHA',
      'application artifact SHA-256',
      'PREPROD target -> not provisioned yet',
      'existing automatic PROD deploy remains unchanged',
    ] as $expected) {
      self::assertStringContainsString($expected, $contract);
    }
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #759 terminal production health proof route.
 *
 * @group agency_project_tests
 */
final class AgencyHealthProductionProofWorkflowTest extends TestCase {

  /**
   * The post-close proof reconciles existing deployment evidence read-only.
   */
  public function testProductionProofIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-agency-health-production-proof.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      "github.repository == 'E-merging-digital/agency-website-drupal'",
      "'.state'",
      "'closed'",
      "'.state_reason'",
      "'completed'",
      'd7bf3abfa7b8df7014d27bb2402b61555bf569a0',
      'git merge-base --is-ancestor',
      'config/sync/core.extension.yml web/modules/custom/agency_health',
      'persist-credentials: false',
      'actions/workflows/deploy-production.yml/runs?event=push&head_sha=',
      "'.conclusion'",
      "'success'",
      'gh run download',
      'agency-production-deploy-${run_id}-${run_attempt}',
      'result_field outcome',
      "'SUCCESS'",
      'git -C /var/www/agency/current rev-parse HEAD',
      'https://emergingdigital.be${path}',
      "probe live '/health/live' 3 1",
      "probe ready '/health/ready' 5 2",
      'test "$compact_body" = \'{"status":"ok"}\'',
      'application/json',
      'no-store',
      'nominal_target_met',
      'agency-health-production-terminal-759-',
      'read-only post-close convergence proof',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }

    foreach ([
      'issue_comment:',
      'workflow_dispatch:',
      'contents: write',
      'actions: write',
      'persist-credentials: true',
      'deploy-production.sh main',
      'drush state:set',
      'drush cim',
      'drush updb',
      'systemctl restart',
      'systemctl reload',
      'BETTERUPTIME_API_TOKEN',
      'OPENAI_API_KEY',
      '${{ inputs.',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

}

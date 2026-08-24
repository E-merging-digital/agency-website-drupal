<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;

/**
 * Protects the bounded #759 production health proof route.
 *
 * @group agency_project_tests
 */
final class AgencyHealthProductionProofWorkflowTest extends TestCase {

  /**
   * The proof is fixed to #759, production and the merged runtime authority.
   */
  public function testProductionProofIsBoundedAndReadOnly(): void {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-agency-health-production-proof.yml';
    self::assertFileExists($path);

    $workflow = (string) file_get_contents($path);
    self::assertIsArray(DrupalYaml::decode($workflow));

    foreach ([
      "github.event.comment.body == '/agency-health production-prove'",
      "test \"$ISSUE_NUMBER\" = '759'",
      'd7bf3abfa7b8df7014d27bb2402b61555bf569a0',
      'git merge-base --is-ancestor',
      'config/sync/core.extension.yml web/modules/custom/agency_health',
      'persist-credentials: false',
      'git -C /var/www/agency/current rev-parse HEAD',
      'https://emergingdigital.be${path}',
      "probe live '/health/live' 3 1",
      "probe ready '/health/ready' 5 2",
      'test "$compact_body" = \'{"status":"ok"}\'',
      'application/json',
      'no-store',
      'nominal_target_met',
      'agency-health-production-759-',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }

    foreach ([
      'workflow_dispatch:',
      'contents: write',
      'persist-credentials: true',
      'deploy-production.sh',
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

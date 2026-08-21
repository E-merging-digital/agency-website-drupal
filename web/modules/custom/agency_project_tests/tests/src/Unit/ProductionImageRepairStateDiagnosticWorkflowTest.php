<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the read-only #401 production repair-state diagnostic.
 *
 * @group agency_project_tests
 * @group production_image_diagnostic
 */
final class ProductionImageRepairStateDiagnosticWorkflowTest extends TestCase {

  /**
   * The control surface is fixed to the incident and current runtime.
   */
  public function testControlSurfaceIsClosed(): void {
    $workflow = $this->workflow();

    foreach ([
      "github.event.issue.number == 602",
      "github.actor == 'E-merging-digital'",
      '/agency-production-image diagnose-repair-state',
      "EXPECTED_RUNTIME='ffccfc35c24805c4ae973cbd847b827b21a04184'",
      "OLD_URI='public://articles/issue-401-redesign-checklist-f925e3b41c32.png'",
      "NEW_URI='public://articles/issue-401-redesign-checklist-70bf17abe69d.png'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
  }

  /**
   * The route may only inspect fixed files and fixed database state.
   */
  public function testDiagnosticIsReadOnly(): void {
    $workflow = $this->workflow();

    foreach ([
      'SELECT',
      'SHOW VARIABLES',
      'SHOW GLOBAL STATUS',
      'file_managed',
      'node__field_feature_image',
      'node_revision__field_feature_image',
      'journalctl',
      'sha256sum',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'drush state:set',
      'drush cr',
      'drush php:script',
      'drush php:eval',
      'drush cim',
      'drush updb',
      'drush sql:dump',
      'systemctl restart',
      'systemctl reload',
      'service nginx restart',
      'deploy-production.sh main',
      'git pull',
      'git reset',
      'git checkout',
      'rm -f',
      'scp ',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Evidence must survive partial query failures.
   */
  public function testEvidenceIsPersisted(): void {
    $workflow = $this->workflow();

    foreach ([
      'artifacts/production-image-repair-state/diagnostic.txt',
      'artifacts/production-image-repair-state/result.json',
      'agency-production-image-repair-state-602-',
      'diagnostic_complete',
      'db_ping_exit',
      'new_file_sha256',
      'gh issue comment 602',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Loads and parses the workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root
      . '/.github/workflows/trusted-production-image-repair-state-diagnostic.yml';
    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

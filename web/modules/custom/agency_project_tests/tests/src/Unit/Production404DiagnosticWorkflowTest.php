<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the bounded read-only production 404 diagnostic.
 *
 * @group agency_project_tests
 * @group production_404_diagnostic
 */
final class Production404DiagnosticWorkflowTest extends TestCase {

  /**
   * The route stays owner-only, issue-bound and live-main-only.
   */
  public function testControlSurfaceIsPinned(): void {
    $workflow = $this->workflow();

    foreach ([
      '/agency-production-404 diagnose',
      'github.event.issue.number == 674',
      "github.actor == 'E-merging-digital'",
      "github.event.comment.user.login == 'E-merging-digital'",
      "ISSUE_NUMBER\" == '674'",
      'currently on live main',
      'runs-on: ubuntu-24.04',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    self::assertStringNotContainsString('workflow_dispatch:', $workflow);
    self::assertStringNotContainsString('github.event.inputs', $workflow);
  }

  /**
   * Fixed HTTP probes distinguish docroot from Drupal routing.
   */
  public function testHttpProbeSetIsFixed(): void {
    $workflow = $this->workflow();

    foreach ([
      "probe_local robots '/robots.txt'",
      "probe_local root '/'",
      "probe_local fr_root '/fr'",
      "probe_local en_root '/en'",
      "probe_local user_login '/user/login'",
      "probe_local node37 '/node/37'",
      "probe_local fr_node37 '/fr/node/37'",
      "probe_local en_node37 '/en/node/37'",
      'checklist-avant-une-refonte-de-site-internet-12-points-verifier',
      'website-redesign-checklist-12-things-verify-you-start',
      "--resolve 'emergingdigital.be:443:127.0.0.1'",
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Drupal inspection is limited to fixed, read-only facts.
   */
  public function testDrupalInspectionIsReadOnly(): void {
    $workflow = $this->workflow();

    foreach ([
      'system.site',
      'page.front',
      'Node::load(37)',
      'entity.node.canonical',
      'router.no_access_checks',
      'path_alias.manager',
      'fr_alias_target',
      'en_alias_target',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }

    foreach ([
      'state:set',
      'config:set',
      'entity:delete',
      'node:delete',
      'drush cr',
      'drush cim',
      'drush updb',
      'systemctl restart',
      'systemctl reload',
      'sudo ',
      'git pull',
      'git reset',
      'git checkout',
      'deploy-production.sh main',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $workflow);
    }
  }

  /**
   * Results remain machine-readable and diagnostically classified.
   */
  public function testResultIsBoundedAndClassified(): void {
    $workflow = $this->workflow();

    foreach ([
      'artifacts/production-404/result.json',
      'DOCROOT_OR_NGINX',
      'DRUPAL_REQUEST_ROUTING',
      'ALIAS_OR_LANGUAGE_ROUTING',
      'FRONT_PAGE_ONLY',
      'HTTP_HEALTHY',
      'MIXED',
      'node37_route_match',
      'node37_published',
    ] as $required) {
      self::assertStringContainsString($required, $workflow);
    }
  }

  /**
   * Loads and parses the workflow.
   */
  private function workflow(): string {
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/.github/workflows/trusted-production-404-diagnostic.yml';

    self::assertFileExists($path);
    self::assertIsArray(Yaml::parseFile($path));

    return (string) file_get_contents($path);
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\Component\Serialization\Yaml as DrupalYaml;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Guards the strictly read-only #1436 two-host OS PLAN contract.
 *
 * @group agency_project_tests
 */
final class OsMaintenance1436PlanWorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const WORKFLOW = '.github/workflows/os-maintenance-1436-plan.yml';
  private const PLAN = 'scripts/maintenance-1436/remote-plan.sh';

  private function source(string $path): string {
    $source = file_get_contents(dirname(DRUPAL_ROOT) . '/' . $path);
    self::assertIsString($source, $path);
    return $source;
  }

  /**
   * Existing dispatcher remains the single comment listener.
   */
  public function testOnlyExistingDispatcherListensAndHasExactRoute(): void {
    $root = dirname(DRUPAL_ROOT);
    $listeners = [];
    foreach (glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [] as $file) {
      $workflow = DrupalYaml::parseFile($file);
      if (isset($workflow['on']['issue_comment'])) {
        $listeners[] = str_replace($root . '/', '', $file);
      }
    }
    sort($listeners);
    self::assertSame([self::DISPATCHER], $listeners);

    $dispatcher = DrupalYaml::parseFile($root . '/' . self::DISPATCHER);
    $route = $dispatcher['jobs']['os-maintenance-1436-plan'];
    self::assertSame('./' . self::WORKFLOW, $route['uses']);
    self::assertSame(['contents' => 'read', 'issues' => 'read'], $route['permissions']);
    self::assertSame([
      'PREPROD_SSH_PRIVATE_KEY',
      'PREPROD_SERVER_HOST',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'SERVER_USER',
    ], array_keys($route['secrets']));
    $guard = (string) $route['if'];
    foreach ([
      "github.event.issue.number == 1436",
      "github.event.comment.author_association == 'OWNER'",
      "github.event.comment.user.login == 'E-merging-digital'",
      'github.event.comment.performed_via_github_app == null',
      '/agency-os-maintenance-1436 plan main=',
    ] as $condition) {
      self::assertStringContainsString($condition, $guard);
    }
  }

  /**
   * Direct owner/main validation and strict PREPROD-to-PROD ordering.
   */
  public function testJitRouteRequiresSameMainAndPreprodPassBeforeProd(): void {
    $root = dirname(DRUPAL_ROOT);
    $workflow = DrupalYaml::parseFile($root . '/' . self::WORKFLOW);
    self::assertArrayHasKey('workflow_call', $workflow['on']);
    self::assertArrayNotHasKey('issue_comment', $workflow['on']);
    self::assertArrayNotHasKey('workflow_dispatch', $workflow['on']);
    self::assertSame(
      ['validate-authority', 'preprod-plan'],
      $workflow['jobs']['prod-plan']['needs'],
    );
    self::assertSame('validate-authority', $workflow['jobs']['preprod-plan']['needs']);
    self::assertSame(['contents' => 'read'], $workflow['jobs']['preprod-plan']['permissions']);
    self::assertSame(['contents' => 'read'], $workflow['jobs']['prod-plan']['permissions']);

    $source = $this->source(self::WORKFLOW);
    foreach ([
      'test "$ISSUE_NUMBER" = 1436',
      'test "$COMMENT_ASSOCIATION" = OWNER',
      'test "$COMMENT_VIA_APP" = false',
      'test "$expected_main" = "$main_sha"',
      'test "$WORKFLOW_SHA" = "$main_sha"',
      'test "$IS_PULL_REQUEST" = false',
      "agency-preprod@$PREPROD_SERVER_HOST",
      'StrictHostKeyChecking=yes',
      'manage-known-host.sh PROVISION',
      'needs.preprod-plan.outputs.plan_digest',
      'REAL_PACKAGE_MUTATION',
      'APT_STATE_MUTATION',
    ] as $guard) {
      self::assertStringContainsString($guard, $source);
    }
    self::assertStringNotContainsString('PREPROD_PROVISIONING_SSH_PRIVATE_KEY', $source);
    self::assertStringNotContainsString('secrets: inherit', $source);
    self::assertStringNotContainsString('sudo ', $source);
    self::assertStringNotContainsString('workflow_dispatch:', $source);
  }

  /**
   * Script is syntax-safe and can never apply a package or reboot.
   */
  public function testFixedScopeIsolatedAptAndPermissionFailures(): void {
    $root = dirname(DRUPAL_ROOT);
    $plan = $root . '/' . self::PLAN;
    $check = new Process(['bash', '-n', $plan]);
    $check->run();
    self::assertTrue($check->isSuccessful(), $check->getErrorOutput());

    $source = $this->source(self::PLAN);
    foreach ([
      "ISSUE='1436'",
      "EXPECTED_HOST='agency-preprod-01'",
      "EXPECTED_HOST='emergingdigital'",
      '[[ "$(id -un)" == "$EXPECTED_USER" ]]',
      '[[ "$(hostname -s)" == "$EXPECTED_HOST" ]]',
      'Dir::State=',
      'Dir::Cache=',
      'APT::Update::Error-Mode=any',
      '--simulate upgrade',
      '--simulate full-upgrade',
      'APT_SOURCE_OR_PERMISSION',
      'APT_LIST_UNAVAILABLE',
      'APT_UPGRADE_SIMULATION',
      'APT_FULL_UPGRADE_SIMULATION',
      "php_branch_8_5",
      "php_8_4_rollback_installed",
      "mariadb_branch_11_8",
      "REAL_PACKAGE_MUTATION",
      "PLAN_DIGEST",
      "FULL_PACKAGE_ADDITIONS",
      "FULL_PACKAGE_REMOVALS",
    ] as $contract) {
      self::assertStringContainsString($contract, $source);
    }
    foreach (['sudo ', 'apt-get install', 'apt-get upgrade', 'systemctl restart', 'shutdown', 'reboot ', 'drush sql:dump'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }
  }

}

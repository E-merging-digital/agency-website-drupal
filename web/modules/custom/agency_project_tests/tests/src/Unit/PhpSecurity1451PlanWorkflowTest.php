<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards the independent PHP security maintenance plan.
 *
 * @group agency_project_tests
 */
final class PhpSecurity1451PlanWorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';
  private const WORKFLOW = '.github/workflows/php-security-1451-plan.yml';
  private const PLAN = 'scripts/maintenance-1451/remote-plan.sh';

  /**
   * Reads a repository file without mutating it.
   */
  private function source(string $path): string {
    $result = file_get_contents(dirname(DRUPAL_ROOT) . '/' . $path);
    self::assertIsString($result);
    return $result;
  }

  /**
   * Only the existing dispatcher may recognize a direct OWNER comment.
   */
  public function testSingleDispatcherAndExactOwnerRoute(): void {
    $root = dirname(DRUPAL_ROOT);
    $listeners = [];
    foreach (glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [] as $file) {
      $workflow = Yaml::parseFile($file);
      if (isset($workflow['on']['issue_comment'])) {
        $listeners[] = str_replace($root . '/', '', $file);
      }
    }
    self::assertSame([self::DISPATCHER], $listeners);
    $dispatcher = Yaml::parseFile($root . '/' . self::DISPATCHER);
    $route = $dispatcher['jobs']['php-security-1451-plan'];
    self::assertSame('./' . self::WORKFLOW, $route['uses']);
    self::assertSame(['contents' => 'read', 'issues' => 'read'], $route['permissions']);
    self::assertSame([
      'PREPROD_SSH_PRIVATE_KEY',
      'PREPROD_SERVER_HOST',
      'SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'SERVER_USER',
    ], array_keys($route['secrets']));
    foreach ([
      'github.event.issue.number == 1451',
      "github.event.comment.author_association == 'OWNER'",
      "github.event.comment.user.login == 'E-merging-digital'",
      'github.event.comment.performed_via_github_app == null',
      '/agency-php-security-1451 plan main=',
    ] as $guard) {
      self::assertStringContainsString($guard, (string) $route['if']);
    }
  }

  /**
   * Validates exact-main authority and PREPROD-first host ordering.
   */
  public function testFreshMainAndSequentialReadOnlyHosts(): void {
    $root = dirname(DRUPAL_ROOT);
    $config = Yaml::parseFile($root . '/' . self::WORKFLOW);
    self::assertArrayHasKey('workflow_call', $config['on']);
    self::assertArrayNotHasKey('issue_comment', $config['on']);
    self::assertArrayNotHasKey('workflow_dispatch', $config['on']);
    self::assertSame(['validate-authority', 'preprod-plan'], $config['jobs']['prod-plan']['needs']);
    self::assertSame('validate-authority', $config['jobs']['preprod-plan']['needs']);
    $workflow = $this->source(self::WORKFLOW);
    foreach ([
      'test "$ISSUE_NUMBER" = 1451',
      'test "$COMMENT_ASSOCIATION" = OWNER',
      'test "$COMMENT_VIA_APP" = false',
      'test "$expected_main" = "$main_sha"',
      'test "$WORKFLOW_SHA" = "$main_sha"',
      'needs.preprod-plan.outputs.plan_digest',
      'agency-preprod@$PREPROD_SERVER_HOST',
      'StrictHostKeyChecking=yes',
      'REAL_PACKAGE_MUTATION',
      'OUT_OF_SCOPE_NOT_ATTESTED',
    ] as $expected) {
      self::assertStringContainsString($expected, $workflow);
    }
    foreach (['sudo ', 'apt-get install', 'workflow_dispatch:', 'secrets: inherit'] as $prohibited) {
      self::assertStringNotContainsString($prohibited, $workflow);
    }
  }

  /**
   * Rejects package mutations and non-isolated APT simulation.
   */
  public function testIsolatedSimulationNeverMutatesPackages(): void {
    $root = dirname(DRUPAL_ROOT);
    $script = $root . '/' . self::PLAN;
    $syntax = new Process(['bash', '-n', $script]);
    $syntax->run();
    self::assertTrue($syntax->isSuccessful(), $syntax->getErrorOutput());
    $source = $this->source(self::PLAN);
    foreach ([
      'expected_host=agency-preprod-01',
      'expected_host=emergingdigital',
      'test "$(hostname -s)" = "$expected_host"',
      'test "$(id -un)" = "$expected_user"',
      'Dir::State::lists=',
      'Dir::Cache::archives=',
      'APT::Update::Error-Mode=any',
      '--simulate install',
      'php8.4-fpm',
      'php8.5-fpm',
      'PACKAGE_ADDITIONS',
      'PACKAGE_REMOVALS',
      'PLAN_DIGEST',
      'OUT_OF_SCOPE_NOT_ATTESTED',
    ] as $expected) {
      self::assertStringContainsString($expected, $source);
    }
    foreach (['sudo ', 'apt-get install', 'apt-get upgrade', 'systemctl restart', 'systemctl reboot', 'drush sql:dump'] as $prohibited) {
      self::assertStringNotContainsString($prohibited, $source);
    }
  }

}

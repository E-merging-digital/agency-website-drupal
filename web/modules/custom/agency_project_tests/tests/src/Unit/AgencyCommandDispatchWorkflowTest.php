<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Protects the single native issue-comment dispatcher contract.
 *
 * @group agency_project_tests
 * @group agency_command_dispatch
 */
final class AgencyCommandDispatchWorkflowTest extends TestCase {

  private const DISPATCHER = '.github/workflows/agency-command-dispatch.yml';

  private const REUSABLES = [
    'PRODUCTION_PROMOTE' => '.github/workflows/promote-production.yml',
    'PRODUCTION_SCHEDULER' => '.github/workflows/production-scheduler-change.yml',
    'EDITORIAL_PUBLICATION' => '.github/workflows/trusted-editorial-publication.yml',
    'EDITORIAL_PREPROD_CANDIDATE' => '.github/workflows/trusted-editorial-preprod-candidate.yml',
    'EDITORIAL_FEATURE_IMAGE' => '.github/workflows/trusted-editorial-feature-image.yml',
    'PREPROD_REFRESH' => '.github/workflows/preprod-914-governed-successor.yml',
    'DEVELOPMENT_SEED' => '.github/workflows/development-seed-publish.yml',
    'PREPROD_REFRESH_940_DIAGNOSTIC' => '.github/workflows/preprod-refresh-940-diagnostic.yml',
    'PREPROD_REFRESH_940_RECOVERY' => '.github/workflows/preprod-refresh-940-recovery.yml',
    'PREPROD_REFRESH_948_DETAIL' => '.github/workflows/preprod-refresh-948-detail-diagnostic.yml',
    'PREPROD_BLOG_IMAGE_DIAGNOSTIC' => '.github/workflows/preprod-blog-image-diagnostic.yml',
    'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971' => '.github/workflows/preprod-editorial-image-rehydrate-971.yml',
    'CONFIG_SYNC_RUNTIME_DIAGNOSTIC' => '.github/workflows/config-sync-runtime-diagnostic.yml',
    'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC' => '.github/workflows/prod-config-sync-runtime-diagnostic.yml',
  ];

  private const INCIDENT_ISSUES = [
    'DEVELOPMENT_SEED' => 956,
    'PREPROD_REFRESH_940_DIAGNOSTIC' => 941,
    'PREPROD_REFRESH_940_RECOVERY' => 943,
    'PREPROD_REFRESH_948_DETAIL' => 949,
    'PREPROD_BLOG_IMAGE_DIAGNOSTIC' => 966,
    'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971' => 971,
    'CONFIG_SYNC_RUNTIME_DIAGNOSTIC' => 982,
    'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC' => 982,
  ];

  /**
   * Exactly one workflow listens to issue comments.
   */
  public function testSingleTopLevelIssueCommentListener(): void {
    $root = dirname(DRUPAL_ROOT);
    $listeners = [];
    foreach (glob($root . '/.github/workflows/*.{yml,yaml}', GLOB_BRACE) ?: [] as $path) {
      $parsed = Yaml::parseFile($path);
      self::assertIsArray($parsed, $path);
      $on = $parsed['on'] ?? NULL;
      if (is_array($on) && array_key_exists('issue_comment', $on)) {
        $listeners[] = substr($path, strlen($root) + 1);
      }
    }
    sort($listeners);
    self::assertSame([self::DISPATCHER], $listeners);
  }

  /**
   * The route table remains unique; only #982 owns both config status probes.
   */
  public function testRoutingMatrixAndIssue982Bindings(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $raw = $dispatcher['env']['AGENCY_COMMAND_ROUTES'] ?? NULL;
    self::assertIsString($raw);
    $routes = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($routes);
    self::assertCount(14, $routes);
    self::assertSame(array_keys(self::REUSABLES), array_column($routes, 'route'));

    $prefixes = array_column($routes, 'prefix');
    self::assertCount(count($prefixes), array_unique($prefixes));
    foreach ($prefixes as $index => $prefix) {
      foreach ($prefixes as $otherIndex => $otherPrefix) {
        if ($index !== $otherIndex) {
          self::assertFalse(str_starts_with($prefix, $otherPrefix));
        }
      }
    }

    self::assertSame(
      'CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      $this->classify($routes, '/agency-config-sync-runtime diagnose', 982),
    );
    self::assertSame(
      'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      $this->classify($routes, '/agency-config-sync-prod-runtime diagnose', 982),
    );
    foreach ([961, 980, 981, 983] as $wrongIssue) {
      self::assertSame(
        'NONE',
        $this->classify($routes, '/agency-config-sync-runtime diagnose', $wrongIssue),
      );
      self::assertSame(
        'NONE',
        $this->classify($routes, '/agency-config-sync-prod-runtime diagnose', $wrongIssue),
      );
    }

    foreach ([
      '/agency-config-sync-runtime diagnose now',
      '/agency-config-sync-runtime diagnose target=PROD',
      '/agency-config-sync-prod-runtime diagnose now',
      '/agency-config-sync-prod-runtime diagnose target=PREPROD',
    ] as $invalid) {
      self::assertSame('NONE', $this->classify($routes, $invalid, 982), $invalid);
    }

    $source = $this->source(self::DISPATCHER);
    self::assertStringContainsString(
      "route = matches[0] if len(matches) == 1 else 'NONE'",
      $source,
    );
    self::assertStringContainsString("'CONFIG_SYNC_RUNTIME_DIAGNOSTIC': '982'", $source);
    self::assertStringContainsString("'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC': '982'", $source);
    self::assertStringNotContainsString("'CONFIG_SYNC_RUNTIME_DIAGNOSTIC': '961'", $source);
    self::assertStringNotContainsString("'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC': '980'", $source);
  }

  /**
   * The reused workflows keep explicit permissions and secret mappings.
   */
  public function testConfigStatusReusableRoutesKeepExplicitSecrets(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $jobs = $dispatcher['jobs'] ?? [];
    self::assertSame([], $jobs['classify']['permissions'] ?? NULL);
    self::assertArrayNotHasKey('secrets', $jobs['classify']);

    $preprod = $jobs['config-sync-runtime-diagnostic'] ?? NULL;
    self::assertIsArray($preprod);
    self::assertSame('./.github/workflows/config-sync-runtime-diagnostic.yml', $preprod['uses'] ?? NULL);
    self::assertSame(
      ['contents' => 'read', 'issues' => 'write'],
      $preprod['permissions'] ?? NULL,
    );
    self::assertSame(
      ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'],
      array_keys($preprod['secrets'] ?? []),
    );

    $prod = $jobs['prod-config-sync-runtime-diagnostic'] ?? NULL;
    self::assertIsArray($prod);
    self::assertSame('./.github/workflows/prod-config-sync-runtime-diagnostic.yml', $prod['uses'] ?? NULL);
    self::assertSame(
      ['contents' => 'read', 'issues' => 'write'],
      $prod['permissions'] ?? NULL,
    );
    self::assertSame(
      ['SSH_PRIVATE_KEY', 'SERVER_HOST', 'SERVER_USER'],
      array_keys($prod['secrets'] ?? []),
    );

    foreach (self::REUSABLES as $path) {
      $workflow = $this->parsed($path);
      $on = $workflow['on'] ?? NULL;
      self::assertIsArray($on, $path);
      self::assertArrayHasKey('workflow_call', $on, $path);
      self::assertArrayNotHasKey('issue_comment', $on, $path);
    }

    $source = $this->source(self::DISPATCHER);
    self::assertStringNotContainsString('secrets: inherit', $source);
    self::assertStringNotContainsString('GENERIC_COMMAND_EXECUTION', $source);
    self::assertStringNotContainsString('workflow_dispatch:', $source);
  }

  /**
   * Classifies one body with the repository-owned route table.
   */
  private function classify(array $routes, string $body, int $issue): string {
    $matches = [];
    foreach ($routes as $route) {
      $routeName = $route['route'] ?? NULL;
      $requiredIssue = self::INCIDENT_ISSUES[$routeName] ?? NULL;
      if ($requiredIssue !== NULL && $issue !== $requiredIssue) {
        continue;
      }
      $matched = in_array($body, $route['exact'] ?? [], TRUE);
      $pattern = $route['regex'] ?? NULL;
      if (is_string($pattern)) {
        $matched = $matched || preg_match('~' . str_replace('~', '\\~', $pattern) . '~D', $body) === 1;
      }
      $template = $route['regex_template'] ?? NULL;
      if (is_string($template)) {
        $pattern = str_replace('{issue}', (string) $issue, $template);
        $matched = $matched || preg_match('~' . str_replace('~', '\\~', $pattern) . '~D', $body) === 1;
      }
      if ($matched) {
        $matches[] = $routeName;
      }
    }
    return count($matches) === 1 ? $matches[0] : 'NONE';
  }

  /**
   * Parses one repository workflow structurally.
   */
  private function parsed(string $relativePath): array {
    $path = dirname(DRUPAL_ROOT) . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Reads one repository source file.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

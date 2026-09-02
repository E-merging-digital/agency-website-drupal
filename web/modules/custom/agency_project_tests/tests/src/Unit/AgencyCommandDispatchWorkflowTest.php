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
    'PRODUCTION_SCHEDULER' =>
    '.github/workflows/production-scheduler-change.yml',
    'EDITORIAL_PUBLICATION' =>
    '.github/workflows/trusted-editorial-publication.yml',
    'EDITORIAL_PREPROD_CANDIDATE' =>
    '.github/workflows/trusted-editorial-preprod-candidate.yml',
    'EDITORIAL_FEATURE_IMAGE' =>
    '.github/workflows/trusted-editorial-feature-image.yml',
    'PREPROD_REFRESH' => '.github/workflows/preprod-914-governed-successor.yml',
    'DEVELOPMENT_SEED' => '.github/workflows/development-seed-publish.yml',
    'PREPROD_REFRESH_940_DIAGNOSTIC' =>
    '.github/workflows/preprod-refresh-940-diagnostic.yml',
    'PREPROD_REFRESH_940_RECOVERY' =>
    '.github/workflows/preprod-refresh-940-recovery.yml',
    'PREPROD_REFRESH_948_DETAIL' =>
    '.github/workflows/preprod-refresh-948-detail-diagnostic.yml',
    'PREPROD_BLOG_IMAGE_DIAGNOSTIC' =>
    '.github/workflows/preprod-blog-image-diagnostic.yml',
    'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971' =>
    '.github/workflows/preprod-editorial-image-rehydrate-971.yml',
    'CONFIG_SYNC_RUNTIME_DIAGNOSTIC' =>
    '.github/workflows/config-sync-runtime-diagnostic.yml',
  ];

  private const INCIDENT_ISSUES = [
    'DEVELOPMENT_SEED' => 956,
    'PREPROD_REFRESH_940_DIAGNOSTIC' => 941,
    'PREPROD_REFRESH_940_RECOVERY' => 943,
    'PREPROD_REFRESH_948_DETAIL' => 949,
    'PREPROD_BLOG_IMAGE_DIAGNOSTIC' => 966,
    'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971' => 971,
    'CONFIG_SYNC_RUNTIME_DIAGNOSTIC' => 961,
  ];

  /**
   * Exactly one workflow may listen to issue_comment, and it is the dispatcher.
   */
  public function testSingleTopLevelIssueCommentListener(): void {
    $root = dirname(DRUPAL_ROOT);
    $listeners = [];
    $pattern = $root . '/.github/workflows/*.{yml,yaml}';
    foreach (glob($pattern, GLOB_BRACE) ?: [] as $path) {
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
   * The exact repository-owned route table is unique and fail-closed.
   */
  public function testRoutingMatrixAndPrefixUniqueness(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    self::assertArrayHasKey('env', $dispatcher);
    $raw = $dispatcher['env']['AGENCY_COMMAND_ROUTES'] ?? NULL;
    self::assertIsString($raw);
    $routes = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($routes);
    self::assertCount(13, $routes);

    $routeNames = array_column($routes, 'route');
    self::assertSame(array_keys(self::REUSABLES), $routeNames);
    self::assertCount(count($routeNames), array_unique($routeNames));

    $prefixes = array_column($routes, 'prefix');
    self::assertCount(count($prefixes), array_unique($prefixes));
    foreach ($prefixes as $index => $prefix) {
      foreach ($prefixes as $otherIndex => $otherPrefix) {
        if ($index === $otherIndex) {
          continue;
        }
        self::assertFalse(
          str_starts_with($prefix, $otherPrefix),
          sprintf('Command prefix %s overlaps %s.', $prefix, $otherPrefix),
        );
      }
    }

    $sha40 = str_repeat('a', 40);
    $sha64 = str_repeat('b', 64);
    $known = [
      [
        "/agency-production-promote go sha={$sha40} artifact={$sha64} "
        . "composer={$sha64} build=123 preprod=456",
        401,
        'PRODUCTION_PROMOTE',
      ],
      [
        "/agency-production-scheduler action=CREATE release={$sha40} "
        . 'expected=ABSENT',
        401,
        'PRODUCTION_SCHEDULER',
      ],
      ['/agency-editorial inspect', 402, 'EDITORIAL_PUBLICATION'],
      ['/agency-editorial-candidate dry-run', 958, 'EDITORIAL_PREPROD_CANDIDATE'],
      [
        '/agency-editorial-image dry-run',
        401,
        'EDITORIAL_FEATURE_IMAGE',
      ],
      [
        '/agency-preprod-refresh-successor PLAN '
        . "plan-923-abcdefgh-r1 {$sha40} AUTO "
        . 'agency-preprod-refresh-simple-v1',
        923,
        'PREPROD_REFRESH',
      ],
      [
        "/agency-development-seed publish seed-956-abcdefgh-r1 {$sha40} "
        . "apply-953-current-r1 {$sha40}",
        956,
        'DEVELOPMENT_SEED',
      ],
      [
        '/agency-preprod-refresh-940-diagnostic diagnose',
        941,
        'PREPROD_REFRESH_940_DIAGNOSTIC',
      ],
      [
        '/agency-preprod-refresh-940-recovery plan',
        943,
        'PREPROD_REFRESH_940_RECOVERY',
      ],
      [
        '/agency-preprod-refresh-940-recovery cleanup',
        943,
        'PREPROD_REFRESH_940_RECOVERY',
      ],
      [
        '/agency-preprod-refresh-948-detail diagnose',
        949,
        'PREPROD_REFRESH_948_DETAIL',
      ],
      [
        '/agency-preprod-blog-image-diagnostic diagnose',
        966,
        'PREPROD_BLOG_IMAGE_DIAGNOSTIC',
      ],
      [
        '/agency-preprod-image-rehydrate dry-run',
        971,
        'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971',
      ],
      [
        '/agency-preprod-image-rehydrate apply',
        971,
        'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971',
      ],
      [
        '/agency-config-sync-runtime diagnose',
        961,
        'CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      ],
    ];
    foreach ($known as [$body, $issue, $expected]) {
      self::assertSame(
        $expected,
        $this->classify($routes, $body, $issue),
      );
    }

    foreach ([
      [
        "/agency-development-seed publish seed-956-abcdefgh-r1 {$sha40} "
        . "apply-953-current-r1 {$sha40}",
        955,
      ],
      ['/agency-preprod-refresh-940-diagnostic diagnose', 940],
      ['/agency-preprod-refresh-940-recovery plan', 941],
      ['/agency-preprod-refresh-940-recovery cleanup', 940],
      ['/agency-preprod-refresh-948-detail diagnose', 948],
      ['/agency-preprod-refresh-948-detail diagnose', 950],
      ['/agency-preprod-blog-image-diagnostic diagnose', 965],
      ['/agency-preprod-blog-image-diagnostic diagnose', 967],
      ['/agency-preprod-image-rehydrate dry-run', 970],
      ['/agency-preprod-image-rehydrate apply', 972],
      ['/agency-config-sync-runtime diagnose', 960],
      ['/agency-config-sync-runtime diagnose', 962],
    ] as [$body, $wrongIssue]) {
      self::assertSame('NONE', $this->classify($routes, $body, $wrongIssue));
    }

    $invalid = [
      'ordinary project lead comment',
      '/agency-production-promote go sha=bad',
      '/agency-production-scheduler action=CREATE release=' . $sha40
      . ' expected=CONTROLLED',
      '/agency-editorial inspect now',
      '/agency-editorial-candidate apply now',
      '/agency-development-seed publish seed-956-bad-r1 ' . $sha40 . ' bad ' . $sha40,
      '/agency-preprod-refresh-940-recovery plan now',
      '/agency-preprod-refresh-948-detail diagnose now',
      '/agency-preprod-blog-image-diagnostic diagnose now',
      '/agency-preprod-image-rehydrate dry-run now',
      '/agency-preprod-image-rehydrate apply asset=other.png',
      '/agency-config-sync-runtime diagnose now',
      '/agency-config-sync-runtime diagnose target=PROD',
      '/agency-unknown apply',
    ];
    foreach ($invalid as $body) {
      self::assertSame(
        'NONE',
        $this->classify($routes, $body, 923),
        $body,
      );
    }

    // An accidental future route collision must fail closed.
    $collision = $routes;
    $collision[] = [
      'route' => 'COLLISION',
      'prefix' => '/collision ',
      'exact' => ['/agency-editorial inspect'],
    ];
    self::assertSame(
      'NONE',
      $this->classify($collision, '/agency-editorial inspect', 402),
    );

    $source = $this->source(self::DISPATCHER);
    self::assertStringContainsString(
      "route = matches[0] if len(matches) == 1 else 'NONE'",
      $source,
    );
    self::assertStringContainsString("'DEVELOPMENT_SEED': '956'", $source);
    self::assertStringContainsString("'PREPROD_REFRESH_940_DIAGNOSTIC': '941'", $source);
    self::assertStringContainsString("'PREPROD_REFRESH_940_RECOVERY': '943'", $source);
    self::assertStringContainsString("'PREPROD_REFRESH_948_DETAIL': '949'", $source);
    self::assertStringContainsString("'PREPROD_BLOG_IMAGE_DIAGNOSTIC': '966'", $source);
    self::assertStringContainsString("'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971': '971'", $source);
    self::assertStringContainsString("'CONFIG_SYNC_RUNTIME_DIAGNOSTIC': '961'", $source);
    self::assertStringContainsString('required_issue = incident_issue.get', $source);
  }

  /**
   * Routing stays native, permissions bounded, and secrets mapped explicitly.
   */
  public function testReusableRoutesRemainAuthorizedDownstream(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    $source = $this->source(self::DISPATCHER);
    self::assertStringNotContainsString('secrets: inherit', $source);

    $jobs = $dispatcher['jobs'] ?? [];
    self::assertSame([], $jobs['classify']['permissions'] ?? NULL);
    self::assertArrayNotHasKey('secrets', $jobs['classify']);

    $prodSecrets = ['SSH_PRIVATE_KEY', 'SERVER_HOST', 'SERVER_USER'];
    $preprodSecrets = [
      'SSH_PRIVATE_KEY',
      'PREPROD_SSH_PRIVATE_KEY',
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'SERVER_HOST',
      'SERVER_USER',
      'PREPROD_SERVER_HOST',
    ];
    $diagnosticSecrets = ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'];
    $candidateSecrets = ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'];
    $seedSecrets = ['PREPROD_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'];
    $rootPreprodSecrets = ['PREPROD_PROVISIONING_SSH_PRIVATE_KEY', 'PREPROD_SERVER_HOST'];
    $jobMap = [
      'production-promote' => [
        'PRODUCTION_PROMOTE',
        ['actions' => 'read', 'contents' => 'read', 'issues' => 'write'],
        $prodSecrets,
      ],
      'production-scheduler' => [
        'PRODUCTION_SCHEDULER',
        ['contents' => 'read', 'issues' => 'read'],
        $prodSecrets,
      ],
      'editorial-publication' => [
        'EDITORIAL_PUBLICATION',
        ['contents' => 'read', 'issues' => 'write'],
        $prodSecrets,
      ],
      'editorial-preprod-candidate' => [
        'EDITORIAL_PREPROD_CANDIDATE',
        ['contents' => 'read', 'issues' => 'write'],
        $candidateSecrets,
      ],
      'editorial-feature-image' => [
        'EDITORIAL_FEATURE_IMAGE',
        ['contents' => 'read', 'issues' => 'write'],
        $prodSecrets,
      ],
      'preprod-refresh' => [
        'PREPROD_REFRESH',
        ['contents' => 'read', 'issues' => 'read'],
        $preprodSecrets,
      ],
      'development-seed' => [
        'DEVELOPMENT_SEED',
        ['contents' => 'read', 'issues' => 'write'],
        $seedSecrets,
      ],
      'preprod-refresh-940-diagnostic' => [
        'PREPROD_REFRESH_940_DIAGNOSTIC',
        ['contents' => 'read', 'issues' => 'read'],
        $diagnosticSecrets,
      ],
      'preprod-refresh-940-recovery' => [
        'PREPROD_REFRESH_940_RECOVERY',
        ['contents' => 'read', 'issues' => 'read'],
        $rootPreprodSecrets,
      ],
      'preprod-refresh-948-detail' => [
        'PREPROD_REFRESH_948_DETAIL',
        ['contents' => 'read', 'issues' => 'read'],
        $rootPreprodSecrets,
      ],
      'preprod-blog-image-diagnostic' => [
        'PREPROD_BLOG_IMAGE_DIAGNOSTIC',
        ['contents' => 'read', 'issues' => 'write'],
        $diagnosticSecrets,
      ],
      'preprod-editorial-image-rehydrate-971' => [
        'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971',
        ['contents' => 'read', 'issues' => 'write'],
        $diagnosticSecrets,
      ],
      'config-sync-runtime-diagnostic' => [
        'CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
        ['contents' => 'read', 'issues' => 'write'],
        $diagnosticSecrets,
      ],
    ];

    foreach ($jobMap as $jobId => [$route, $permissions, $secretNames]) {
      $job = $jobs[$jobId] ?? NULL;
      self::assertIsArray($job, $jobId);
      self::assertSame('./' . self::REUSABLES[$route], $job['uses'] ?? NULL);
      self::assertSame($permissions, $job['permissions'] ?? NULL);
      self::assertSame($secretNames, array_keys($job['secrets'] ?? []));
      self::assertStringContainsString(
        "needs.classify.outputs.route == '{$route}'",
        (string) ($job['if'] ?? ''),
      );
    }

    foreach (self::REUSABLES as $path) {
      $workflow = $this->parsed($path);
      $on = $workflow['on'] ?? NULL;
      self::assertIsArray($on, $path);
      self::assertArrayHasKey('workflow_call', $on, $path);
      self::assertArrayNotHasKey('issue_comment', $on, $path);
      $workflowSource = $this->source($path);
      self::assertStringContainsString('github.event.issue', $workflowSource, $path);
      self::assertStringContainsString('github.event.comment', $workflowSource, $path);
    }

    self::assertStringNotContainsString('GENERIC_COMMAND_EXECUTION', $source);
    self::assertStringNotContainsString('workflow_dispatch:', $source);
  }

  /**
   * Classifies a body using the exact repository-owned route table.
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
        $regex = '~' . str_replace('~', '\\~', $pattern) . '~D';
        $matched = $matched || preg_match($regex, $body) === 1;
      }
      $template = $route['regex_template'] ?? NULL;
      if (is_string($template)) {
        $pattern = str_replace('{issue}', (string) $issue, $template);
        $regex = '~' . str_replace('~', '\\~', $pattern) . '~D';
        $matched = $matched || preg_match($regex, $body) === 1;
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
    $root = dirname(DRUPAL_ROOT);
    $path = $root . '/' . $relativePath;
    self::assertFileExists($path);
    $parsed = Yaml::parseFile($path);
    self::assertIsArray($parsed);
    return $parsed;
  }

  /**
   * Returns one repository workflow as source text.
   */
  private function source(string $relativePath): string {
    return (string) file_get_contents(dirname(DRUPAL_ROOT) . '/' . $relativePath);
  }

}

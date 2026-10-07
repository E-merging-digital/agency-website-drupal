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
    'CANVAS_AI_PROVIDER_PROOF_530' => '.github/workflows/trusted-canvas-ai-provider-proof.yml',
    'CANVAS_AI_CANDIDATE_ADDRESSING_DIAGNOSTIC_1392' => '.github/workflows/canvas-ai-candidate-addressing-diagnostic.yml',
    'INFRA_COCKPIT_CONSUMER_PROOF' => '.github/workflows/infrastructure-cockpit-consumer-proof.yml',
  ];

  private const INCIDENT_ISSUES = [
    'DEVELOPMENT_SEED' => 956,
    'DEVELOPMENT_SEED_CLEANUP_PROOF' => 956,
    'PREPROD_REFRESH_940_DIAGNOSTIC' => 941,
    'PREPROD_REFRESH_940_RECOVERY' => 943,
    'PREPROD_REFRESH_948_DETAIL' => 949,
    'PREPROD_BLOG_IMAGE_DIAGNOSTIC' => 966,
    'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971' => 971,
    'CONFIG_SYNC_RUNTIME_DIAGNOSTIC' => [982, 995, 1318],
    'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC' => [982, 995, 1301, 1302],
    'CANVAS_AI_PROVIDER_PROOF_530' => 530,
    'CANVAS_AI_CANDIDATE_ADDRESSING_DIAGNOSTIC_1392' => 1392,
    'INFRA_COCKPIT_CONSUMER_PROOF' => 1261,
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
   * The historical routing matrix stays covered with #982/#995 and #1318.
   */
  public function testRoutingMatrixAndIssue982And995And1318Bindings(): void {
    $dispatcher = $this->parsed(self::DISPATCHER);
    self::assertArrayHasKey('env', $dispatcher);
    $raw = $dispatcher['env']['AGENCY_COMMAND_ROUTES'] ?? NULL;
    self::assertIsString($raw);
    $routes = json_decode($raw, TRUE, 32, JSON_THROW_ON_ERROR);
    self::assertIsArray($routes);
    self::assertCount(17, $routes);

    $routeNames = array_column($routes, 'route');
    self::assertSame(array_keys(self::REUSABLES), $routeNames);
    self::assertCount(count($routeNames), array_unique($routeNames));

    $prefixes = array_column($routes, 'prefix');
    self::assertCount(count($prefixes), array_unique($prefixes));
    $developmentSeed = $routes[array_search('DEVELOPMENT_SEED', $routeNames, TRUE)] ?? NULL;
    self::assertIsArray($developmentSeed);
    self::assertSame('DEVELOPMENT_SEED_CLEANUP_PROOF', $developmentSeed['cleanup_route'] ?? NULL);
    self::assertSame('/agency-development-seed-cleanup-proof ', $developmentSeed['cleanup_prefix'] ?? NULL);
    self::assertSame(
      '^/agency-development-seed-cleanup-proof run=[1-9][0-9]* request=seed-956-[A-Za-z0-9._-]{8,40}-r1 main=[0-9a-f]{40}$',
      $developmentSeed['cleanup_regex'] ?? NULL,
    );
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
      ['/agency-editorial-image dry-run', 401, 'EDITORIAL_FEATURE_IMAGE'],
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
        "/agency-development-seed-cleanup-proof run=34696289170 "
        . "request=seed-956-abcdefgh-r1 main={$sha40}",
        956,
        'DEVELOPMENT_SEED_CLEANUP_PROOF',
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
        982,
        'CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      ],
      [
        '/agency-config-sync-prod-runtime diagnose',
        982,
        'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      ],
      [
        '/agency-config-sync-runtime diagnose',
        995,
        'CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      ],
      [
        '/agency-config-sync-runtime diagnose',
        1318,
        'CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      ],
      [
        '/agency-config-sync-prod-runtime diagnose',
        995,
        'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      ],
      [
        '/agency-config-sync-prod-runtime diagnose',
        1301,
        'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      ],
      [
        '/agency-config-language-lock-prod diagnose',
        1302,
        'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      ],
      [
        "/agency-canvas-ai-provider-proof run pr=533 sha={$sha40}",
        530,
        'CANVAS_AI_PROVIDER_PROOF_530',
      ],
      [
        "/agency-canvas-addressing-diagnostic run pr=533 sha={$sha40}",
        1392,
        'CANVAS_AI_CANDIDATE_ADDRESSING_DIAGNOSTIC_1392',
      ],
      [
        "/agency-infra-cockpit-consumer prove main={$sha40} infra={$sha40} release={$sha40} "
        . "session=0123456789abcdef pubkey={$sha64}",
        1261,
        'INFRA_COCKPIT_CONSUMER_PROOF',
      ],
    ];
    foreach ($known as [$body, $issue, $expected]) {
      self::assertSame($expected, $this->classify($routes, $body, $issue));
    }

    foreach ([
      [
        "/agency-development-seed publish seed-956-abcdefgh-r1 {$sha40} "
        . "apply-953-current-r1 {$sha40}",
        955,
      ],
      [
        "/agency-development-seed-cleanup-proof run=34696289170 "
        . "request=seed-956-abcdefgh-r1 main={$sha40}",
        955,
      ],
      [
        "/agency-development-seed-cleanup-proof run=34696289170 "
        . "request=seed-956-abcdefgh-r1 main={$sha40}",
        957,
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
      ['/agency-config-sync-runtime diagnose', 961],
      ['/agency-config-sync-runtime diagnose', 980],
      ['/agency-config-sync-runtime diagnose', 981],
      ['/agency-config-sync-runtime diagnose', 983],
      ['/agency-config-sync-runtime diagnose', 1317],
      ['/agency-config-sync-runtime diagnose', 1319],
      ['/agency-config-sync-prod-runtime diagnose', 961],
      ['/agency-config-sync-prod-runtime diagnose', 980],
      ['/agency-config-sync-prod-runtime diagnose', 981],
      ['/agency-config-sync-prod-runtime diagnose', 983],
      ['/agency-config-sync-prod-runtime diagnose', 1300],
      ['/agency-config-sync-prod-runtime diagnose', 1302],
      ['/agency-config-language-lock-prod diagnose', 982],
      ['/agency-config-language-lock-prod diagnose', 995],
      ['/agency-config-language-lock-prod diagnose', 1301],
      ['/agency-config-language-lock-prod diagnose', 1303],
      ["/agency-canvas-ai-provider-proof run pr=533 sha={$sha40}", 529],
      ["/agency-canvas-ai-provider-proof run pr=533 sha={$sha40}", 531],
      [
        "/agency-infra-cockpit-consumer prove main={$sha40} infra={$sha40} release={$sha40} "
        . "session=0123456789abcdef pubkey={$sha64}",
        1260,
      ],
      [
        "/agency-infra-cockpit-consumer prove main={$sha40} infra={$sha40} release={$sha40} "
        . "session=0123456789abcdef pubkey={$sha64}",
        1262,
      ],
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
      '/agency-development-seed-cleanup-proof run=0 request=seed-956-abcdefgh-r1 main=' . $sha40,
      '/agency-development-seed-cleanup-proof run=34696289170 request=seed-956-../escape-r1 main=' . $sha40,
      '/agency-development-seed-cleanup-proof run=34696289170 request=seed-956-short-r1 main=' . $sha40,
      '/agency-development-seed-cleanup-proof run=34696289170 request=seed-956-abcdefgh-r1 main=BAD',
      '/agency-preprod-refresh-940-recovery plan now',
      '/agency-preprod-refresh-948-detail diagnose now',
      '/agency-preprod-blog-image-diagnostic diagnose now',
      '/agency-preprod-image-rehydrate dry-run now',
      '/agency-preprod-image-rehydrate apply asset=other.png',
      '/agency-config-sync-runtime diagnose now',
      '/agency-config-sync-runtime diagnose target=PROD',
      '/agency-config-sync-prod-runtime diagnose now',
      '/agency-config-sync-prod-runtime diagnose target=PREPROD',
      '/agency-canvas-ai-provider-proof run pr=532 sha=' . $sha40,
      '/agency-canvas-ai-provider-proof run pr=533 sha=BAD',
      '/agency-canvas-ai-provider-proof inspect pr=533 sha=' . $sha40,
      '/agency-infra-cockpit-consumer prove main=BAD infra=' . $sha40
      . ' release=' . $sha40 . ' session=0123456789abcdef pubkey=' . $sha64,
      '/agency-infra-cockpit-consumer prove main=' . $sha40
      . ' infra=BAD release=' . $sha40 . ' session=0123456789abcdef pubkey=' . $sha64,
      '/agency-infra-cockpit-consumer prove main=' . $sha40
      . ' infra=' . $sha40 . ' release=BAD session=0123456789abcdef pubkey=' . $sha64,
      '/agency-infra-cockpit-consumer prove main=' . $sha40
      . ' infra=' . $sha40 . ' release=' . $sha40 . ' session=BAD pubkey=' . $sha64,
      '/agency-infra-cockpit-consumer prove main=' . $sha40
      . ' infra=' . $sha40 . ' release=' . $sha40 . ' session=0123456789abcdef pubkey=BAD',
      '/agency-unknown apply',
    ];
    foreach ($invalid as $body) {
      self::assertSame('NONE', $this->classify($routes, $body, 982), $body);
      self::assertSame('NONE', $this->classify($routes, $body, 995), $body);
      self::assertSame('NONE', $this->classify($routes, $body, 1318), $body);
    }

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
    self::assertStringContainsString(
      "'DEVELOPMENT_SEED_CLEANUP_PROOF': '956'",
      $source,
    );
    self::assertStringContainsString(
      "'PREPROD_REFRESH_940_DIAGNOSTIC': '941'",
      $source,
    );
    self::assertStringContainsString(
      "'PREPROD_REFRESH_940_RECOVERY': '943'",
      $source,
    );
    self::assertStringContainsString(
      "'PREPROD_REFRESH_948_DETAIL': '949'",
      $source,
    );
    self::assertStringContainsString(
      "'PREPROD_BLOG_IMAGE_DIAGNOSTIC': '966'",
      $source,
    );
    self::assertStringContainsString(
      "'PREPROD_EDITORIAL_IMAGE_REHYDRATE_971': '971'",
      $source,
    );
    self::assertStringContainsString(
      "'CONFIG_SYNC_RUNTIME_DIAGNOSTIC': ('982', '995', '1318')",
      $source,
    );
    self::assertStringContainsString(
      "'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC': ('982', '995', '1301', '1302')",
      $source,
    );
    self::assertStringContainsString(
      "'CANVAS_AI_PROVIDER_PROOF_530': '530'",
      $source,
    );
    self::assertStringContainsString(
      "'CANVAS_AI_CANDIDATE_ADDRESSING_DIAGNOSTIC_1392': '1392'",
      $source,
    );
    self::assertStringContainsString(
      "'INFRA_COCKPIT_CONSUMER_PROOF': '1261'",
      $source,
    );
    self::assertStringNotContainsString(
      "'CONFIG_SYNC_RUNTIME_DIAGNOSTIC': '961'",
      $source,
    );
    self::assertStringNotContainsString(
      "'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC': '980'",
      $source,
    );
    self::assertStringContainsString('required_issue = incident_issue.get', $source);
    self::assertStringContainsString("issue == '1301'", $source);
    self::assertStringContainsString("issue == '1302'", $source);
    self::assertStringContainsString(
      "language_lock_command = '/agency-config-language-lock-prod diagnose'",
      $source,
    );
    self::assertStringContainsString("comment_author != 'E-merging-digital'", $source);
    self::assertStringContainsString("comment_author_association != 'OWNER'", $source);
    self::assertStringContainsString('or comment_from_app', $source);

    self::assertSame(
      'CANVAS_AI_PROVIDER_PROOF_530',
      $this->classify(
        $routes,
        "/agency-canvas-ai-provider-proof run pr=533 sha={$sha40}",
        530,
        'E-merging-digital',
        'OWNER',
        FALSE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        "/agency-canvas-ai-provider-proof run pr=533 sha={$sha40}",
        530,
        'other-user',
        'CONTRIBUTOR',
        FALSE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        "/agency-canvas-ai-provider-proof run pr=533 sha={$sha40}",
        530,
        'E-merging-digital',
        'OWNER',
        TRUE,
      ),
    );

    self::assertSame(
      'CANVAS_AI_CANDIDATE_ADDRESSING_DIAGNOSTIC_1392',
      $this->classify(
        $routes,
        "/agency-canvas-addressing-diagnostic run pr=533 sha={$sha40}",
        1392,
        'E-merging-digital',
        'OWNER',
        FALSE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        "/agency-canvas-addressing-diagnostic run pr=533 sha={$sha40}",
        1392,
        'other-user',
        'CONTRIBUTOR',
        FALSE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        "/agency-canvas-addressing-diagnostic run pr=533 sha={$sha40}",
        1392,
        'E-merging-digital',
        'OWNER',
        TRUE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        "/agency-canvas-addressing-diagnostic run pr=534 sha={$sha40}",
        1392,
        'E-merging-digital',
        'OWNER',
        FALSE,
      ),
    );

    self::assertSame(
      'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      $this->classify(
        $routes,
        '/agency-config-sync-prod-runtime diagnose',
        1301,
        'E-merging-digital',
        'OWNER',
        FALSE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        '/agency-config-sync-prod-runtime diagnose',
        1301,
        'other-user',
        'CONTRIBUTOR',
        FALSE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        '/agency-config-sync-prod-runtime diagnose',
        1301,
        'E-merging-digital',
        'OWNER',
        TRUE,
      ),
    );
    self::assertSame(
      'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
      $this->classify(
        $routes,
        '/agency-config-language-lock-prod diagnose',
        1302,
        'E-merging-digital',
        'OWNER',
        FALSE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        '/agency-config-language-lock-prod diagnose',
        1302,
        'other-user',
        'CONTRIBUTOR',
        FALSE,
      ),
    );
    self::assertSame(
      'NONE',
      $this->classify(
        $routes,
        '/agency-config-language-lock-prod diagnose',
        1302,
        'E-merging-digital',
        'OWNER',
        TRUE,
      ),
    );
  }

  /**
   * Historical permissions and explicit secret mappings remain protected.
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
    $rootPreprodSecrets = [
      'PREPROD_PROVISIONING_SSH_PRIVATE_KEY',
      'PREPROD_SERVER_HOST',
    ];
    $canvasAiSecrets = ['OPENAI_API_KEY'];
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
      'development-seed-cleanup-proof' => [
        'DEVELOPMENT_SEED_CLEANUP_PROOF',
        ['contents' => 'read', 'issues' => 'write'],
        [],
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
      'prod-config-sync-runtime-diagnostic' => [
        'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC',
        ['contents' => 'read', 'issues' => 'write'],
        $prodSecrets,
      ],
      'canvas-ai-provider-proof-530' => [
        'CANVAS_AI_PROVIDER_PROOF_530',
        ['contents' => 'read', 'issues' => 'read', 'pull-requests' => 'write'],
        $canvasAiSecrets,
      ],
      'canvas-ai-candidate-addressing-diagnostic-1392' => [
        'CANVAS_AI_CANDIDATE_ADDRESSING_DIAGNOSTIC_1392',
        ['contents' => 'read', 'issues' => 'read', 'pull-requests' => 'read'],
        [],
      ],
      'infrastructure-cockpit-consumer-proof' => [
        'INFRA_COCKPIT_CONSUMER_PROOF',
        ['contents' => 'read', 'issues' => 'write'],
        $rootPreprodSecrets,
      ],
    ];

    foreach ($jobMap as $jobId => [$route, $permissions, $secretNames]) {
      $job = $jobs[$jobId] ?? NULL;
      self::assertIsArray($job, $jobId);
      $expectedReusable = $route === 'DEVELOPMENT_SEED_CLEANUP_PROOF'
        ? './.github/workflows/development-seed-cleanup-proof.yml'
        : './' . self::REUSABLES[$route];
      self::assertSame($expectedReusable, $job['uses'] ?? NULL);
      self::assertSame($permissions, $job['permissions'] ?? NULL);
      self::assertSame($secretNames, array_keys($job['secrets'] ?? []));
      self::assertStringContainsString(
        "needs.classify.outputs.route == '{$route}'",
        (string) ($job['if'] ?? ''),
      );
    }

    foreach ([...array_values(self::REUSABLES), '.github/workflows/development-seed-cleanup-proof.yml'] as $path) {
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
   * Restored #1362 provider route stays exact, reusable and fail closed.
   */
  public function testCanvasAiProviderProofRouteIsBoundedAndJitValidated(): void {
    $workflowPath = '.github/workflows/trusted-canvas-ai-provider-proof.yml';
    $workflow = $this->parsed($workflowPath);
    $source = $this->source($workflowPath);
    $on = $workflow['on'] ?? NULL;
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertSame(
      ['OPENAI_API_KEY'],
      array_keys($on['workflow_call']['secrets'] ?? []),
    );
    self::assertTrue(
      $on['workflow_call']['secrets']['OPENAI_API_KEY']['required'] ?? FALSE,
    );

    foreach ([
      'test "$ISSUE_NUMBER" = \'530\'',
      'test "$COMMENT_AUTHOR" = \'E-merging-digital\'',
      'test "$COMMENT_AUTHOR_ASSOCIATION" = \'OWNER\'',
      'test "$COMMENT_FROM_APP" = \'false\'',
      '^/agency-canvas-ai-provider-proof\\ run\\ pr=533\\ sha=([0-9a-f]{40})$',
      'repos/$GITHUB_REPOSITORY/issues/530',
      'repos/$GITHUB_REPOSITORY/pulls/533',
      'test "$head_sha" = "$requested_sha"',
      'test "$(jq -r \'.head.sha\' <<<"$pr_json")" = "$EXPECTED_HEAD_SHA"',
      'persist-credentials: false',
      'PROVIDER_SECRET: ${{ secrets.OPENAI_API_KEY }}',
      'npx playwright test tests/browser/canvas-ai-provider-proof.spec.mjs --project=desktop --workers=1',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    $providerJob = $workflow['jobs']['provider-proof'] ?? NULL;
    self::assertIsArray($providerJob);
    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency', 'ddev', 'browser'],
      $providerJob['runs-on'] ?? NULL,
    );

    $dedicatedPorts = [
      'host_mailpit_port' => '19025',
      'host_webserver_port' => '19080',
      'host_db_port' => '19306',
      'host_https_port' => '19443',
      'router_http_port' => '19180',
      'router_https_port' => '19444',
    ];
    $configuredPorts = [];
    foreach ($dedicatedPorts as $key => $expectedPort) {
      self::assertStringContainsString(
        sprintf('%s: "%s"', $key, $expectedPort),
        $source,
      );
      $pattern = sprintf('/%s: "([0-9]+)"/', preg_quote($key, '/'));
      self::assertSame(1, preg_match($pattern, $source, $matches));
      $configuredPort = (int) ($matches[1] ?? 0);
      self::assertSame((int) $expectedPort, $configuredPort);
      $this->assertOutsideHostEphemeralRange($configuredPort, $key);
      $configuredPorts[$key] = $configuredPort;
    }
    self::assertGreaterThan(1024, $configuredPorts['router_http_port']);
    self::assertGreaterThan(1024, $configuredPorts['router_https_port']);
    self::assertNotSame(
      $configuredPorts['router_http_port'],
      $configuredPorts['host_webserver_port'],
    );
    self::assertNotSame(
      $configuredPorts['router_https_port'],
      $configuredPorts['host_https_port'],
    );

    foreach ([
      'rm -f .ddev/.env.web',
      'ddev delete --omit-snapshot --yes',
      'rm -f .ddev/config.gate-canvas-ai-provider.yaml',
      'rm -f "${RUNNER_TEMP}/canvas-runtime-diff-paths-995.php"',
    ] as $cleanup) {
      self::assertStringContainsString($cleanup, $source);
    }

    foreach ([
      'ddev drush config:status --format=json',
      'scripts/runner/filter-config-status-metadata.php PREPROD',
      'canvas-ai-provider-config-drift-gate-1373.php?ref=$GITHUB_SHA',
      'canvas-runtime-diff-paths-995.php?ref=$GITHUB_SHA',
      'trusted_comparator="${RUNNER_TEMP}/canvas-runtime-diff-paths-995.php"',
      'test -s "$trusted_comparator"',
      'tail -n +2 "$trusted_comparator"',
      'AGENCY_CANVAS_1373_EXECUTE=1',
      'AGENCY_CANVAS_1373_STATUS_B64=',
      'KNOWN_CANVAS_DETERMINISTIC_DRIFT_PATTERN',
      'CANVAS_CONFIG_DRIFT_GATE=PASS',
      '.summary == {"total": 19, "known": 19, "unexpected": 0}',
    ] as $gateContract) {
      self::assertStringContainsString($gateContract, $source);
    }
    self::assertStringNotContainsString(
      'tail -n +2 scripts/runner/canvas-runtime-diff-paths-995.php',
      $source,
    );
    $trustedComparator = $this->source(
      'scripts/runner/canvas-runtime-diff-paths-995.php',
    );
    self::assertStringContainsString(
      'Unknown Canvas map key type at path %s; key_type=%s',
      $trustedComparator,
    );
    foreach ([
      "\$path === 'versioned_properties'",
      '$sync_version === $canonical_key',
      '&& $active_exists',
      '&& !$sync_exists',
      '$active_exists !== $sync_exists',
    ] as $numericVersionBoundary) {
      self::assertStringContainsString(
        $numericVersionBoundary,
        $trustedComparator,
      );
    }
    self::assertStringNotContainsString(
      "grep -Fq 'No differences'",
      $source,
    );
    self::assertStringNotContainsString(
      'printf \'%s\\n\' "$config_status_raw"',
      $source,
    );

    $driftGate = strpos($source, 'CANVAS_CONFIG_DRIFT_GATE=PASS');
    $providerExecution = strpos(
      $source,
      'npx playwright test tests/browser/canvas-ai-provider-proof.spec.mjs',
    );
    self::assertNotFalse($driftGate);
    self::assertNotFalse($providerExecution);
    self::assertLessThan($providerExecution, $driftGate);

    foreach ([
      'issue_comment:',
      'workflow_dispatch:',
      'secrets: inherit',
      'SSH_PRIVATE_KEY',
      'PREPROD_SERVER_HOST',
      'SERVER_HOST',
      'workflow inputs',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }

    $jit = strpos($source, 'JIT revalidate #530 and exact live #533 HEAD before secret exposure');
    $secret = strpos($source, 'PROVIDER_SECRET: ${{ secrets.OPENAI_API_KEY }}');
    self::assertNotFalse($jit);
    self::assertNotFalse($secret);
    self::assertLessThan($secret, $jit);
  }

  /**
   * #1393 addressing diagnostic stays owner-only, secret-free and bounded.
   */
  public function testCanvasAddressingDiagnosticRouteIsBoundedAndSecretFree(): void {
    $path = '.github/workflows/canvas-ai-candidate-addressing-diagnostic.yml';
    $workflow = $this->parsed($path);
    $source = $this->source($path);

    $on = $workflow['on'] ?? NULL;
    self::assertIsArray($on);
    self::assertArrayHasKey('workflow_call', $on);
    self::assertArrayNotHasKey('issue_comment', $on);
    self::assertArrayNotHasKey('workflow_dispatch', $on);
    self::assertArrayNotHasKey('secrets', $on['workflow_call'] ?? []);

    $validate = $workflow['jobs']['validate-target'] ?? NULL;
    self::assertIsArray($validate);
    self::assertSame('ubuntu-24.04', $validate['runs-on'] ?? NULL);

    $diagnostic = $workflow['jobs']['diagnostic'] ?? NULL;
    self::assertIsArray($diagnostic);
    self::assertSame(
      ['self-hosted', 'linux', 'x64', 'agency', 'ddev', 'browser'],
      $diagnostic['runs-on'] ?? NULL,
    );

    foreach ([
      'test "$EVENT_NAME" = \'issue_comment\'',
      'test "$EVENT_ACTION" = \'created\'',
      'test "$ISSUE_NUMBER" = \'1392\'',
      'test "$COMMENT_AUTHOR" = \'E-merging-digital\'',
      'test "$COMMENT_AUTHOR_ASSOCIATION" = \'OWNER\'',
      'test "$COMMENT_FROM_APP" = \'false\'',
      'test "$GITHUB_ACTOR" = \'E-merging-digital\'',
      '^/agency-canvas-addressing-diagnostic\\ run\\ pr=533\\ sha=([0-9a-f]{40})$',
      'repos/$GITHUB_REPOSITORY/issues/1392',
      'repos/$GITHUB_REPOSITORY/issues/530',
      'repos/$GITHUB_REPOSITORY/pulls/533',
      'test "$base_ref" = \'main\'',
      'test "$head_ref" = \'feature/issue-530-bounded-canvas-ai-composition\'',
      'composer.json',
      'composer.lock',
      'config/sync/core.extension.yml',
      'docs/ai/canvas-ai-proof-policy.yml',
      'tests/browser/canvas-ai-provider-proof.spec.mjs',
      'web/modules/custom/agency_project_tests/tests/src/Unit/CanvasAiPreProviderAuditTest.php',
      '52600000-0000-4000-8000-000000000001',
      'ddev drush site:install --existing-config',
      'ddev drush emerging:governed-content --all',
      '/canvas/api/v0/layout/canvas_page/',
      '/canvas/editor/canvas_page/',
      'canvas_ai_post_count',
      'AI_CHAT_OPENED',
      'AI_PROMPT_SUBMITTED',
      'PROVIDER_CALL',
      'ddev delete --omit-snapshot --yes',
      'WORKTREE_FINAL=CLEAN',
    ] as $required) {
      self::assertStringContainsString($required, $source);
    }

    foreach ([
      'OPENAI_API_KEY',
      'SSH_PRIVATE_KEY',
      'PREPROD_SERVER_HOST',
      'SERVER_HOST',
      'secrets:',
      'submitUserMessage',
      'workflow_dispatch:',
      'issue_comment:',
    ] as $forbidden) {
      self::assertStringNotContainsString($forbidden, $source);
    }
  }
  /**
   * Checks a provider-proof port against the host ephemeral range.
   */
  private function assertOutsideHostEphemeralRange(int $port, string $key): void {
    self::assertFalse(
      $port >= 32768 && $port <= 60999,
      sprintf('%s must stay outside host ephemeral range 32768-60999.', $key),
    );
  }

  /**
   * Classifies one body with the repository-owned route table.
   */
  private function classify(
    array $routes,
    string $body,
    int $issue,
    string $commentAuthor = 'E-merging-digital',
    string $commentAuthorAssociation = 'OWNER',
    bool $commentFromApp = FALSE,
  ): string {
    $matches = [];
    foreach ($routes as $route) {
      $routeName = $route['route'] ?? NULL;
      $requiredIssue = self::INCIDENT_ISSUES[$routeName] ?? NULL;
      if (is_array($requiredIssue)) {
        if (!in_array($issue, $requiredIssue, TRUE)) {
          continue;
        }
      }
      elseif ($requiredIssue !== NULL && $issue !== $requiredIssue) {
        continue;
      }
      if (
        in_array(
          $routeName,
          [
            'CANVAS_AI_PROVIDER_PROOF_530',
            'CANVAS_AI_CANDIDATE_ADDRESSING_DIAGNOSTIC_1392',
          ],
          TRUE,
        )
        && (
          $commentAuthor !== 'E-merging-digital'
          || $commentAuthorAssociation !== 'OWNER'
          || $commentFromApp
        )
      ) {
        continue;
      }
      if ($routeName === 'PROD_CONFIG_SYNC_RUNTIME_DIAGNOSTIC') {
        $languageLockCommand = '/agency-config-language-lock-prod diagnose';
        if (
          $issue === 1302
          && (
            $body !== $languageLockCommand
            || $commentAuthor !== 'E-merging-digital'
            || $commentAuthorAssociation !== 'OWNER'
            || $commentFromApp
          )
        ) {
          continue;
        }
        if ($body === $languageLockCommand && $issue !== 1302) {
          continue;
        }
        if (
          $issue === 1301
          && (
            $commentAuthor !== 'E-merging-digital'
            || $commentAuthorAssociation !== 'OWNER'
            || $commentFromApp
          )
        ) {
          continue;
        }
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
      $cleanupRoute = $route['cleanup_route'] ?? NULL;
      $cleanupPattern = $route['cleanup_regex'] ?? NULL;
      if ($routeName === 'DEVELOPMENT_SEED' && is_string($cleanupRoute) && is_string($cleanupPattern)) {
        $cleanupIssue = self::INCIDENT_ISSUES[$cleanupRoute] ?? NULL;
        $regex = '~' . str_replace('~', '\\~', $cleanupPattern) . '~D';
        if ($issue === $cleanupIssue && preg_match($regex, $body) === 1) {
          $matches[] = $cleanupRoute;
        }
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

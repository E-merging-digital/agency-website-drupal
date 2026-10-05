<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Controller;

use Drupal\agency_operations\Service\CapabilityRegistryReader;
use Drupal\agency_operations\Service\CockpitPlanReceiptReaderInterface;
use Drupal\agency_operations\Service\EditorialLanguageReadinessInterface;
use Drupal\agency_operations\Service\RuntimeMetadataReader;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only Agency operations overview.
 */
final class OperationsController extends ControllerBase {

  private const REPOSITORY_URL = 'https://github.com/E-merging-digital/agency-website-drupal';

  private const COCKPIT_PLAN_ISSUE_TITLE = '[Ops][PLAN] Prepare PREPROD refresh PLAN';

  private const COCKPIT_PLAN_AUTHORITY_MARKER = 'AGENCY_PREPROD_COCKPIT_PLAN_AUTHORITY={"authorized_actor":"E-merging-digital","implementation_issue":914,"mode":"PLAN","parent_issue":816,"profile_id":"agency-preprod-refresh-simple-v1","run_attempt":1,"schema_version":1}';

  public function __construct(
    private readonly CapabilityRegistryReader $capabilityRegistry,
    private readonly EditorialLanguageReadinessInterface $languageReadiness,
    private readonly ModuleHandlerInterface $agencyModuleHandler,
    private readonly RuntimeMetadataReader $runtimeMetadata,
    private readonly CockpitPlanReceiptReaderInterface $planReceiptReader,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('agency_operations.capability_registry'),
      $container->get('agency_operations.language_readiness'),
      $container->get('module_handler'),
      $container->get('agency_operations.runtime_metadata'),
      $container->get('agency_operations.cockpit_plan_receipt'),
    );
  }

  /**
   * Builds the V3 human-first control-plane facade.
   */
  public function overview(): array {
    $registry = $this->capabilityRegistry->read();
    $runtime = $this->runtimeMetadata->read();
    $lastPlan = $this->planReceiptReader->read();
    $requiredLanguages = $this->languageReadiness->getRequiredLanguages();
    $refresh = $this->findCapability(
      $registry,
      'PROD -> PREPROD sanitized DB refresh',
    );
    $editorial = $this->findCapability(
      $registry,
      'Editorial Candidate PREPROD',
    );
    $developmentSeed = $this->findCapability(
      $registry,
      'Development Seed publisher/distribution',
    );

    $build = [];
    $build['overview'] = [
      '#type' => 'item',
      '#title' => $this->t('Daily operational overview'),
      '#markup' => $this->t(
        'Human-readable status is shown first. Exact technical status, authority, execution surfaces and evidence remain available under Technical details.',
      ),
    ];

    if ($runtime['release_available']) {
      $runtimeMarkup = $this->t(
        '@environment — release @release (freshness: @freshness).',
        [
          '@environment' => $runtime['environment'],
          '@release' => $runtime['release_identity'],
          '@freshness' => $runtime['freshness'],
        ],
      );
    }
    else {
      $runtimeMarkup = $this->t(
        '@environment — the current runtime does not expose a release identity from its local release path.',
        ['@environment' => $runtime['environment']],
      );
    }
    $build['runtime'] = [
      '#type' => 'item',
      '#title' => $this->t('Current runtime'),
      '#markup' => $runtimeMarkup,
    ];

    $build['environments'] = [
      '#type' => 'table',
      '#caption' => $this->t('Environments'),
      '#header' => [
        $this->t('Environment'),
        $this->t('Status'),
        $this->t('Current release'),
        $this->t('Freshness'),
      ],
      '#rows' => $this->environmentSummaryRows($registry, $runtime),
    ];

    $languageNames = [];
    foreach ($requiredLanguages as $code => $name) {
      $languageNames[] = $this->t('@name (@code)', [
        '@name' => $name,
        '@code' => $code,
      ]);
    }
    $languageStatus = $requiredLanguages === []
      ? CapabilityRegistryReader::STATUS_BLOCKED
      : CapabilityRegistryReader::STATUS_READY;
    if ($languageNames === []) {
      $languageSummary = $this->t(
        'No configurable site language is available; publication readiness fails closed.',
      );
    }
    else {
      $languageSummary = $this->t(
        '@languages are required for publication readiness.',
        ['@languages' => implode(', ', array_map('strval', $languageNames))],
      );
    }
    $build['content_summary'] = [
      '#type' => 'table',
      '#caption' => $this->t('Content'),
      '#header' => [
        $this->t('Area'),
        $this->t('Status'),
        $this->t('Summary'),
        $this->t('Next view'),
      ],
      '#rows' => [
        [
          $this->t('Content languages'),
          $this->humanStatusLabel($languageStatus),
          $languageSummary,
          Link::fromTextAndUrl(
            $this->t('Open editorial language readiness'),
            Url::fromRoute('agency_operations.editorial'),
          )->toString(),
        ],
        [
          $this->t('Editorial workflow'),
          $this->humanStatusLabel($editorial['human_status_key'] ?? NULL),
          $this->t('Any missing configured language blocks publication readiness. No publication control is exposed here.'),
          Link::fromTextAndUrl(
            $this->t('Review editorial readiness'),
            Url::fromRoute('agency_operations.editorial'),
          )->toString(),
        ],
      ],
    ];

    $planAuthorityUrl = $this->cockpitPlanAuthorityUrl();
    $build['preprod_data_summary'] = [
      '#type' => 'table',
      '#caption' => $this->t('PREPROD data'),
      '#header' => [
        $this->t('Operation'),
        $this->t('Status'),
        $this->t('Summary'),
        $this->t('Next view'),
      ],
      '#rows' => [
        [
          $this->t('Refresh PREPROD'),
          $this->humanStatusLabel($refresh['human_status_key'] ?? NULL),
          $this->t('PLAN is preparation and analysis only. Opening the GitHub draft does not authorize or execute anything; authority exists only after you review and submit the new issue in GitHub. APPLY is not available from this cockpit.'),
          Link::fromTextAndUrl(
            $this->t('Prepare PREPROD PLAN'),
            $planAuthorityUrl,
          )->toString(),
        ],
        [
          $this->t('Last PREPROD PLAN'),
          $this->lastPlanStatusLabel($lastPlan),
          $this->lastPlanSummary($lastPlan),
          Link::fromTextAndUrl(
            $this->t('Open evidence'),
            Url::fromUri((string) $lastPlan['run_url']),
          )->toString(),
        ],
      ],
    ];

    $build['development_data_summary'] = [
      '#type' => 'table',
      '#caption' => $this->t('Development data'),
      '#header' => [
        $this->t('Operation'),
        $this->t('Status'),
        $this->t('Summary'),
        $this->t('Evidence'),
      ],
      '#rows' => [
        [
          $this->t('Development Seed'),
          $this->humanStatusLabel(
            $developmentSeed['human_status_key'] ?? NULL,
          ),
          $this->t('Development data remains pull-only from a sanitized PREPROD source. This cockpit does not generate or push a seed.'),
          Link::fromTextAndUrl(
            $this->t('Open Development Seed runs'),
            Url::fromUri(
              self::REPOSITORY_URL
              . '/actions/workflows/development-seed-publish.yml',
            ),
          )->toString(),
        ],
      ],
    ];

    $build['recent_evidence'] = [
      '#type' => 'table',
      '#caption' => $this->t('Recent evidence'),
      '#header' => [
        $this->t('Area'),
        $this->t('Recent runs / evidence'),
        $this->t('Canonical authority'),
      ],
      '#rows' => $this->evidenceSummaryRows(),
    ];

    $build['technical_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Technical details'),
      '#open' => FALSE,
    ];
    $build['technical_details']['boundary'] = [
      '#type' => 'item',
      '#title' => $this->t('Control-plane boundary'),
      '#markup' => $this->t(
        'This cockpit remains a read-only control-plane facade. Existing governed workflows and runners remain the execution plane. The PREPROD PLAN action only opens a prefilled GitHub new-issue page; Drupal does not create the issue, post a trigger or dispatch a workflow.',
      ),
    ];
    $build['technical_details']['environments'] = [
      '#type' => 'table',
      '#caption' => $this->t('Environment authority and metadata'),
      '#header' => [
        $this->t('Environment'),
        $this->t('Status'),
        $this->t('Technical status'),
        $this->t('Current release'),
        $this->t('Source / authority'),
        $this->t('Freshness'),
      ],
      '#rows' => $this->environmentTechnicalRows($registry, $runtime),
    ];
    $build['technical_details']['environment_boundary'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'A remote release or health value is not inferred when this Drupal runtime cannot authoritatively observe it. The release shown as current comes only from the environment serving this request.',
      ),
    ];
    $build['technical_details']['languages'] = [
      '#type' => 'table',
      '#caption' => $this->t('Required public content languages'),
      '#header' => [
        $this->t('Code'),
        $this->t('Language'),
        $this->t('Required'),
      ],
      '#rows' => array_map(
        fn (string $name, string $code): array => [
          $code,
          $name,
          $this->t('Required'),
        ],
        array_values($requiredLanguages),
        array_keys($requiredLanguages),
      ),
      '#empty' => $this->t(
        'No configurable site language was discovered. Publication readiness fails closed.',
      ),
    ];

    if ($registry['available']) {
      $registryMarkup = $this->t(
        'Derived read-only from @path (last materialized: @freshness). The cockpit does not persist a second execution registry.',
        [
          '@path' => $registry['path'],
          '@freshness' => $registry['last_materialized'] ?? $this->t('unknown'),
        ],
      );
    }
    else {
      $registryMarkup = $this->t(
        'Registry @path is not readable. Capability presentation fails closed.',
        ['@path' => $registry['path']],
      );
    }
    $build['technical_details']['registry_status'] = [
      '#type' => 'item',
      '#title' => $this->t('Capability registry'),
      '#markup' => $registryMarkup,
    ];

    foreach ($registry['groups'] as $group => $capabilities) {
      $rows = [];
      foreach ($capabilities as $capability) {
        $rows[] = [
          $capability['name'],
          $capability['owner'],
          $this->humanStatusLabel($capability['human_status_key']),
          $capability['status'],
          $capability['surface'],
          $capability['scope'],
        ];
      }
      $build['technical_details']['capability_' . strtolower($group)] = [
        '#type' => 'table',
        '#caption' => $group,
        '#header' => [
          $this->t('Name'),
          $this->t('Owner / authority'),
          $this->t('Status'),
          $this->t('Technical status'),
          $this->t('Execution surface'),
          $this->t('Read/write scope'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t(
          'No capability from the authoritative registry is currently classified in this group.',
        ),
      ];
    }

    $build['technical_details']['preprod_refresh'] = [
      '#type' => 'details',
      '#title' => $this->t('PREPROD refresh authority'),
      '#open' => FALSE,
      'status' => [
        '#type' => 'item',
        '#title' => $this->t('Status'),
        '#markup' => $this->humanStatusLabel(
          $refresh['human_status_key'] ?? NULL,
        ),
      ],
      'technical_status' => [
        '#type' => 'item',
        '#title' => $this->t('Technical status'),
        '#markup' => $refresh['status'] ?? 'UNKNOWN',
      ],
      'plan' => [
        '#type' => 'item',
        '#title' => 'PLAN',
        '#markup' => $this->t(
          'Metadata/readiness analysis only; no PROD data transfer and no PREPROD database mutation.',
        ),
      ],
      'apply' => [
        '#type' => 'item',
        '#title' => 'APPLY',
        '#markup' => $this->t('Not available from this cockpit.'),
      ],
      'authority' => [
        '#type' => 'item',
        '#title' => $this->t('Authorization'),
        '#markup' => $this->t(
          'Human authority remains in the GitHub UI. The cockpit only prepares a fixed PLAN-only issue draft; you must review it and click Submit new issue in GitHub before any PLAN can be authorized.',
        ),
      ],
      'procedure' => [
        '#type' => 'link',
        '#title' => $this->t('View the governed PLAN procedure'),
        '#url' => Url::fromUri(
          self::REPOSITORY_URL
          . '/blob/main/docs/operations/preproduction-refresh-governed-successor.md',
        ),
        '#attributes' => [
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ],
      'evidence' => [
        '#type' => 'link',
        '#title' => $this->t('View authoritative refresh runs'),
        '#url' => Url::fromUri(
          self::REPOSITORY_URL
          . '/actions/workflows/preprod-914-governed-successor.yml',
        ),
        '#attributes' => [
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ],
    ];

    $build['technical_details']['last_plan_receipt'] = [
      '#type' => 'details',
      '#title' => $this->t('Last PREPROD PLAN receipt'),
      '#open' => FALSE,
      'boundary' => [
        '#type' => 'item',
        '#markup' => $this->t(
          'This receipt is evidence only. It is never authority and cannot authorize PLAN or APPLY.',
        ),
      ],
      'receipt' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Field'),
          $this->t('Value'),
        ],
        '#rows' => $this->lastPlanTechnicalRows($lastPlan),
      ],
    ];

    $build['technical_details']['editorial'] = [
      '#type' => 'details',
      '#title' => $this->t('Editorial operations'),
      '#open' => FALSE,
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Candidate authority remains the existing governed candidate record; the cockpit does not copy private payloads.'),
          $this->t('All configurable site languages are required by default.'),
          $this->t('A missing required language blocks publication readiness.'),
          $this->t('PREPROD render and human approval remain external governed receipts.'),
        ],
      ],
    ];

    $build['technical_details']['development_seed'] = [
      '#type' => 'details',
      '#title' => $this->t('Development Seed'),
      '#open' => FALSE,
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          'LOCAL UX = ddev pull agency',
          'SOURCE = sanitized PREPROD',
          'DDEV -> PREPROD = NO',
          'DDEV -> PROD = NO',
          $this->t('Current status is derived from the authoritative capability registry; no seed is generated by this cockpit.'),
        ],
      ],
    ];

    $build['technical_details']['history'] = [
      '#type' => 'table',
      '#caption' => $this->t('History / evidence authority'),
      '#header' => [
        $this->t('Area'),
        $this->t('Authority'),
        $this->t('Recent runs / evidence'),
        $this->t('Canonical authority'),
      ],
      '#rows' => $this->evidenceRows(),
    ];
    $build['technical_details']['history_boundary'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'Evidence remains in GitHub workflows, issues and existing receipts. V3 creates no history table, entity or second receipt store.',
      ),
    ];

    $build['technical_details']['native_audit'] = [
      '#type' => 'table',
      '#caption' => $this->t('Drupal-native adoption audit'),
      '#header' => [
        $this->t('Primitive'),
        $this->t('Current status'),
        $this->t('V3 verdict'),
      ],
      '#rows' => [
        [
          'Content Translation',
          $this->agencyModuleHandler->moduleExists('content_translation')
            ? 'ENABLED'
            : 'DISABLED',
          'REUSE NOW',
        ],
        [
          'Content Moderation',
          $this->agencyModuleHandler->moduleExists('content_moderation')
            ? 'ENABLED'
            : 'DISABLED',
          'REUSE_LATER — do not duplicate candidate authority',
        ],
        [
          'Workflows',
          $this->agencyModuleHandler->moduleExists('workflows')
            ? 'ENABLED'
            : 'DISABLED',
          'REUSE_LATER with Content Moderation if a material UX need is proven',
        ],
        [
          'Workspaces',
          $this->agencyModuleHandler->moduleExists('workspaces')
            ? 'ENABLED'
            : 'DISABLED',
          'REUSE_LATER — same-environment grouping only, never environment transport',
        ],
        [
          'CHANGE_SET',
          'NO CUSTOM ENTITY',
          'REUSE_EXISTING — candidate identity + revisions + references',
        ],
      ],
    ];

    return $build;
  }

  /**
   * Builds the primary environment summary without exposing technical strings.
   */
  private function environmentSummaryRows(array $registry, array $runtime): array {
    $definitions = [
      'PROD' => 'Same-artifact PROD promotion',
      'PREPROD' => 'PROD -> PREPROD sanitized DB refresh',
      'DEVELOPMENT' => 'Development Seed publisher/distribution',
    ];
    $rows = [];

    foreach ($definitions as $environment => $capabilityName) {
      $capability = $this->findCapability($registry, $capabilityName);
      $isCurrentRuntime = $runtime['environment'] === $environment;
      $release = $isCurrentRuntime && $runtime['release_available']
        ? $runtime['release_identity']
        : $this->t('Not exposed by this runtime');
      $freshness = $isCurrentRuntime
        ? $runtime['freshness']
        : ($registry['last_materialized'] ?? $this->t('unknown'));

      $rows[] = [
        $environment,
        $this->humanStatusLabel($capability['human_status_key'] ?? NULL),
        $release,
        $freshness,
      ];
    }

    return $rows;
  }

  /**
   * Builds exact environment authority rows for progressive disclosure.
   */
  private function environmentTechnicalRows(array $registry, array $runtime): array {
    $definitions = [
      'PROD' => 'Same-artifact PROD promotion',
      'PREPROD' => 'PROD -> PREPROD sanitized DB refresh',
      'DEVELOPMENT' => 'Development Seed publisher/distribution',
    ];
    $rows = [];

    foreach ($definitions as $environment => $capabilityName) {
      $capability = $this->findCapability($registry, $capabilityName);
      $isCurrentRuntime = $runtime['environment'] === $environment;
      $release = $isCurrentRuntime && $runtime['release_available']
        ? $runtime['release_identity']
        : $this->t('Not exposed by this runtime');
      $source = $registry['path'];
      $authority = $capability['owner'] ?? $this->t('Unknown authority');
      $freshness = $registry['last_materialized'] ?? $this->t('unknown');

      if ($isCurrentRuntime) {
        $source .= '; ' . $runtime['source'];
        $authority .= '; ' . $runtime['authority'];
        $freshness .= '; ' . $runtime['freshness'];
      }

      $rows[] = [
        $environment,
        $this->humanStatusLabel($capability['human_status_key'] ?? NULL),
        $capability['status'] ?? 'UNKNOWN',
        $release,
        $source . ' / ' . $authority,
        $freshness,
      ];
    }

    return $rows;
  }

  /**
   * Translates one semantic human status in the current admin UI language.
   */
  private function humanStatusLabel(?string $statusKey): string {
    return (string) match ($statusKey) {
      CapabilityRegistryReader::STATUS_OPERATIONAL => $this->t('Operational'),
      CapabilityRegistryReader::STATUS_READY => $this->t('Ready'),
      CapabilityRegistryReader::STATUS_PREPARING => $this->t('Preparing'),
      CapabilityRegistryReader::STATUS_BLOCKED => $this->t('Blocked'),
      CapabilityRegistryReader::STATUS_HUMAN_ACTION_REQUIRED => $this->t(
        'Human action required',
      ),
      default => $this->t('Unavailable'),
    };
  }

  /**
   * Returns the fail-closed human status of the latest PLAN evidence.
   */
  private function lastPlanStatusLabel(array $lastPlan): string {
    return !empty($lastPlan['available'])
      ? (string) $this->t('Passed')
      : (string) $this->t('Evidence unavailable');
  }

  /**
   * Returns the compact human-first latest PLAN summary.
   */
  private function lastPlanSummary(array $lastPlan): string {
    if (empty($lastPlan['available'])) {
      return (string) $this->t(
        'PLAN result is not inferred. APPLY remains not authorized.',
      );
    }

    return (string) $this->t(
      'Authority: #@authority; Completed: @completed; Evaluated main: @main; Observed PROD release: @prod; Mutation: None; APPLY: Not authorized.',
      [
        '@authority' => (string) $lastPlan['authority_issue'],
        '@completed' => $this->formatCompletedAt(
          (string) $lastPlan['completed_at'],
        ),
        '@main' => $this->shortSha((string) $lastPlan['main_sha']),
        '@prod' => $this->shortSha(
          (string) $lastPlan['observed_prod_release_sha'],
        ),
      ],
    );
  }

  /**
   * Returns progressive-disclosure receipt fields without raw JSON.
   */
  private function lastPlanTechnicalRows(array $lastPlan): array {
    if (empty($lastPlan['available'])) {
      return [
        [$this->t('Status'), $this->t('Evidence unavailable')],
        ['PLAN_RESULT', 'NOT_INFERRED'],
        ['APPLY', $this->t('Not authorized')],
        [
          $this->t('Source'),
          'api.github.com / E-merging-digital/agency-website-drupal / public read-only',
        ],
      ];
    }

    return [
      [$this->t('Status'), $this->t('Passed')],
      [$this->t('Receipt source'), (string) $lastPlan['receipt_source']],
      [
        $this->t('Authority issue'),
        Link::fromTextAndUrl(
          '#' . (string) $lastPlan['authority_issue'],
          Url::fromUri((string) $lastPlan['authority_url']),
        )->toString(),
      ],
      [$this->t('Request'), (string) $lastPlan['request_id']],
      [$this->t('Evaluated main'), (string) $lastPlan['main_sha']],
      [
        $this->t('Observed PROD release'),
        (string) $lastPlan['observed_prod_release_sha'],
      ],
      [$this->t('Completed'), (string) $lastPlan['completed_at']],
      ['PLAN_RESULT', (string) $lastPlan['plan_result']],
      ['JIT_MAIN_REVALIDATION', (string) $lastPlan['jit_main_revalidation']],
      [$this->t('Mutation'), $this->t('None')],
      ['APPLY', $this->t('Not authorized')],
      [
        $this->t('Dispatch run'),
        Link::fromTextAndUrl(
          (string) $lastPlan['dispatch_run'],
          Url::fromUri((string) $lastPlan['run_url']),
        )->toString(),
      ],
    ];
  }

  /**
   * Formats the trusted canonical UTC receipt timestamp for the primary view.
   */
  private function formatCompletedAt(string $timestamp): string {
    return str_replace('T', ' ', substr($timestamp, 0, 16)) . ' UTC';
  }

  /**
   * Shortens a validated full SHA for the primary view only.
   */
  private function shortSha(string $sha): string {
    return substr($sha, 0, 7) . '…';
  }

  /**
   * Finds one exact named capability in the already parsed registry.
   */
  private function findCapability(array $registry, string $name): ?array {
    foreach ($registry['groups'] as $capabilities) {
      foreach ($capabilities as $capability) {
        if ($capability['name'] === $name) {
          return $capability;
        }
      }
    }

    return NULL;
  }

  /**
   * Builds the fixed GitHub new-issue draft for human PLAN authority.
   */
  private function cockpitPlanAuthorityUrl(): Url {
    $body = implode("\n", [
      'Parent: #816',
      '',
      '## Cockpit PREPROD PLAN authority',
      '',
      'This issue authorizes one PLAN against the live main resolved when the issue is opened.',
      '',
      'PLAN = analysis / preparation only',
      'NO DATA MUTATION',
      'APPLY = NOT AUTHORIZED',
      '',
      self::COCKPIT_PLAN_AUTHORITY_MARKER,
      '',
    ]);

    return Url::fromUri(self::REPOSITORY_URL . '/issues/new', [
      'query' => [
        'title' => self::COCKPIT_PLAN_ISSUE_TITLE,
        'body' => $body,
        'labels' => 'Task,P1,status:in-progress',
      ],
      'attributes' => [
        'target' => '_blank',
        'rel' => 'noopener noreferrer',
      ],
    ]);
  }

  /**
   * Returns concise links to existing authoritative evidence surfaces only.
   */
  private function evidenceSummaryRows(): array {
    $rows = [];
    foreach ($this->evidenceDefinitions() as $definition) {
      $rows[] = [
        $definition['label'],
        Link::fromTextAndUrl(
          $this->t('Open recent evidence'),
          Url::fromUri($definition['runs']),
        )->toString(),
        Link::fromTextAndUrl(
          $this->t('Open canonical authority'),
          Url::fromUri($definition['authority_url']),
        )->toString(),
      ];
    }

    return $rows;
  }

  /**
   * Returns detailed links to existing authoritative evidence surfaces only.
   */
  private function evidenceRows(): array {
    $rows = [];
    foreach ($this->evidenceDefinitions() as $definition) {
      $rows[] = [
        $definition['label'],
        $definition['authority'],
        Link::fromTextAndUrl(
          $this->t('Open runs'),
          Url::fromUri($definition['runs']),
        )->toString(),
        Link::fromTextAndUrl(
          $this->t('Open authority'),
          Url::fromUri($definition['authority_url']),
        )->toString(),
      ];
    }

    return $rows;
  }

  /**
   * Defines existing evidence navigation without copying remote state.
   *
   * @return array<int, array{label: string, authority: string, runs: string, authority_url: string}>
   *   Existing GitHub surfaces.
   */
  private function evidenceDefinitions(): array {
    return [
      [
        'label' => (string) $this->t('Code deployment'),
        'authority' => 'release / promotion workflows',
        'runs' => self::REPOSITORY_URL . '/actions/workflows/promote-production.yml',
        'authority_url' => self::REPOSITORY_URL . '/issues/870',
      ],
      [
        'label' => (string) $this->t('PREPROD refresh'),
        'authority' => '#914 / completed #816',
        'runs' => self::REPOSITORY_URL . '/actions/workflows/preprod-914-governed-successor.yml',
        'authority_url' => self::REPOSITORY_URL . '/issues/914',
      ],
      [
        'label' => (string) $this->t('Editorial candidate / promotion'),
        'authority' => '#959 / #872',
        'runs' => self::REPOSITORY_URL . '/actions/workflows/trusted-editorial-preprod-candidate.yml',
        'authority_url' => self::REPOSITORY_URL . '/issues/872',
      ],
      [
        'label' => (string) $this->t('Development Seed'),
        'authority' => '#873 / #956',
        'runs' => self::REPOSITORY_URL . '/actions/workflows/development-seed-publish.yml',
        'authority_url' => self::REPOSITORY_URL . '/issues/873',
      ],
    ];
  }

}

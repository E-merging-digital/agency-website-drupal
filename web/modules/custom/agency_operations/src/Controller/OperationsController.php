<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Controller;

use Drupal\agency_operations\Service\CapabilityRegistryReader;
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

  public function __construct(
    private readonly CapabilityRegistryReader $capabilityRegistry,
    private readonly EditorialLanguageReadinessInterface $languageReadiness,
    private readonly ModuleHandlerInterface $agencyModuleHandler,
    private readonly RuntimeMetadataReader $runtimeMetadata,
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
    );
  }

  /**
   * Builds the V2 control-plane facade without delegating execution authority.
   */
  public function overview(): array {
    $registry = $this->capabilityRegistry->read();
    $runtime = $this->runtimeMetadata->read();
    $requiredLanguages = $this->languageReadiness->getRequiredLanguages();

    $build = [];
    $build['boundary'] = [
      '#type' => 'item',
      '#title' => $this->t('Control-plane boundary'),
      '#markup' => $this->t(
        'V2 remains a read-only control-plane facade. Existing governed workflows and runners remain the execution plane. PREPROD PLAN requires separate manual GitHub authority; this cockpit does not request PLAN, APPLY or any PROD/PREPROD mutation.',
      ),
    ];

    $build['environments'] = [
      '#type' => 'table',
      '#caption' => $this->t('Environments — authoritative metadata only'),
      '#header' => [
        $this->t('Environment'),
        $this->t('Status'),
        $this->t('Technical status'),
        $this->t('Current release'),
        $this->t('Source / authority'),
        $this->t('Freshness'),
      ],
      '#rows' => $this->environmentRows($registry, $runtime),
    ];
    $build['environment_boundary'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'A remote release or health value is not inferred when this Drupal runtime cannot authoritatively observe it. The release shown as current comes only from the environment serving this request.',
      ),
    ];

    $build['languages'] = [
      '#type' => 'table',
      '#caption' => $this->t('Required public content languages'),
      '#header' => [$this->t('Code'), $this->t('Language'), $this->t('Required')],
      '#rows' => array_map(
        static fn (string $name, string $code): array => [$code, $name, 'YES'],
        array_values($requiredLanguages),
        array_keys($requiredLanguages),
      ),
      '#empty' => $this->t(
        'No configurable site language was discovered. Publication readiness fails closed.',
      ),
    ];
    $build['editorial_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Open editorial language readiness'),
      '#url' => Url::fromRoute('agency_operations.editorial'),
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
    $build['registry_status'] = [
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
          $capability['human_status'],
          $capability['status'],
          $capability['surface'],
          $capability['scope'],
        ];
      }
      $build['capability_' . strtolower($group)] = [
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

    $refresh = $this->findCapability($registry, 'PROD -> PREPROD sanitized DB refresh');
    $build['preprod_refresh'] = [
      '#type' => 'details',
      '#title' => $this->t('Refresh PREPROD'),
      '#open' => TRUE,
      'status' => [
        '#type' => 'item',
        '#title' => $this->t('Status'),
        '#markup' => $refresh['human_status'] ?? $this->t('Indisponible'),
      ],
      'technical_status' => [
        '#type' => 'item',
        '#title' => $this->t('Technical status'),
        '#markup' => $refresh['status'] ?? 'UNKNOWN',
      ],
      'plan' => [
        '#type' => 'item',
        '#title' => 'PLAN',
        '#markup' => $this->t('Metadata/readiness analysis only; no PROD data transfer and no PREPROD database mutation.'),
      ],
      'apply' => [
        '#type' => 'item',
        '#title' => 'APPLY',
        '#markup' => $this->t('Not available from this cockpit.'),
      ],
      'authority' => [
        '#type' => 'item',
        '#title' => $this->t('Authorization'),
        '#markup' => $this->t('Manual GitHub authorization is required through the existing governed #914 authority contract. This page does not create an authority issue, post a trigger or dispatch a workflow.'),
      ],
      'procedure' => [
        '#type' => 'link',
        '#title' => $this->t('View the governed PLAN procedure'),
        '#url' => Url::fromUri(self::REPOSITORY_URL . '/blob/main/docs/operations/preproduction-refresh-governed-successor.md'),
        '#attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer'],
      ],
      'evidence' => [
        '#type' => 'link',
        '#title' => $this->t('View authoritative refresh runs'),
        '#url' => Url::fromUri(self::REPOSITORY_URL . '/actions/workflows/preprod-914-governed-successor.yml'),
        '#attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer'],
      ],
    ];

    $build['editorial'] = [
      '#type' => 'details',
      '#title' => $this->t('Editorial operations'),
      '#open' => TRUE,
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

    $build['development_seed'] = [
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

    $build['history'] = [
      '#type' => 'table',
      '#caption' => $this->t('History / evidence'),
      '#header' => [
        $this->t('Area'),
        $this->t('Authority'),
        $this->t('Recent runs / evidence'),
        $this->t('Canonical authority'),
      ],
      '#rows' => $this->evidenceRows(),
    ];
    $build['history_boundary'] = [
      '#type' => 'item',
      '#markup' => $this->t('Evidence remains in GitHub workflows, issues and existing receipts. V2 creates no history table, entity or second receipt store.'),
    ];

    $build['native_audit'] = [
      '#type' => 'table',
      '#caption' => $this->t('Drupal-native adoption audit'),
      '#header' => [
        $this->t('Primitive'),
        $this->t('Current status'),
        $this->t('V2 verdict'),
      ],
      '#rows' => [
        [
          'Content Translation',
          $this->agencyModuleHandler->moduleExists('content_translation') ? 'ENABLED' : 'DISABLED',
          'REUSE NOW',
        ],
        [
          'Content Moderation',
          $this->agencyModuleHandler->moduleExists('content_moderation') ? 'ENABLED' : 'DISABLED',
          'REUSE_LATER — do not duplicate candidate authority',
        ],
        [
          'Workflows',
          $this->agencyModuleHandler->moduleExists('workflows') ? 'ENABLED' : 'DISABLED',
          'REUSE_LATER with Content Moderation if a material UX need is proven',
        ],
        [
          'Workspaces',
          $this->agencyModuleHandler->moduleExists('workspaces') ? 'ENABLED' : 'DISABLED',
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
   * Builds environment rows from repository truth plus the local runtime only.
   */
  private function environmentRows(array $registry, array $runtime): array {
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
        $capability['human_status'] ?? $this->t('Indisponible'),
        $capability['status'] ?? 'UNKNOWN',
        $release,
        $source . ' / ' . $authority,
        $freshness,
      ];
    }

    return $rows;
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
   * Returns links to existing authoritative evidence surfaces only.
   */
  private function evidenceRows(): array {
    $rows = [
      [
        'Code deployment',
        'release / promotion workflows',
        self::REPOSITORY_URL . '/actions/workflows/promote-production.yml',
        self::REPOSITORY_URL . '/issues/870',
      ],
      [
        'PREPROD refresh',
        '#914 / completed #816',
        self::REPOSITORY_URL . '/actions/workflows/preprod-914-governed-successor.yml',
        self::REPOSITORY_URL . '/issues/914',
      ],
      [
        'Editorial candidate / promotion',
        '#959 / #872',
        self::REPOSITORY_URL . '/actions/workflows/trusted-editorial-preprod-candidate.yml',
        self::REPOSITORY_URL . '/issues/872',
      ],
      [
        'Development Seed',
        '#873 / #956',
        self::REPOSITORY_URL . '/actions/workflows/development-seed-publish.yml',
        self::REPOSITORY_URL . '/issues/873',
      ],
    ];

    return array_map(
      static fn (array $row): array => [
        $row[0],
        $row[1],
        Link::fromTextAndUrl('Open runs', Url::fromUri($row[2]))->toString(),
        Link::fromTextAndUrl('Open authority', Url::fromUri($row[3]))->toString(),
      ],
      $rows,
    );
  }

}

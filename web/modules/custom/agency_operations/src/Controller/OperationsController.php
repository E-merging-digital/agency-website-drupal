<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Controller;

use Drupal\agency_operations\Service\CapabilityRegistryReader;
use Drupal\agency_operations\Service\EditorialLanguageReadinessInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only Agency operations overview.
 */
final class OperationsController extends ControllerBase {

  public function __construct(
    private readonly CapabilityRegistryReader $capabilityRegistry,
    private readonly EditorialLanguageReadinessInterface $languageReadiness,
    private readonly ModuleHandlerInterface $agencyModuleHandler,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('agency_operations.capability_registry'),
      $container->get('agency_operations.language_readiness'),
      $container->get('module_handler'),
    );
  }

  /**
   * Builds the V1 control-plane facade without exposing execution actions.
   */
  public function overview(): array {
    $registry = $this->capabilityRegistry->read();
    $requiredLanguages = $this->languageReadiness->getRequiredLanguages();

    $build = [];
    $build['boundary'] = [
      '#type' => 'item',
      '#title' => $this->t('Control-plane boundary'),
      '#markup' => $this->t(
        'V1 is read-only and preparation-first. Existing workflows and runners remain the execution data plane. No PROD or PREPROD operation can be triggered from this page.',
      ),
    ];

    $build['environments'] = [
      '#type' => 'table',
      '#caption' => $this->t('Environments'),
      '#header' => [
        $this->t('Environment'),
        $this->t('Role'),
        $this->t('V1 state'),
      ],
      '#rows' => [
        [
          'LOCAL / DDEV',
          $this->t('Development and isolated testing'),
          $this->t('Read-only status; pull-only Development Seed contract'),
        ],
        [
          'PREPROD',
          $this->t('Production-like review and independent sanitized data target'),
          $this->t('Read-only / preparation; no mutation'),
        ],
        [
          'PROD',
          $this->t('Live service and editorial data authority'),
          $this->t('Read-only semantics only; no access or mutation'),
        ],
      ],
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
        'Derived read-only from @path. The cockpit does not persist a second execution registry.',
        ['@path' => $registry['path']],
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
          $capability['status'],
          $capability['surface'],
          $capability['scope'],
          $this->t('External governed route only; no cockpit execution'),
        ];
      }
      $build['capability_' . strtolower($group)] = [
        '#type' => 'table',
        '#caption' => $group,
        '#header' => [
          $this->t('Name'),
          $this->t('Owner / authority'),
          $this->t('Status'),
          $this->t('Execution surface'),
          $this->t('Read/write scope'),
          $this->t('Human / V1 boundary'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t(
          'No capability from the authoritative registry is currently classified in this group.',
        ),
      ];
    }

    $build['preprod_refresh'] = [
      '#type' => 'details',
      '#title' => $this->t('PREPROD refresh'),
      '#open' => TRUE,
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          'SOURCE/TARGET = PROD read-only → sanitized/hardened PREPROD',
          'PLAN = existing governed capability',
          'APPLY = existing separately governed capability',
          'ROLLBACK = existing PREPROD capability',
          'REAL_REFRESH_TRIGGER = NO',
        ],
      ],
    ];

    $build['editorial'] = [
      '#type' => 'details',
      '#title' => $this->t('Editorial operations'),
      '#open' => TRUE,
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          $this->t(
            'Candidate authority remains the existing governed candidate record; the cockpit does not copy private payloads.',
          ),
          $this->t('All configurable site languages are required by default.'),
          $this->t('A missing required language blocks publication readiness.'),
          $this->t(
            'PREPROD render and human approval remain external governed receipts.',
          ),
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
          'DDEV → PREPROD = NO',
          'DDEV → PROD = NO',
          'V1 = READ_ONLY / STATUS',
        ],
      ],
    ];

    $build['code_release'] = [
      '#type' => 'details',
      '#title' => $this->t('Code & releases'),
      '#open' => FALSE,
      'content' => [
        '#theme' => 'item_list',
        '#items' => [
          'Git/main → build → exact artifact → PREPROD → validation → SAME ARTIFACT → PROD',
          'ARBITRARY SHA = NO',
          'ARBITRARY WORKFLOW = NO',
          'V1 = READ_ONLY / STATUS',
        ],
      ],
    ];

    $build['history'] = [
      '#type' => 'item',
      '#title' => $this->t('History / evidence'),
      '#markup' => $this->t(
        'READ_ONLY_AGGREGATION: existing workflow evidence and receipts remain authoritative. V1 creates no receipt table, entity, credential store or second source of truth.',
      ),
    ];

    $build['native_audit'] = [
      '#type' => 'table',
      '#caption' => $this->t('Drupal-native adoption audit'),
      '#header' => [
        $this->t('Primitive'),
        $this->t('Current status'),
        $this->t('V1 verdict'),
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

}

<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Controller;

use Drupal\agency_operations\Service\EditorialLanguageReadinessInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only view of the dynamic editorial language readiness contract.
 */
final class EditorialOperationsController extends ControllerBase {

  public function __construct(
    private readonly EditorialLanguageReadinessInterface $languageReadiness,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('agency_operations.language_readiness'));
  }

  /**
   * Shows the language matrix without importing private candidate payloads.
   */
  public function overview(): array {
    // V1 deliberately has no privileged candidate ingestion channel.
    // Missing candidate state intentionally fails closed rather than being guessed.
    $readiness = $this->languageReadiness->evaluate([]);
    $rows = [];
    foreach ($readiness['languages'] as $state) {
      $rows[] = [
        $state['language_code'],
        $state['language_name'],
        $state['translation_exists'] ? 'YES' : 'NO',
        $state['translation_state'],
        $state['preprod_render_state'],
        $state['validation_state'],
        $state['approval_state'],
        $state['ready'] ? 'READY' : 'BLOCKED',
      ];
    }

    return [
      'boundary' => [
        '#type' => 'item',
        '#title' => $this->t('Candidate boundary'),
        '#markup' => $this->t(
          'The durable candidate remains on its existing governed control surface. V1 does not copy private candidate payloads or add GitHub credentials to Drupal. With no candidate metadata loaded, readiness intentionally fails closed.',
        ),
      ],
      'matrix' => [
        '#type' => 'table',
        '#caption' => $this->t(
          'Language matrix — overall: @state',
          ['@state' => $readiness['overall']],
        ),
        '#header' => [
          $this->t('Code'),
          $this->t('Language'),
          $this->t('Translation exists'),
          $this->t('Translation state'),
          $this->t('PREPROD render'),
          $this->t('Validation'),
          $this->t('Approval'),
          $this->t('Readiness'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t(
          'No configurable site language was discovered. Publication readiness remains blocked.',
        ),
      ],
      'contract' => [
        '#type' => 'item',
        '#title' => $this->t('Publication contract'),
        '#markup' => $this->t(
          'PUBLIC_CONTENT_READY only when every currently configurable site language is READY. Adding another configured language automatically adds another required matrix row; no code change is required.',
        ),
      ],
    ];
  }

}

<?php

declare(strict_types=1);

namespace Drupal\agency_health\Controller;

use Drupal\agency_health\HealthProbeInterface;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Exposes minimal provider-neutral liveness and readiness responses.
 */
final class HealthController extends ControllerBase {

  /**
   * Constructs the health controller.
   */
  public function __construct(
    private readonly HealthProbeInterface $healthProbe,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('agency_health.probe'),
    );
  }

  /**
   * Returns the minimal liveness response.
   */
  public function liveness(): JsonResponse {
    return $this->response($this->healthProbe->isLive());
  }

  /**
   * Returns the minimal readiness response.
   */
  public function readiness(): JsonResponse {
    return $this->response($this->healthProbe->isReady());
  }

  /**
   * Builds the binary public response required by the shared contract.
   */
  private function response(bool $healthy): JsonResponse {
    return new JsonResponse(
      ['status' => $healthy ? 'ok' : 'unavailable'],
      $healthy ? 200 : 503,
      ['Cache-Control' => 'no-store'],
    );
  }

}

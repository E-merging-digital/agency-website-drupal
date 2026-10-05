<?php

declare(strict_types=1);

namespace Drupal\agency_health;

/**
 * Provides provider-neutral application health checks.
 */
interface HealthProbeInterface {

  /**
   * Returns whether the minimal Drupal request bootstrap is live.
   */
  public function isLive(): bool;

  /**
   * Returns whether required application dependencies are ready.
   */
  public function isReady(): bool;

}

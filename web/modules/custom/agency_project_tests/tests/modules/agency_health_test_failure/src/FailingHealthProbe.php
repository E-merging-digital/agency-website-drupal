<?php

declare(strict_types=1);

namespace Drupal\agency_health_test_failure;

use Drupal\agency_health\HealthProbeInterface;

/**
 * Simulates an unavailable required dependency for functional HTTP tests.
 */
final class FailingHealthProbe implements HealthProbeInterface {

  /**
   * {@inheritdoc}
   */
  public function isLive(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isReady(): bool {
    return FALSE;
  }

}

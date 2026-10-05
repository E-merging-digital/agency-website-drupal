<?php

declare(strict_types=1);

namespace Drupal\agency_health;

use Drupal\Core\Database\Connection;

/**
 * Implements the minimal Platform Ops health contract for Drupal.
 */
final class HealthProbe implements HealthProbeInterface {

  /**
   * Constructs the health probe.
   */
  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isLive(): bool {
    // Reaching this service proves that Drupal completed the request bootstrap.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isReady(): bool {
    try {
      return (string) $this->database->query('SELECT 1')->fetchField() === '1';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

}

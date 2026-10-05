<?php

declare(strict_types=1);

namespace Drupal\agency_operations\Service;

/**
 * Reads the latest trusted Cockpit PREPROD PLAN receipt.
 */
interface CockpitPlanReceiptReaderInterface {

  /**
   * Returns trusted receipt evidence or a fail-closed unavailable result.
   *
   * @return array<string, mixed>
   *   Receipt presentation data. A successful result has available=TRUE.
   */
  public function read(): array;

}

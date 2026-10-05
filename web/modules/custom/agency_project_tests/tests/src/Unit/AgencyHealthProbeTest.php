<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\agency_health\HealthProbe;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the provider-neutral Agency health probe.
 *
 * @group agency_project_tests
 */
final class AgencyHealthProbeTest extends TestCase {

  /**
   * Reaching the probe proves the minimal Drupal bootstrap completed.
   */
  public function testLivenessSucceedsAfterBootstrap(): void {
    $database = $this->createMock(Connection::class);
    $probe = new HealthProbe($database);

    self::assertTrue($probe->isLive());
  }

  /**
   * Readiness succeeds only when the required database responds.
   */
  public function testReadinessSucceedsWhenDatabaseResponds(): void {
    $statement = $this->createMock(StatementInterface::class);
    $statement->expects(self::once())
      ->method('fetchField')
      ->willReturn(1);

    $database = $this->createMock(Connection::class);
    $database->expects(self::once())
      ->method('query')
      ->with('SELECT 1')
      ->willReturn($statement);

    $probe = new HealthProbe($database);

    self::assertTrue($probe->isReady());
  }

  /**
   * Readiness fails closed without leaking a database exception.
   */
  public function testReadinessFailsClosedWhenDatabaseThrows(): void {
    $database = $this->createMock(Connection::class);
    $database->expects(self::once())
      ->method('query')
      ->with('SELECT 1')
      ->willThrowException(new \RuntimeException('sensitive-internal-db-detail'));

    $probe = new HealthProbe($database);

    self::assertFalse($probe->isReady());
  }

}

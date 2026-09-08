<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Unit;

use Drupal\agency_operations\Service\RuntimeMetadataReader;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Proves runtime metadata stays local, read-only and fail-closed.
 *
 * @group agency_operations
 */
final class RuntimeMetadataReaderTest extends UnitTestCase {

  /**
   * Proves PREPROD host detection without fabricating a release identity.
   */
  public function testPreprodRuntimeWithoutReleasePathFailsClosed(): void {
    $stack = new RequestStack();
    $stack->push(Request::create('https://preprod.emergingdigital.be/admin/agency/operations'));

    $result = (new RuntimeMetadataReader(__DIR__, $stack))->read();

    self::assertSame('PREPROD', $result['environment']);
    self::assertFalse($result['release_available']);
    self::assertNull($result['release_identity']);
    self::assertSame('current request', $result['freshness']);
  }

  /**
   * Proves the production host is identified independently of PREPROD.
   */
  public function testProdHostIsDetected(): void {
    $stack = new RequestStack();
    $stack->push(Request::create('https://emergingdigital.be/admin/agency/operations'));

    $result = (new RuntimeMetadataReader(__DIR__, $stack))->read();

    self::assertSame('PROD', $result['environment']);
  }

}

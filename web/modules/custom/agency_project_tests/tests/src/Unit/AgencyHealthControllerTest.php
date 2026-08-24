<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Unit;

use Drupal\agency_health\Controller\HealthController;
use Drupal\agency_health\HealthProbeInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Covers the public health response contract.
 *
 * @group agency_project_tests
 */
final class AgencyHealthControllerTest extends TestCase {

  /**
   * Healthy probes expose only the minimal success payload.
   */
  public function testHealthyResponsesAreMinimal(): void {
    $probe = $this->createMock(HealthProbeInterface::class);
    $probe->method('isLive')->willReturn(TRUE);
    $probe->method('isReady')->willReturn(TRUE);
    $controller = new HealthController($probe);

    $this->assertHealthyResponse($controller->liveness());
    $this->assertHealthyResponse($controller->readiness());
  }

  /**
   * Failed required checks expose only the binary unavailable payload.
   */
  public function testFailedResponsesAreMinimalAndFailClosed(): void {
    $probe = $this->createMock(HealthProbeInterface::class);
    $probe->method('isLive')->willReturn(FALSE);
    $probe->method('isReady')->willReturn(FALSE);
    $controller = new HealthController($probe);

    $this->assertUnavailableResponse($controller->liveness());
    $this->assertUnavailableResponse($controller->readiness());
  }

  /**
   * Asserts the exact public healthy response.
   */
  private function assertHealthyResponse(JsonResponse $response): void {
    self::assertSame(200, $response->getStatusCode());
    self::assertSame('{"status":"ok"}', $response->getContent());
    self::assertSame('application/json', $response->headers->get('Content-Type'));
    self::assertStringContainsString(
      'no-store',
      (string) $response->headers->get('Cache-Control'),
    );
  }

  /**
   * Asserts the exact public unavailable response and no detail leakage.
   */
  private function assertUnavailableResponse(JsonResponse $response): void {
    self::assertSame(503, $response->getStatusCode());
    self::assertSame('{"status":"unavailable"}', $response->getContent());
    self::assertSame('application/json', $response->headers->get('Content-Type'));
    self::assertStringContainsString(
      'no-store',
      (string) $response->headers->get('Cache-Control'),
    );

    $body = (string) $response->getContent();
    foreach (['version', 'database', 'host', 'trace', 'exception', 'token', 'password'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, strtolower($body));
    }
  }

}

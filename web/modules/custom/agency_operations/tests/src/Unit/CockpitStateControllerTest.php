<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_operations\Unit;

use Drupal\agency_operations\Controller\CockpitStateController;
use Drupal\agency_operations\Service\CapabilityRegistryReader;
use Drupal\agency_operations\Service\EnvironmentDataStateProvider;
use Drupal\agency_operations\Service\RuntimeMetadataReader;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Proves the cockpit state transport is authenticated and PREPROD-only.
 *
 * @group agency_operations
 */
final class CockpitStateControllerTest extends UnitTestCase {

  private const TOKEN = 'test-cockpit-token-0123456789-abcdef-0123456789';

  /**
   * Temporary PREPROD-like project root.
   */
  private string $fixtureRoot;

  /**
   * Temporary deployed Drupal app root.
   */
  private string $appRoot;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->fixtureRoot = sys_get_temp_dir()
      . '/agency-cockpit-state-' . bin2hex(random_bytes(6));
    $releaseRoot = $this->fixtureRoot
      . '/releases/20260914150000-' . str_repeat('a', 40);
    $this->appRoot = $releaseRoot . '/web';

    mkdir($this->appRoot, 0777, TRUE);
    mkdir($this->fixtureRoot . '/shared', 0777, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->removeTree($this->fixtureRoot);
    parent::tearDown();
  }

  /**
   * Proves an authenticated PREPROD request returns the normalized provider.
   */
  public function testAuthorizedPreprodRequestReturnsProjection(): void {
    $controller = new CockpitStateController(
      $this->providerForHost('preprod.emergingdigital.be'),
      self::TOKEN,
    );
    $request = Request::create('/api/agency-operations/v1/environment-data-state');
    $request->headers->set('Authorization', 'Bearer ' . self::TOKEN);

    $response = $controller->state($request);
    $payload = json_decode((string) $response->getContent(), TRUE, 32, JSON_THROW_ON_ERROR);

    self::assertSame(200, $response->getStatusCode());
    self::assertSame('agency-website', $payload['project_id']);
    self::assertSame('PREPROD', $payload['runtime']['environment']);
    self::assertSame('agency-drupal-environment-data', $payload['adapter']['id']);
    self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  /**
   * Proves wrong or missing credentials never expose the projection.
   */
  public function testUnauthorizedRequestIsDenied(): void {
    $controller = new CockpitStateController(
      $this->providerForHost('preprod.emergingdigital.be'),
      self::TOKEN,
    );
    $request = Request::create('/api/agency-operations/v1/environment-data-state');
    $request->headers->set('Authorization', 'Bearer wrong-token');

    $response = $controller->state($request);
    $payload = json_decode((string) $response->getContent(), TRUE, 32, JSON_THROW_ON_ERROR);

    self::assertSame(401, $response->getStatusCode());
    self::assertSame(['error' => 'unauthorized'], $payload);
    self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
  }

  /**
   * Proves weak or missing transport configuration fails closed.
   */
  public function testMissingTransportConfigurationFailsClosed(): void {
    $controller = new CockpitStateController(
      $this->providerForHost('preprod.emergingdigital.be'),
      '',
    );

    $response = $controller->state(
      Request::create('/api/agency-operations/v1/environment-data-state'),
    );

    self::assertSame(503, $response->getStatusCode());
    self::assertSame(
      ['error' => 'transport_unavailable'],
      json_decode((string) $response->getContent(), TRUE, 32, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Proves the machine surface cannot expose PROD through this transport.
   */
  public function testAuthenticatedNonPreprodRequestIsNotAvailable(): void {
    $controller = new CockpitStateController(
      $this->providerForHost('emergingdigital.be'),
      self::TOKEN,
    );
    $request = Request::create('/api/agency-operations/v1/environment-data-state');
    $request->headers->set('Authorization', 'Bearer ' . self::TOKEN);

    $response = $controller->state($request);

    self::assertSame(404, $response->getStatusCode());
    self::assertSame(
      ['error' => 'not_found'],
      json_decode((string) $response->getContent(), TRUE, 32, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Creates the real state provider against an isolated fixture filesystem.
   */
  private function providerForHost(string $host): EnvironmentDataStateProvider {
    $stack = new RequestStack();
    $stack->push(Request::create('https://' . $host . '/'));

    return new EnvironmentDataStateProvider(
      $this->appRoot,
      new RuntimeMetadataReader($this->appRoot, $stack),
      new CapabilityRegistryReader($this->appRoot),
    );
  }

  /**
   * Removes the isolated fixture tree without following symlinks.
   */
  private function removeTree(string $path): void {
    if (is_link($path) || is_file($path)) {
      @unlink($path);
      return;
    }
    if (!is_dir($path)) {
      return;
    }

    foreach (scandir($path) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
  }

}

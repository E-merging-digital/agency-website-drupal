<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers fail-closed readiness over a real Drupal HTTP request.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class AgencyHealthReadinessFailureTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_health',
    'agency_health_test_failure',
    'language',
    'redirect',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    ConfigurableLanguage::createFromLangcode('fr')->save();

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['fr' => 'fr'])
      ->set('url.domains', ['fr' => ''])
      ->save();

    $this->config('language.types')
      ->set('all', [
        'language_interface',
        'language_content',
        'language_url',
      ])
      ->set('configurable', [
        'language_interface',
        'language_content',
      ])
      ->set('negotiation.language_interface.enabled', [
        'language-url' => -8,
        'language-selected' => -6,
      ])
      ->set('negotiation.language_content.enabled', [
        'language-url' => -8,
        'language-selected' => 12,
      ])
      ->set('negotiation.language_url.enabled', [
        'language-url' => -8,
      ])
      ->save();

    $this->config('redirect.settings')
      ->set('route_normalizer_enabled', TRUE)
      ->save();

    drupal_flush_all_caches();
  }

  /**
   * A failed required dependency returns only the unavailable contract.
   */
  public function testReadinessRequiredDependencyFailureReturns503(): void {
    $driver = $this->getSession()->getDriver();
    self::assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->followRedirects(FALSE);

    $client->request('GET', $this->baseUrl . '/health/ready');
    $response = $client->getResponse();

    self::assertSame(503, $response->getStatusCode());
    self::assertNull($response->getHeader('Location'));
    self::assertStringContainsString(
      'application/json',
      (string) $response->getHeader('Content-Type'),
    );
    self::assertStringContainsString(
      'no-store',
      (string) $response->getHeader('Cache-Control'),
    );

    $body = trim($response->getContent());
    self::assertSame('{"status":"unavailable"}', $body);

    foreach (['version', 'database', 'host', 'trace', 'exception', 'token', 'password'] as $forbidden) {
      self::assertStringNotContainsString($forbidden, strtolower($body));
    }

    // The fault is readiness-only: Drupal/runtime liveness must remain healthy.
    $client->request('GET', $this->baseUrl . '/health/live');
    $response = $client->getResponse();
    self::assertSame(200, $response->getStatusCode());
    self::assertNull($response->getHeader('Location'));
    self::assertSame('{"status":"ok"}', trim($response->getContent()));
  }

}

<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Covers the public Agency health endpoints through a real Drupal request.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class AgencyHealthEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_health',
    'language',
    'node',
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

    if (!ConfigurableLanguage::load('fr')) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }
    if (!ConfigurableLanguage::load('en')) {
      ConfigurableLanguage::createFromLangcode('en')->save();
    }

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();

    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['fr' => 'fr', 'en' => 'en'])
      ->set('url.domains', ['fr' => '', 'en' => ''])
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
      ->set('default_status_code', 301)
      ->save();

    if (!NodeType::load('page')) {
      NodeType::create([
        'type' => 'page',
        'name' => 'Basic page',
      ])->save();
    }

    drupal_flush_all_caches();
  }

  /**
   * Liveness and readiness stay direct under multilingual normalization.
   */
  public function testPublicHealthEndpointsAreHealthyAndMinimal(): void {
    $driver = $this->getSession()->getDriver();
    self::assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->followRedirects(FALSE);

    foreach (['/health/live', '/health/ready'] as $path) {
      $client->request('GET', $this->baseUrl . $path);
      $response = $client->getResponse();

      self::assertSame(200, $response->getStatusCode());
      self::assertFalse($response->headers->has('Location'));
      self::assertStringStartsWith(
        'application/json',
        (string) $response->headers->get('Content-Type'),
      );
      self::assertStringContainsString(
        'no-store',
        strtolower((string) $response->headers->get('Cache-Control')),
      );

      $body = trim((string) $response->getContent());
      self::assertSame('{"status":"ok"}', $body);

      foreach (['version', 'database', 'host', 'trace', 'exception', 'token', 'password'] as $forbidden) {
        self::assertStringNotContainsString($forbidden, strtolower($body));
      }
    }
  }

  /**
   * Unsupported methods are rejected by the route contract.
   */
  public function testHealthEndpointsRejectPost(): void {
    $driver = $this->getSession()->getDriver();
    self::assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->followRedirects(FALSE);

    foreach (['/health/live', '/health/ready'] as $path) {
      $client->request('POST', $this->baseUrl . $path);
      self::assertSame(405, $client->getResponse()->getStatusCode());
    }
  }

  /**
   * A normal editorial route remains subject to language normalization.
   */
  public function testEditorialRouteStillUsesLanguageRouteNormalizer(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Normalizer control page',
      'langcode' => 'fr',
      'status' => Node::PUBLISHED,
    ]);
    $node->save();

    $driver = $this->getSession()->getDriver();
    self::assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->followRedirects(FALSE);
    $client->request('GET', $this->baseUrl . '/node/' . $node->id());

    $response = $client->getResponse();
    self::assertSame(301, $response->getStatusCode());
    self::assertSame('1', $response->headers->get('X-Drupal-Route-Normalizer'));

    $location = $response->headers->get('Location');
    self::assertNotNull($location);
    self::assertSame(
      '/fr/node/' . $node->id(),
      parse_url($location, PHP_URL_PATH),
    );
  }

}

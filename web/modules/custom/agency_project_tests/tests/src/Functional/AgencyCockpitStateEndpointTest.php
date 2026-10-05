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
 * Proves the cockpit machine endpoint stays canonical under language redirects.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class AgencyCockpitStateEndpointTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'agency_operations',
    'language',
    'locale',
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

    NodeType::create([
      'type' => 'page',
      'name' => 'Basic page',
    ])->save();

    drupal_flush_all_caches();
  }

  /**
   * The unprefixed machine endpoint reaches the JSON contract without redirect.
   */
  public function testCockpitMachineEndpointBypassesLanguageNormalizer(): void {
    $route = $this->container
      ->get('router.route_provider')
      ->getRouteByName('agency_operations.cockpit_state');
    self::assertTrue((bool) $route->getDefault('_disable_route_normalizer'));

    $driver = $this->getSession()->getDriver();
    self::assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $client->followRedirects(FALSE);

    $client->request(
      'GET',
      $this->baseUrl . '/api/agency-operations/v1/environment-data-state',
    );
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
    self::assertSame(
      ['error' => 'transport_unavailable'],
      json_decode($response->getContent(), TRUE, 8, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Ordinary editorial routes retain the normal French path canonicalization.
   */
  public function testEditorialRouteStillUsesLanguageCanonicalization(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Canonical multilingual page',
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
    self::assertSame(
      '/fr/node/' . $node->id(),
      parse_url((string) $response->getHeader('Location'), PHP_URL_PATH),
    );
    self::assertSame('1', $response->getHeader('X-Drupal-Route-Normalizer'));
  }

}

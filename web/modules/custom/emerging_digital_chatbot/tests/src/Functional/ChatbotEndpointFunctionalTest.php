<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Functional;

use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the public chatbot endpoint security exposure.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class ChatbotEndpointFunctionalTest extends BrowserTestBase {

  use BlockCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'emerging_digital_chatbot',
    'path_alias',
    'system',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests the widget exposes a tokenized endpoint and CSRF remains enforced.
   */
  public function testEndpointExposesTokenAndRejectsMissingCsrf(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('mode', 'ai')
      ->set('future_ai.enabled', FALSE)
      ->set('future_ai.prompts.fr.system', 'Hidden functional system prompt.')
      ->save();

    $endpoint = $this->getRenderedEndpointUrl();
    self::assertStringStartsWith(
      '/api/emerging-digital-chatbot/conversation?',
      $endpoint,
    );
    self::assertStringContainsString('token=', $endpoint);

    $response = $this->getHttpClient()->request(
      'POST',
      $this->getAbsoluteUrl('/api/emerging-digital-chatbot/conversation'),
      [
        'body' => json_encode([
          'langcode' => 'fr',
          'message' => 'Je cherche une aide Drupal avec sk-test-secret.',
        ], JSON_THROW_ON_ERROR),
        'cookies' => $this->getSessionCookies(),
        'headers' => [
          'Content-Type' => 'application/json',
        ],
        'http_errors' => FALSE,
      ],
    );
    $body = (string) $response->getBody();
    self::assertSame(
      403,
      $response->getStatusCode(),
      sprintf(
        'Expected missing CSRF token to be rejected: %s',
        $this->sanitizeBodyExcerpt($body),
      ),
    );
    self::assertStringNotContainsString('sk-test-secret', $body);
    self::assertStringNotContainsString('Hidden functional system prompt.', $body);
  }

  /**
   * Gets the CSRF-protected endpoint URL rendered for the browser widget.
   */
  private function getRenderedEndpointUrl(): string {
    $this->placeBlock('emerging_digital_chatbot', [
      'id' => 'emerging_digital_chatbot_endpoint_test',
      'label_display' => FALSE,
      'suppress_contact_pages' => FALSE,
    ]);

    $this->drupalGet('/user/login');
    $this->assertSession()->statusCodeEquals(200);

    $settingsElement = $this->assertSession()->elementExists(
      'css',
      'script[data-drupal-selector="drupal-settings-json"]',
    );
    $settingsJson = html_entity_decode(
      $settingsElement->getHtml(),
      ENT_QUOTES | ENT_HTML5,
      'UTF-8',
    );
    $settings = json_decode(
      $settingsJson,
      TRUE,
      512,
      JSON_THROW_ON_ERROR,
    );
    $endpoint = $settings['emergingDigitalChatbot']['emerging-digital-chatbot']['endpoint'] ?? NULL;

    self::assertIsString($endpoint);
    self::assertStringContainsString('token=', $endpoint);
    $page = $this->getSession()->getPage()->getContent();
    self::assertStringNotContainsString('Hidden functional system prompt.', $page);
    self::assertStringNotContainsString('sk-test-secret', $page);

    return $endpoint;
  }

  /**
   * Sanitizes a short body excerpt for failure messages.
   */
  private function sanitizeBodyExcerpt(string $body): string {
    $excerpt = strip_tags($body);
    $excerpt = preg_replace('/\s+/u', ' ', (string) $excerpt);

    return mb_substr(trim((string) $excerpt), 0, 300);
  }

}

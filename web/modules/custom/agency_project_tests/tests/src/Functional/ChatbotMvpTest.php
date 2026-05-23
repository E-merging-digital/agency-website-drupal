<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests the non-AI chatbot MVP.
 *
 * @group agency_project_tests
 */
#[RunTestsInSeparateProcesses]
final class ChatbotMvpTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'emerging_digital_chatbot',
  ];

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * Tests the widget shell and local deterministic payload.
   */
  public function testChatbotWidgetRendersLocalGuidedPayload(): void {
    $this->placeBlock('emerging_digital_chatbot', [
      'id' => 'chatbot_mvp_test',
      'suppress_contact_pages' => FALSE,
      'launcher_variant' => 'labelled',
    ]);

    $this->drupalGet('<front>');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementExists('css', '.ed-chatbot');
    $this->assertSession()->elementExists(
      'css',
      '.ed-chatbot__launcher[aria-expanded="false"]'
      . '[aria-controls="emerging-digital-chatbot-panel"]'
    );
    $this->assertSession()->elementExists(
      'css',
      '#emerging-digital-chatbot-panel[role="dialog"][aria-modal="true"]'
    );
    $this->assertSession()->pageTextContains('E-merging Digital assistant');
    $this->assertSession()->responseContains('Qualify my project');
    $this->assertSession()->responseNotContains('api.openai.com');
    $this->assertSession()->responseNotContains('OpenAI');

    $settings = $this->getRenderedChatbotSettings();
    self::assertArrayNotHasKey('endpoint', $settings);
    self::assertSame(
      '/en/contact',
      $settings['payload']['messages']['flows']['qualify_project']['ctas'][0]['path'] ?? NULL,
    );
  }

  /**
   * Tests that the MVP exposes no backend conversation endpoint.
   */
  public function testChatbotDoesNotExposeConversationEndpoint(): void {
    $this->expectException(RouteNotFoundException::class);

    $this->container
      ->get('router.route_provider')
      ->getRouteByName('emerging_digital_chatbot.conversation');
  }

  /**
   * Tests the deterministic engine drops external CTA URLs.
   */
  public function testQualificationEngineKeepsCtasInternal(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('messages.en.flows.external_test', [
        'label' => 'External test',
        'response' => 'Local response.',
        'ctas' => [
          [
            'label' => 'Lien externe',
            'path' => 'https://example.com',
          ],
          [
            'label' => 'Contact',
            'path' => '/fr/contact',
          ],
        ],
      ])
      ->save();

    $payload = $this->container
      ->get('emerging_digital_chatbot.qualification_engine')
      ->buildPayload();

    self::assertSame([
      [
        'label' => 'Contact',
        'path' => '/fr/contact',
      ],
    ], $payload['messages']['flows']['external_test']['ctas']);
  }

  /**
   * Gets rendered chatbot drupalSettings.
   *
   * @return array<string, mixed>
   *   Rendered settings for the chatbot widget.
   */
  private function getRenderedChatbotSettings(): array {
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

    $chatbotSettings = $settings['emergingDigitalChatbot']['emerging-digital-chatbot'] ?? NULL;
    self::assertIsArray($chatbotSettings);

    return $chatbotSettings;
  }

}

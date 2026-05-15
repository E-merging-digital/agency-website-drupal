<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Functional;

use Drupal\Tests\block\Traits\BlockCreationTrait;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the anonymous chatbot drupalSettings payload.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class ChatbotPublicSettingsTest extends BrowserTestBase {

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
   * Tests anonymous HTML does not expose Future AI technical settings.
   */
  public function testAnonymousWidgetSettingsDoNotExposeFutureAiDiagnostics(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('mode', 'ai')
      ->set('future_ai.enabled', FALSE)
      ->set('future_ai.provider', 'leaky_provider_public_test')
      ->set('future_ai.model', 'leaky-model-public-test')
      ->set('future_ai.prompt_version', 'leaky_prompt_version_public_test')
      ->set('future_ai.rag_profile', 'leaky_rag_profile_public_test')
      ->set('future_ai.prompts.en.system', 'Hidden public system prompt.')
      ->set('future_ai.context.allowed_public_paths.en', [
        '/leaky-public-path',
      ])
      ->save();

    $this->placeBlock('emerging_digital_chatbot', [
      'id' => 'emerging_digital_chatbot_public_test',
      'label' => 'Chatbot public test',
      'label_display' => FALSE,
      'suppress_contact_pages' => FALSE,
    ]);

    $this->drupalGet('/user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseContains('emergingDigitalChatbot');
    $this->assertSession()->responseContains('E-merging Digital assistant');

    $this->assertSession()->responseNotContains('leaky_provider_public_test');
    $this->assertSession()->responseNotContains('leaky-model-public-test');
    $this->assertSession()->responseNotContains(
      'leaky_prompt_version_public_test',
    );
    $this->assertSession()->responseNotContains(
      'leaky_rag_profile_public_test',
    );
    $this->assertSession()->responseNotContains('/leaky-public-path');
    $this->assertSession()->responseNotContains('Hidden public system prompt.');

    foreach ([
      'provider',
      'model',
      'promptVersion',
      'ragProfile',
      'promptPrepared',
      'allowedPublicPaths',
    ] as $technicalKey) {
      $this->assertSession()->responseNotContains(
        '"' . $technicalKey . '"',
      );
      $this->assertSession()->responseNotContains(
        '&quot;' . $technicalKey . '&quot;',
      );
    }
  }

}

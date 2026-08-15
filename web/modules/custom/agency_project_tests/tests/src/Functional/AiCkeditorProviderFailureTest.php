<?php

declare(strict_types=1);

namespace Drupal\Tests\agency_project_tests\Functional;

use Drupal\editor\Entity\Editor;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves AI CKEditor does not make normal Article authoring provider-dependent.
 *
 * @group agency_project_tests
 * @group agency_ai
 */
#[Group('agency_ai')]
#[RunTestsInSeparateProcesses]
final class AiCkeditorProviderFailureTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ai',
    'ai_ckeditor',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    if (!NodeType::load('article')) {
      $this->drupalCreateContentType([
        'type' => 'article',
        'name' => 'Article',
      ]);
    }

    $editor = Editor::load('basic_html');
    self::assertInstanceOf(Editor::class, $editor);

    $settings = $editor->getSettings();
    $toolbar = $settings['toolbar']['items'] ?? [];
    self::assertIsArray($toolbar);
    if (!in_array('aickeditor', $toolbar, TRUE)) {
      $toolbar[] = 'aickeditor';
    }
    $settings['toolbar']['items'] = $toolbar;
    $settings['plugins']['ai_ckeditor_ai'] = [
      'dialog' => [
        'autoresize' => 'min-width: 600px',
        'height' => '750',
        'width' => '900',
        'dialog_class' => 'ai-ckeditor-modal',
      ],
      'plugins' => [
        'ai_ckeditor_completion' => [
          'enabled' => TRUE,
          'provider' => '',
        ],
        'ai_ckeditor_help' => ['enabled' => FALSE],
        'ai_ckeditor_modify_prompt' => [
          'enabled' => TRUE,
          'provider' => '',
        ],
        'ai_ckeditor_reformat_html' => [
          'enabled' => FALSE,
          'provider' => '',
        ],
        'ai_ckeditor_spellfix' => [
          'enabled' => TRUE,
          'provider' => '',
        ],
        'ai_ckeditor_summarize' => [
          'enabled' => TRUE,
          'provider' => '',
        ],
        'ai_ckeditor_tone' => [
          'enabled' => FALSE,
          'provider' => '',
          'autocreate' => FALSE,
          'tone_vocabulary' => 'tags',
          'use_description' => FALSE,
        ],
        'ai_ckeditor_translate' => [
          'enabled' => FALSE,
          'provider' => '',
          'language_source' => 'lang',
          'autocreate' => FALSE,
          'translate_vocabulary' => 'tags',
          'use_description' => FALSE,
        ],
      ],
    ];
    $editor->setSettings($settings)->save();

    $account = $this->drupalCreateUser([
      'create article content',
      'use text format basic_html',
      'use ai ckeditor',
    ]);
    self::assertNotFalse($account);
    $this->drupalLogin($account);
  }

  /**
   * Verifies an editor can save an Article with no AI provider configured.
   */
  public function testNormalArticleSaveWithoutProvider(): void {
    self::assertSame([], $this->config('ai.settings')->get('default_providers') ?? []);

    $this->drupalGet('node/add/article');
    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'title[0][value]' => 'Human authored article without provider',
      'body[0][value]' => '<p>This content is saved without invoking AI.</p>',
    ], 'Save');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Human authored article without provider');
    $this->assertSession()->pageTextContains('has been created');
  }

}

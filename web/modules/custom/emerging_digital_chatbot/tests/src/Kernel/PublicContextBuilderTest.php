<?php

declare(strict_types=1);

namespace Drupal\Tests\emerging_digital_chatbot\Kernel;

use Drupal\emerging_digital_chatbot\Context\PublicContextBuilder;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the controlled public context builder.
 *
 * @group emerging_digital_chatbot
 */
#[RunTestsInSeparateProcesses]
final class PublicContextBuilderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'emerging_digital_chatbot',
    'field',
    'filter',
    'language',
    'node',
    'path_alias',
    'system',
    'text',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['emerging_digital_chatbot', 'filter', 'node']);

    ConfigurableLanguage::createFromLangcode('fr')->save();

    $type = NodeType::create([
      'type' => 'page',
      'name' => 'Page',
    ]);
    $type->save();
    FieldStorageConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'type' => 'text_with_summary',
      'translatable' => TRUE,
    ])->save();
    FieldConfig::create([
      'field_name' => 'body',
      'entity_type' => 'node',
      'bundle' => $type->id(),
      'label' => 'Body',
    ])->save();

    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.enabled', TRUE)
      ->set('future_ai.context.max_context_chars', 500)
      ->set('future_ai.context.allowed_public_paths.fr', [
        '/fr/public',
        '/fr/unpublished',
        '/admin/content',
      ])
      ->set('future_ai.context.allowed_public_paths.en', [
        '/en/public',
      ])
      ->save();
  }

  /**
   * Tests publication, language, path and sensitive-data boundaries.
   */
  public function testBuildContextUsesOnlyAllowedPublishedLanguageContent(): void {
    $published = Node::create([
      'type' => 'page',
      'langcode' => 'fr',
      'title' => 'Page publique FR',
      'status' => 1,
      'body' => [
        'value' => '<h2>Drupal public FR</h2><script>bad()</script><p>Contact test@example.com ou +32 477 12 34 56.</p>',
        'format' => 'plain_text',
      ],
    ]);
    $published->addTranslation('en', [
      'title' => 'Public English page',
      'status' => 1,
      'body' => [
        'value' => '<p>Public English Drupal content.</p><p>Phone 0477 12 34 56.</p>',
        'format' => 'plain_text',
      ],
    ]);
    $published->save();

    $unpublished = Node::create([
      'type' => 'page',
      'langcode' => 'fr',
      'title' => 'Brouillon secret',
      'status' => 0,
      'body' => [
        'value' => '<p>Contenu non publié.</p>',
        'format' => 'plain_text',
      ],
    ]);
    $unpublished->save();

    $forbidden = Node::create([
      'type' => 'page',
      'langcode' => 'fr',
      'title' => 'Chemin interdit',
      'status' => 1,
      'body' => [
        'value' => '<p>Ce contenu ne doit pas sortir.</p>',
        'format' => 'plain_text',
      ],
    ]);
    $forbidden->save();

    $this->createAlias('/node/' . $published->id(), '/public', 'fr');
    $this->createAlias('/node/' . $published->id(), '/public', 'en');
    $this->createAlias('/node/' . $unpublished->id(), '/unpublished', 'fr');
    $this->createAlias('/node/' . $forbidden->id(), '/forbidden', 'fr');

    $builder = $this->container->get('emerging_digital_chatbot.public_context_builder');
    self::assertInstanceOf(PublicContextBuilder::class, $builder);

    $frContext = $builder->buildContext('fr');
    $frText = $frContext['text'];

    self::assertSame(['/fr/public'], $frContext['paths']);
    self::assertStringContainsString('Page publique FR', $frText);
    self::assertStringContainsString('Drupal public FR', $frText);
    self::assertStringNotContainsString('Brouillon secret', $frText);
    self::assertStringNotContainsString('Chemin interdit', $frText);
    self::assertStringNotContainsString('/fr/forbidden', $frText);
    self::assertStringNotContainsString('/admin/content', $frText);
    self::assertStringNotContainsString('<h2>', $frText);
    self::assertStringNotContainsString('<script>', $frText);
    self::assertStringNotContainsString('bad()', $frText);
    self::assertStringNotContainsString('test@example.com', $frText);
    self::assertStringNotContainsString('+32 477 12 34 56', $frText);

    $enContext = $builder->buildContext('en');
    $enText = $enContext['text'];

    self::assertSame(['/en/public'], $enContext['paths']);
    self::assertStringContainsString('Public English page', $enText);
    self::assertStringContainsString('Public English Drupal content.', $enText);
    self::assertStringNotContainsString('Page publique FR', $enText);
    self::assertStringNotContainsString('0477 12 34 56', $enText);
  }

  /**
   * Tests context length limiting and UTF-8 preservation.
   */
  public function testSanitizeContextTextLimitsLengthAndPreservesUtf8(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.context.max_context_chars', 80)
      ->save();

    $builder = $this->container->get('emerging_digital_chatbot.public_context_builder');
    self::assertInstanceOf(PublicContextBuilder::class, $builder);

    $text = '<p>' . str_repeat('Éléphant agile ', 20) . 'person@example.com 0477 12 34 56</p>';
    $sanitized = $builder->sanitizeContextText($text);

    self::assertLessThanOrEqual(80, mb_strlen($sanitized));
    self::assertTrue(mb_check_encoding($sanitized, 'UTF-8'));
    self::assertStringContainsString('Éléphant', $sanitized);
    self::assertStringNotContainsString('<p>', $sanitized);
    self::assertStringNotContainsString('person@example.com', $sanitized);
    self::assertStringNotContainsString('0477 12 34 56', $sanitized);
  }

  /**
   * Tests that no public context is built while future AI is disabled.
   */
  public function testBuildContextReturnsEmptyContextWhenFutureAiIsDisabled(): void {
    $this->config('emerging_digital_chatbot.settings')
      ->set('future_ai.enabled', FALSE)
      ->save();

    $builder = $this->container->get('emerging_digital_chatbot.public_context_builder');
    self::assertInstanceOf(PublicContextBuilder::class, $builder);

    $context = $builder->buildContext('fr');

    self::assertFalse($context['enabled']);
    self::assertSame([], $context['paths']);
    self::assertSame([], $context['items']);
    self::assertSame('', $context['text']);
  }

  /**
   * Creates a path alias.
   */
  private function createAlias(string $path, string $alias, string $langcode): void {
    PathAlias::create([
      'path' => $path,
      'alias' => $alias,
      'langcode' => $langcode,
    ])->save();
  }

}

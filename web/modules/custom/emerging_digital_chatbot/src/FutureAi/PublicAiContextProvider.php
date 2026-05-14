<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\emerging_digital_chatbot\Context\PublicContextBuilder;

/**
 * Prepares the public-only context boundary for future retrieval.
 */
final class PublicAiContextProvider {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly PublicContextBuilder $contextBuilder,
  ) {
  }

  /**
   * Builds the public context contract for future AI providers.
   *
   * @return array{
   *   profile: string,
   *   enabled: bool,
   *   langcode: string,
   *   max_context_chars: int,
   *   paths: list<string>,
   *   text: string,
   *   status: string
   *   }
   *   Public-only context metadata and sanitized text.
   */
  public function buildContextContract(string $langcode): array {
    $publicContext = $this->contextBuilder->buildContext($langcode);

    return [
      'profile' => $this->getRagProfile(),
      'enabled' => (bool) $publicContext['enabled'],
      'langcode' => $publicContext['langcode'],
      'max_context_chars' => $publicContext['max_context_chars'],
      'paths' => $publicContext['paths'],
      'text' => $publicContext['text'],
      'status' => $publicContext['text'] === '' ? 'empty' : 'ready',
    ];
  }

  /**
   * Builds a short context note for the provider prompt.
   */
  public function buildPromptContext(string $langcode, int $maxChars): string {
    $contract = $this->buildContextContract($langcode);
    $context = [
      'Context profile: ' . $contract['profile'] . '.',
      'Public context status: ' . $contract['status'] . '.',
      'Allowed context: published public pages only.',
      'Excluded context: admin pages, drafts, webform submissions, private files, CRM data, visitor conversations, and personal data.',
      'Retrieval status: Drupal public context builder active; no vector store or autonomous tool call is active.',
      'Allowed public paths: ' . ($contract['paths'] === [] ? 'none loaded' : implode(', ', $contract['paths'])),
    ];

    if ($contract['text'] !== '') {
      $context[] = 'Public Drupal context:';
      $context[] = $contract['text'];
    }

    return mb_substr(implode("\n", $context), 0, $maxChars);
  }

  /**
   * Gets the configured RAG profile.
   */
  private function getRagProfile(): string {
    $profile = $this->configFactory
      ->get('emerging_digital_chatbot.settings')
      ->get('future_ai.rag_profile');

    return is_string($profile) && trim($profile) !== '' ? trim($profile) : 'public_pages_v1';
  }

}

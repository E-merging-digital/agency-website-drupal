<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\FutureAi;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Prepares the public-only context boundary for future retrieval.
 */
final class PublicAiContextProvider {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Builds a short context note for the provider prompt.
   */
  public function buildPromptContext(string $langcode, int $maxChars): string {
    $paths = $this->getAllowedPublicPaths($langcode);
    $context = [
      'Context profile: public_pages_v1.',
      'Allowed context: published public pages only.',
      'Excluded context: admin pages, drafts, webform submissions, private files, CRM data, visitor conversations, and personal data.',
      'Retrieval status: prepared structure only; no vector store or autonomous tool call is active.',
      'Allowed public paths: ' . implode(', ', $paths),
    ];

    return mb_substr(implode("\n", $context), 0, $maxChars);
  }

  /**
   * Gets configured public paths for one language.
   *
   * @return string[]
   *   Public path list.
   */
  private function getAllowedPublicPaths(string $langcode): array {
    $paths = $this->configFactory
      ->get('emerging_digital_chatbot.settings')
      ->get("future_ai.context.allowed_public_paths.$langcode");

    if (!is_array($paths)) {
      return [];
    }

    return array_values(array_filter(array_map('strval', $paths)));
  }

}

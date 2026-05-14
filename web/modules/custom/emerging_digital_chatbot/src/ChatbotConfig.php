<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Reads and normalizes chatbot configuration for rendering and endpoints.
 */
final class ChatbotConfig {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly CurrentPathStack $currentPath,
    private readonly AliasManagerInterface $aliasManager,
    private readonly PathMatcherInterface $pathMatcher,
  ) {
  }

  /**
   * Determines whether the widget can render on the current request.
   */
  public function isVisibleOnCurrentPage(bool $suppress_contact_pages = TRUE): bool {
    if (!$this->isEnabled()) {
      return FALSE;
    }

    $langcode = $this->getCurrentLangcode();
    if (!in_array($langcode, $this->getEnabledLanguages(), TRUE)) {
      return FALSE;
    }

    $paths = $this->getCurrentPathCandidates($langcode);
    if ($suppress_contact_pages && $this->matchesAny($paths, ['/fr/contact', '/en/contact', '/contact'])) {
      return FALSE;
    }

    if ($this->matchesAny($paths, $this->getExcludedPages())) {
      return FALSE;
    }

    $allowed_pages = $this->getAllowedPages();
    return in_array('*', $allowed_pages, TRUE) || $this->matchesAny($paths, $allowed_pages);
  }

  /**
   * Builds the localized payload consumed by the vanilla JS widget.
   *
   * @return array<string, mixed>
   *   The normalized chatbot payload.
   */
  public function getWidgetPayload(): array {
    $langcode = $this->getCurrentLangcode();
    $messages = $this->getMessages($langcode);

    return [
      'langcode' => $langcode,
      'mode' => $this->getMode(),
      'futureAiEnabled' => $this->isFutureAiEnabled(),
      'futureAi' => $this->getFutureAiSummary($langcode),
      'messages' => $messages,
    ];
  }

  /**
   * Gets the active interface language.
   */
  public function getCurrentLangcode(): string {
    return $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)
      ->getId();
  }

  /**
   * Gets the configured conversation mode.
   */
  public function getMode(): string {
    return (string) ($this->configFactory->get('emerging_digital_chatbot.settings')->get('mode') ?? 'guide');
  }

  /**
   * Determines whether future AI mode is explicitly enabled.
   */
  public function isFutureAiEnabled(): bool {
    return (bool) $this->configFactory->get('emerging_digital_chatbot.settings')->get('future_ai.enabled');
  }

  /**
   * Gets a small, non-secret future AI summary for diagnostics.
   *
   * @return array<string, string|bool>
   *   Future AI architecture metadata.
   */
  public function getFutureAiSummary(?string $langcode = NULL): array {
    $config = $this->configFactory->get('emerging_digital_chatbot.settings');
    $langcode ??= $this->getCurrentLangcode();
    $prompt = $config->get("future_ai.prompts.$langcode.system");

    return [
      'enabled' => (bool) $config->get('future_ai.enabled'),
      'provider' => (string) ($config->get('future_ai.provider') ?? 'openai_responses'),
      'promptVersion' => (string) ($config->get('future_ai.prompt_version') ?? 'mvp_contact_v1'),
      'ragProfile' => (string) ($config->get('future_ai.rag_profile') ?? 'public_pages_v1'),
      'retentionPolicy' => (string) ($config->get('future_ai.retention_policy') ?? 'none'),
      'promptPrepared' => is_string($prompt) && $prompt !== '',
    ];
  }

  /**
   * Gets localized messages, falling back to French.
   *
   * @return array<string, mixed>
   *   The localized messages.
   */
  public function getMessages(string $langcode): array {
    $config = $this->configFactory->get('emerging_digital_chatbot.settings');
    $messages = $config->get("messages.$langcode");
    if (!is_array($messages)) {
      $messages = $config->get('messages.fr');
    }

    return is_array($messages) ? $messages : [];
  }

  /**
   * Determines whether the chatbot is enabled.
   */
  private function isEnabled(): bool {
    return (bool) $this->configFactory->get('emerging_digital_chatbot.settings')->get('enabled');
  }

  /**
   * Gets enabled languages.
   *
   * @return string[]
   *   Enabled language codes.
   */
  private function getEnabledLanguages(): array {
    return $this->getStringList('languages');
  }

  /**
   * Gets allowed pages.
   *
   * @return string[]
   *   Allowed page patterns.
   */
  private function getAllowedPages(): array {
    return $this->getStringList('allowed_pages');
  }

  /**
   * Gets excluded pages.
   *
   * @return string[]
   *   Excluded page patterns.
   */
  private function getExcludedPages(): array {
    return $this->getStringList('excluded_pages');
  }

  /**
   * Reads a string sequence from configuration.
   *
   * @return string[]
   *   A normalized string list.
   */
  private function getStringList(string $key): array {
    $value = $this->configFactory->get('emerging_digital_chatbot.settings')->get($key);
    if (!is_array($value)) {
      return [];
    }

    return array_values(array_filter(array_map('strval', $value)));
  }

  /**
   * Gets candidate paths for current route matching.
   *
   * @return string[]
   *   Internal path, alias and front path candidates.
   */
  private function getCurrentPathCandidates(string $langcode): array {
    $path = $this->currentPath->getPath();
    $alias = $this->aliasManager->getAliasByPath($path, $langcode);
    $paths = [$path, $alias];

    if ($this->pathMatcher->isFrontPage()) {
      $paths[] = '<front>';
      $paths[] = '/';
    }

    return array_values(array_unique(array_map([$this, 'normalizePath'], $paths)));
  }

  /**
   * Checks whether any path matches the configured pattern list.
   *
   * @param string[] $paths
   *   Current path candidates.
   * @param string[] $patterns
   *   Configured path patterns. Supports "*" and trailing "*".
   */
  private function matchesAny(array $paths, array $patterns): bool {
    foreach ($patterns as $pattern) {
      $pattern = $this->normalizePath($pattern);
      foreach ($paths as $path) {
        if ($pattern === '*' || $pattern === $path) {
          return TRUE;
        }
        if (str_ends_with($pattern, '*') && str_starts_with($path, rtrim($pattern, '*'))) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Normalizes a configured path.
   */
  private function normalizePath(string $path): string {
    $path = trim($path);
    if ($path === '' || $path === '*' || $path === '<front>') {
      return $path;
    }

    return '/' . ltrim($path, '/');
  }

}

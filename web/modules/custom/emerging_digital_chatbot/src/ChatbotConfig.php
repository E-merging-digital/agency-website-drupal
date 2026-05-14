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
   * Gets the configured Responses API endpoint.
   */
  public function getFutureAiEndpoint(): string {
    return $this->getFutureAiString('endpoint', 'https://api.openai.com/v1/responses');
  }

  /**
   * Gets the configured OpenAI model.
   */
  public function getFutureAiModel(): string {
    return $this->getFutureAiString('model', 'gpt-4o-mini');
  }

  /**
   * Gets the configured prompt version.
   */
  public function getFutureAiPromptVersion(): string {
    return $this->getFutureAiString('prompt_version', 'mvp_contact_v1');
  }

  /**
   * Gets the configured future RAG profile.
   */
  public function getFutureAiRagProfile(): string {
    return $this->getFutureAiString('rag_profile', 'public_pages_v1');
  }

  /**
   * Gets the localized system prompt.
   */
  public function getFutureAiSystemPrompt(string $langcode): string {
    $config = $this->configFactory->get('emerging_digital_chatbot.settings');
    $prompt = $config->get("future_ai.prompts.$langcode.system");
    if (!is_string($prompt) || trim($prompt) === '') {
      $prompt = $config->get('future_ai.prompts.fr.system');
    }

    return is_string($prompt) ? trim($prompt) : '';
  }

  /**
   * Gets the localized fallback message for unavailable AI mode.
   */
  public function getFutureAiFallbackMessage(string $langcode): string {
    $config = $this->configFactory->get('emerging_digital_chatbot.settings');
    $message = $config->get("future_ai.fallback_message.$langcode");
    if (!is_string($message) || trim($message) === '') {
      $message = $config->get('future_ai.fallback_message.fr');
    }

    return is_string($message) ? trim($message) : 'AI mode is unavailable. Please use the guided choices or contact the team.';
  }

  /**
   * Gets the AI response temperature.
   */
  public function getFutureAiTemperature(): float {
    $value = $this->configFactory->get('emerging_digital_chatbot.settings')->get('future_ai.temperature');

    return max(0.0, min(1.0, is_numeric($value) ? (float) $value : 0.2));
  }

  /**
   * Gets the maximum output token budget.
   */
  public function getFutureAiMaxOutputTokens(): int {
    return $this->getFutureAiBoundedInt('max_output_tokens', 220, 80, 600);
  }

  /**
   * Gets the provider timeout in seconds.
   */
  public function getFutureAiTimeoutSeconds(): int {
    return $this->getFutureAiBoundedInt('timeout_seconds', 8, 2, 30);
  }

  /**
   * Gets the maximum visitor message length.
   */
  public function getFutureAiMaxInputChars(): int {
    return $this->getFutureAiBoundedInt('security.max_input_chars', 600, 80, 1200);
  }

  /**
   * Gets the maximum prompt context length.
   */
  public function getFutureAiMaxContextChars(): int {
    $contextValue = $this->configFactory->get('emerging_digital_chatbot.settings')->get('future_ai.context.max_context_chars');
    if (is_numeric($contextValue)) {
      return max(1, min(4000, (int) $contextValue));
    }

    return $this->getFutureAiBoundedInt('security.max_context_chars', 1200, 200, 4000);
  }

  /**
   * Gets the configured rate limit.
   */
  public function getFutureAiRateLimit(): int {
    return $this->getFutureAiBoundedInt('security.rate_limit.limit', 10, 1, 120);
  }

  /**
   * Gets the configured rate limit window.
   */
  public function getFutureAiRateLimitWindow(): int {
    return $this->getFutureAiBoundedInt('security.rate_limit.window_seconds', 60, 10, 3600);
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
      'model' => $this->getFutureAiModel(),
      'promptVersion' => $this->getFutureAiPromptVersion(),
      'ragProfile' => $this->getFutureAiRagProfile(),
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
   * Reads a future AI string setting.
   */
  private function getFutureAiString(string $key, string $default): string {
    $value = $this->configFactory->get('emerging_digital_chatbot.settings')->get("future_ai.$key");

    return is_string($value) && trim($value) !== '' ? trim($value) : $default;
  }

  /**
   * Reads and bounds a future AI integer setting.
   */
  private function getFutureAiBoundedInt(string $key, int $default, int $min, int $max): int {
    $value = $this->configFactory->get('emerging_digital_chatbot.settings')->get("future_ai.$key");
    $value = is_numeric($value) ? (int) $value : $default;

    return max($min, min($max, $value));
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

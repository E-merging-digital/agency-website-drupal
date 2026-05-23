<?php

declare(strict_types=1);

namespace Drupal\emerging_digital_chatbot\Context;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\path_alias\AliasManagerInterface;

/**
 * Builds a controlled public context from configured Drupal pages.
 */
final class PublicContextBuilder {

  private const CONFIG_NAME = 'emerging_digital_chatbot.settings';

  /**
   * Public node text fields allowed in the chatbot context.
   */
  private const PUBLIC_TEXT_FIELDS = [
    'field_short_description',
    'body',
    'field_detailed_description',
  ];

  /**
   * Path prefixes that must never be considered public context.
   */
  private const BLOCKED_PATH_PREFIXES = [
    '/admin',
    '/batch',
    '/core',
    '/system',
    '/user',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly AliasManagerInterface $aliasManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Builds the sanitized public context for the active language.
   *
   * @return array{
   *   enabled: bool,
   *   langcode: string,
   *   max_context_chars: int,
   *   paths: list<string>,
   *   items: list<array{path: string, title: string, summary: string,
   *     content: string}>,
   *   text: string
   *   }
   *   Safe public context metadata and text.
   */
  public function buildContext(?string $langcode = NULL): array {
    $langcode ??= $this->getActiveLangcode();
    $maxChars = $this->getMaxContextChars();

    $context = [
      'enabled' => $this->isFutureAiEnabled(),
      'langcode' => $langcode,
      'max_context_chars' => $maxChars,
      'paths' => [],
      'items' => [],
      'text' => '',
    ];

    if (!$context['enabled']) {
      return $context;
    }

    foreach ($this->getAllowedPublicPaths($langcode) as $path) {
      $node = $this->loadPublishedNodeForPath($path, $langcode);
      if (!$node instanceof NodeInterface) {
        continue;
      }

      $item = $this->buildNodeContextItem($node, $path);
      if ($item['title'] === '' && $item['summary'] === '' && $item['content'] === '') {
        continue;
      }

      $context['paths'][] = $path;
      $context['items'][] = $item;
    }

    $context['text'] = $this->sanitizeContextText($this->formatContextItems($context['items']));

    return $context;
  }

  /**
   * Sanitizes context text before it can be sent to an AI provider.
   */
  public function sanitizeContextText(string $text): string {
    $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $text);
    $text = preg_replace('/<\/(?:p|div|section|article|header|footer|h[1-6]|li|ul|ol|br)>/iu', "\n", (string) $text);
    $text = Html::decodeEntities(strip_tags((string) $text));
    $text = preg_replace('/[[:^print:]\t\r\n]/u', ' ', $text);
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', ' ', (string) $text);
    $text = preg_replace('/(?:\+?\d[\d\s().-]{7,}\d)/u', ' ', (string) $text);
    $text = preg_replace('/\b(?:api[_-]?key|secret|token|password)\b\s*[:=]\s*[^\s]+/iu', ' ', (string) $text);
    $text = preg_replace('/\bsk-[A-Za-z0-9_-]{8,}\b/u', ' ', (string) $text);

    $lines = preg_split('/\R/u', (string) $text) ?: [];
    $lines = array_map(static function (string $line): string {
      $line = preg_replace('/[ \t]+/u', ' ', $line);

      return trim((string) $line);
    }, $lines);
    $lines = array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    $text = trim(implode("\n", $lines));

    return $this->limitText($text, $this->getMaxContextChars());
  }

  /**
   * Determines whether future AI context can be built.
   */
  private function isFutureAiEnabled(): bool {
    return (bool) $this->configFactory->get(self::CONFIG_NAME)->get('future_ai.enabled');
  }

  /**
   * Gets the active interface language code.
   */
  private function getActiveLangcode(): string {
    return $this->languageManager
      ->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)
      ->getId();
  }

  /**
   * Gets configured public paths for one language.
   *
   * @return list<string>
   *   Public path list.
   */
  private function getAllowedPublicPaths(string $langcode): array {
    $paths = $this->configFactory
      ->get(self::CONFIG_NAME)
      ->get("future_ai.context.allowed_public_paths.$langcode");

    if (!is_array($paths)) {
      return [];
    }

    $paths = array_map(fn(mixed $path): string => $this->normalizePath((string) $path), $paths);
    $paths = array_filter($paths, fn(string $path): bool => $this->isAllowedPublicPath($path));

    return array_values(array_unique($paths));
  }

  /**
   * Loads a published node matching a configured public path.
   */
  private function loadPublishedNodeForPath(string $path, string $langcode): ?NodeInterface {
    foreach ($this->getPathLookupCandidates($path, $langcode) as $candidate) {
      $internalPath = $this->aliasManager->getPathByAlias($candidate, $langcode);
      if (!preg_match('/^\/node\/(\d+)$/', $internalPath, $matches)) {
        continue;
      }

      $node = $this->getNodeStorage()->load((int) $matches[1]);
      if (!$node instanceof NodeInterface || !$node->hasTranslation($langcode)) {
        continue;
      }

      $translation = $node->getTranslation($langcode);
      if ($translation->isPublished()) {
        return $translation;
      }
    }

    return NULL;
  }

  /**
   * Gets safe alias lookup candidates for prefixed and neutral paths.
   *
   * @return list<string>
   *   Candidate aliases or internal paths.
   */
  private function getPathLookupCandidates(string $path, string $langcode): array {
    $candidates = [$path];
    $prefix = '/' . $langcode . '/';
    if (str_starts_with($path, $prefix)) {
      $candidates[] = '/' . substr($path, strlen($prefix));
    }

    return array_values(array_unique($candidates));
  }

  /**
   * Builds one public context item from an already filtered node.
   *
   * @return array{path: string, title: string, summary: string, content: string}
   *   Sanitized item data.
   */
  private function buildNodeContextItem(NodeInterface $node, string $path): array {
    $summary = $this->getFieldText($node, 'field_short_description');
    $content = [];
    foreach (self::PUBLIC_TEXT_FIELDS as $fieldName) {
      if ($fieldName === 'field_short_description') {
        continue;
      }
      $content[] = $this->getFieldText($node, $fieldName);
    }

    return [
      'path' => $path,
      'title' => $this->sanitizeContextText($node->label()),
      'summary' => $this->sanitizeContextText($summary),
      'content' => $this->sanitizeContextText(implode("\n", array_filter($content))),
    ];
  }

  /**
   * Extracts raw text from a safe allowlist field.
   */
  private function getFieldText(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }

    $field = $node->get($fieldName);
    if (!$field instanceof FieldItemListInterface) {
      return '';
    }

    $values = [];
    foreach ($field as $item) {
      $value = $item->get('value')->getValue();
      if (is_scalar($value)) {
        $values[] = (string) $value;
      }
    }

    return implode("\n", $values);
  }

  /**
   * Formats public context items as compact prompt text.
   *
   * @param list<array{path: string, title: string, summary: string, content: string}> $items
   *   Public context items.
   */
  private function formatContextItems(array $items): string {
    $lines = [];
    foreach ($items as $item) {
      $lines[] = 'Path: ' . $item['path'];
      if ($item['title'] !== '') {
        $lines[] = 'Title: ' . $item['title'];
      }
      if ($item['summary'] !== '') {
        $lines[] = 'Summary: ' . $item['summary'];
      }
      if ($item['content'] !== '') {
        $lines[] = 'Content: ' . $item['content'];
      }
      $lines[] = '';
    }

    return implode("\n", $lines);
  }

  /**
   * Gets the configured context character budget.
   */
  private function getMaxContextChars(): int {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $value = $config->get('future_ai.context.max_context_chars');
    if (!is_numeric($value)) {
      $value = $config->get('future_ai.security.max_context_chars');
    }

    return max(1, min(4000, is_numeric($value) ? (int) $value : 1200));
  }

  /**
   * Limits text without cutting a UTF-8 sequence or leaving a broken word.
   */
  private function limitText(string $text, int $maxChars): string {
    if (mb_strlen($text) <= $maxChars) {
      return $text;
    }

    $suffix = $maxChars > 3 ? '...' : '';
    $limit = max(1, $maxChars - mb_strlen($suffix));
    $truncated = mb_substr($text, 0, $limit);
    $lastSpace = mb_strrpos($truncated, ' ');
    if (is_int($lastSpace) && $lastSpace > (int) floor($limit * 0.65)) {
      $truncated = mb_substr($truncated, 0, $lastSpace);
    }

    return rtrim($truncated, " \t\n\r\0\x0B.,;:") . $suffix;
  }

  /**
   * Normalizes a configured path.
   */
  private function normalizePath(string $path): string {
    $path = trim($path);
    if ($path === '') {
      return '';
    }

    return '/' . ltrim($path, '/');
  }

  /**
   * Keeps context retrieval scoped to explicit public page aliases only.
   */
  private function isAllowedPublicPath(string $path): bool {
    if ($path === '' || str_contains($path, '?') || str_contains($path, '#')) {
      return FALSE;
    }

    foreach (self::BLOCKED_PATH_PREFIXES as $prefix) {
      if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Gets node storage.
   */
  private function getNodeStorage(): NodeStorageInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    assert($storage instanceof NodeStorageInterface);

    return $storage;
  }

}

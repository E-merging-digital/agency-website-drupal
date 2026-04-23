<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyRepositoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Client HTTP pour traductions IA FR -> EN.
 */
final class AiTranslationClient {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
    private readonly StateInterface $state,
    private readonly ?KeyRepositoryInterface $keyRepository = NULL,
  ) {}

  /**
   * Traduit un texte entre deux langues via un provider OpenAI-compatible.
   */
  public function translate(string $text, string $sourceLangcode, string $targetLangcode): string {
    $text = trim($text);
    if ($text === '') {
      return $text;
    }

    $config = $this->configFactory->get('agency_ai_translation.settings');
    $apiKey = $this->resolveApiKey();
    if ($apiKey === '') {
      throw new \RuntimeException('Configuration OpenAI absente. Vérifiez d’abord la Key Drupal/provider OpenAI, puis les fallbacks éventuels.');
    }

    $endpoint = (string) $config->get('endpoint');
    $model = (string) $config->get('model');
    $systemPrompt = (string) $config->get('system_prompt');
    $sourceLanguageLabel = $this->getLanguageLabel($sourceLangcode);
    $targetLanguageLabel = $this->getLanguageLabel($targetLangcode);
    $systemPromptWithContext = trim($systemPrompt . "\n\nSource language: {$sourceLanguageLabel}\nTarget language: {$targetLanguageLabel}\nReturn only the translated content.");

    $payload = [
      'model' => $model,
      'temperature' => 0.2,
      'messages' => [
        ['role' => 'system', 'content' => $systemPromptWithContext],
        [
          'role' => 'user',
          'content' => "Translate from {$sourceLanguageLabel} to {$targetLanguageLabel} without adding commentary.\n\nSource content:\n{$text}",
        ],
      ],
    ];

    $response = $this->httpClient->request('POST', $endpoint, [
      'headers' => [
        'Authorization' => 'Bearer ' . $apiKey,
        'Content-Type' => 'application/json',
      ],
      'json' => $payload,
      'timeout' => 60,
    ]);

    $data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $translated = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

    if ($translated === '') {
      $this->logger->warning('Réponse IA vide pour une tentative de traduction.');
      return $text;
    }

    return $translated;
  }

  /**
   * Traduit du FR vers EN (compatibilité rétro).
   */
  public function translateFrToEn(string $text): string {
    return $this->translate($text, 'fr', 'en');
  }

  /**
   * Retourne la clé API sans la persister en configuration exportable.
   */
  private function resolveApiKey(): string {
    $settings = $this->configFactory->get('agency_ai_translation.settings');

    // 1) Source de vérité prioritaire : provider OpenAI + module Key.
    $providerConfig = $this->configFactory->get('ai_provider_openai.settings');
    $providerKeyId = $this->firstNonEmptyString([
      $providerConfig->get('key_id'),
      $providerConfig->get('api_key'),
      $providerConfig->get('api_key_name'),
      $providerConfig->get('key'),
    ]);
    $providerKey = $this->resolveFromKeyIdOrRawValue($providerKeyId);
    if ($providerKey !== '') {
      return $providerKey;
    }

    // 2) Key ID configurable côté module.
    $configuredKeyId = (string) $settings->get('openai_key_id');
    $configuredKey = $this->resolveFromKeyIdOrRawValue($configuredKeyId);
    if ($configuredKey !== '') {
      return $configuredKey;
    }

    // 3) Fallbacks legacy.
    $settingsKey = Settings::get('agency_ai_translation.api_key', '');
    if (is_string($settingsKey) && $settingsKey !== '') {
      return $settingsKey;
    }

    $envKey = getenv('AGENCY_AI_TRANSLATION_API_KEY');
    if (is_string($envKey) && $envKey !== '') {
      return $envKey;
    }

    $stateKey = $this->state->get('agency_ai_translation.api_key', '');
    return is_string($stateKey) ? $stateKey : '';
  }

  /**
   * Résout une valeur brute ou un identifiant Key Drupal.
   */
  private function resolveFromKeyIdOrRawValue(?string $candidate): string {
    $candidate = trim((string) $candidate);
    if ($candidate === '') {
      return '';
    }

    if ($this->keyRepository) {
      $key = $this->keyRepository->getKey($candidate);
      if ($key) {
        $resolvedValue = trim((string) $key->getKeyValue());
        if ($resolvedValue !== '') {
          return $resolvedValue;
        }
      }
    }

    // Si ce n'est pas un key_id résolvable, on traite la valeur comme brute.
    return str_starts_with($candidate, 'sk-') ? $candidate : '';
  }

  /**
   * Retourne la première chaîne non vide.
   */
  private function firstNonEmptyString(array $values): ?string {
    foreach ($values as $value) {
      if (is_string($value) && trim($value) !== '') {
        return trim($value);
      }
    }
    return NULL;
  }

  /**
   * Retourne le libellé Drupal d'une langue à partir de son code.
   */
  private function getLanguageLabel(string $langcode): string {
    $language = $this->languageManager->getLanguage($langcode);
    return $language ? $language->getName() : strtoupper($langcode);
  }

}

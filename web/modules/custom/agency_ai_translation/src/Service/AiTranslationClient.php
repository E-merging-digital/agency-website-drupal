<?php

declare(strict_types=1);

namespace Drupal\agency_ai_translation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Client HTTP pour traductions IA FR -> EN.
 */
final class AiTranslationClient {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
    private readonly StateInterface $state,
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
      throw new \RuntimeException('Clé API absente. Configurez-la dans settings.php ou en variable d’environnement.');
    }

    $endpoint = (string) $config->get('endpoint');
    $model = (string) $config->get('model');
    $systemPrompt = (string) $config->get('system_prompt');

    $payload = [
      'model' => $model,
      'temperature' => 0.2,
      'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        [
          'role' => 'user',
          'content' => "Traduire de {$sourceLangcode} vers {$targetLangcode} sans ajouter de commentaire.\n\nTexte source :\n{$text}",
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

}
